<?php

namespace D3vnz\HelpdeskLogger;

/**
 * Auto-discovers the currently-running git SHA so users don't have to
 * plumb HELPDESK_LOGGER_RELEASE through every deploy script.
 *
 * Resolution order (highest priority first):
 *
 *   1. Explicit override: `HELPDESK_LOGGER_RELEASE` env var
 *   2. Platform-provided env vars (Heroku, Vercel, Railway, Render,
 *      GitHub Actions artifacts, generic CI)
 *   3. Direct read of `.git/HEAD` + its referenced file (or packed-refs)
 *      — works on Laravel Forge since Forge uses `git pull` on deploy,
 *      leaving `.git/` intact on the server
 *   4. null — silently omitted from the payload
 *
 * Reads the `.git` dir directly rather than shelling out to `git`:
 *   - No dependency on `exec()` / `proc_open()` being enabled
 *   - Unaffected by open_basedir / disable_functions hardening
 *   - ~0.1ms — faster than spawning a subprocess
 *
 * Memoized for the process lifetime so every exception in a long-running
 * worker doesn't re-read the files.
 */
class ReleaseDetector
{
    private static ?string $cached = null;
    private static bool $detected = false;

    /** For testing — reset memoization. */
    public static function reset(): void
    {
        self::$cached = null;
        self::$detected = false;
    }

    /**
     * Returns the full 40-char SHA, or null if nothing could be detected.
     */
    public static function detect(?string $projectRoot = null): ?string
    {
        if (self::$detected) {
            return self::$cached;
        }
        self::$detected = true;
        self::$cached = self::compute($projectRoot);
        return self::$cached;
    }

    /**
     * Describes where the SHA came from — "env:VAR_NAME", "git-head",
     * "packed-refs", or null. Used by `helpdesk:test` to show operators
     * what's being picked up.
     */
    public static function source(?string $projectRoot = null): ?string
    {
        return self::computeSource($projectRoot);
    }

    protected static function compute(?string $projectRoot): ?string
    {
        // Platform / override envs — getenv() hits the real process
        // environment (doesn't go through Laravel's $_ENV cache), so
        // a server-wide Forge env var still wins at runtime.
        foreach (self::envCandidates() as $var) {
            $val = getenv($var);
            if (is_string($val) && $val !== '') {
                return self::normalize($val);
            }
        }

        $root = $projectRoot ?: self::guessRoot();
        if (! $root) return null;

        $gitDir = $root . DIRECTORY_SEPARATOR . '.git';
        return self::readGitHead($gitDir);
    }

    protected static function computeSource(?string $projectRoot): ?string
    {
        foreach (self::envCandidates() as $var) {
            $val = getenv($var);
            if (is_string($val) && $val !== '') {
                return "env:{$var}";
            }
        }

        $root = $projectRoot ?: self::guessRoot();
        if (! $root) return null;

        $gitDir = $root . DIRECTORY_SEPARATOR . '.git';
        if (! is_dir($gitDir)) return null;

        $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
        if (! is_readable($headFile)) return null;

        $head = trim((string) @file_get_contents($headFile));
        if ($head === '') return null;

        if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
            return 'git-head-detached';
        }

        if (preg_match('/^ref:\s*(.+)$/', $head, $m)) {
            $ref = trim($m[1]);
            $refFile = $gitDir . DIRECTORY_SEPARATOR . $ref;
            if (is_readable($refFile)) {
                return 'git-head';
            }
            return 'git-packed-refs';
        }

        return null;
    }

    /** @return list<string> */
    protected static function envCandidates(): array
    {
        return [
            // User override — always wins.
            'HELPDESK_LOGGER_RELEASE',

            // Platforms that inject the deployed commit at runtime.
            'SOURCE_VERSION',          // Heroku
            'HEROKU_SLUG_COMMIT',      // Heroku (older)
            'VERCEL_GIT_COMMIT_SHA',   // Vercel
            'RAILWAY_GIT_COMMIT_SHA',  // Railway
            'RENDER_GIT_COMMIT',       // Render
            'GITHUB_SHA',              // GitHub Actions / Actions-built images
            'CI_COMMIT_SHA',           // GitLab CI
            'BITBUCKET_COMMIT',        // Bitbucket Pipelines

            // Generic fallbacks.
            'COMMIT_SHA',
            'GIT_COMMIT',
        ];
    }

    protected static function readGitHead(string $gitDir): ?string
    {
        if (! is_dir($gitDir)) return null;

        $headFile = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';
        if (! is_readable($headFile)) return null;

        $head = trim((string) @file_get_contents($headFile));
        if ($head === '') return null;

        // Detached HEAD: contains the SHA directly.
        if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
            return self::normalize($head);
        }

        // Symbolic ref: "ref: refs/heads/main"
        if (preg_match('/^ref:\s*(.+)$/', $head, $m)) {
            $ref = trim($m[1]);

            // Loose ref (most common after git pull/checkout).
            $refFile = $gitDir . DIRECTORY_SEPARATOR . $ref;
            if (is_readable($refFile)) {
                $sha = trim((string) @file_get_contents($refFile));
                if (preg_match('/^[0-9a-f]{40}$/i', $sha)) {
                    return self::normalize($sha);
                }
            }

            // Packed ref (after `git gc` or `git pack-refs`).
            $packedFile = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';
            if (is_readable($packedFile)) {
                $content = (string) @file_get_contents($packedFile);
                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === '^') continue;
                    if (preg_match('/^([0-9a-f]{40})\s+(\S+)$/i', $line, $mm)
                        && $mm[2] === $ref) {
                        return self::normalize($mm[1]);
                    }
                }
            }
        }

        return null;
    }

    protected static function guessRoot(): ?string
    {
        // Prefer Laravel's base_path() when the framework is booted —
        // survives nested vendor/ structures and unusual project layouts.
        if (function_exists('base_path')) {
            try {
                $path = base_path();
                if (is_string($path) && $path !== '') return $path;
            } catch (\Throwable) {
                // no app() bound yet — fall through to path-walk
            }
        }

        // Fallback: walk up from this file until we find a composer.json
        // or a .git directory (stops at the filesystem root).
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . '.git')
                || is_file($dir . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return null;
    }

    protected static function normalize(string $sha): string
    {
        return strtolower(substr(trim($sha), 0, 40));
    }
}
