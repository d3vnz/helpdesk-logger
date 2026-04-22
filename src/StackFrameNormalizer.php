<?php

namespace D3vnz\HelpdeskLogger;

use Throwable;

/**
 * Normalises a PHP throwable's trace into the shape TicketMate expects.
 *
 * Every frame gets: {index, file, line, function, class, type, is_vendor}.
 *
 * "is_vendor" is derived by the presence of '/vendor/' OR falling outside
 * the configured app paths. Lets TicketMate collapse vendor frames in
 * the UI and run fingerprint matching on the top APP frame only.
 */
class StackFrameNormalizer
{
    /** @param  list<string>  $appPaths */
    public function __construct(
        protected array $appPaths,
        protected int $maxFrames = 40,
    ) {}

    /** @return list<array<string,mixed>> */
    public function normalize(Throwable $e): array
    {
        $trace = $e->getTrace();
        array_unshift($trace, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'function' => '__throw',
            'class' => $e::class,
            'type' => '::',
        ]);

        $out = [];
        $count = 0;
        foreach ($trace as $frame) {
            if ($count >= $this->maxFrames) break;
            $file = (string) ($frame['file'] ?? '');
            $out[] = [
                'index' => $count,
                'file' => $this->stripBasePath($file),
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'function' => (string) ($frame['function'] ?? ''),
                'class' => (string) ($frame['class'] ?? ''),
                'type' => (string) ($frame['type'] ?? ''),
                'is_vendor' => $this->isVendor($file),
            ];
            $count++;
        }
        return $out;
    }

    protected function isVendor(string $file): bool
    {
        if ($file === '') return true;
        if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            || str_contains($file, '/vendor/')) {
            return true;
        }
        foreach ($this->appPaths as $path) {
            if ($path === '') continue;
            if (str_starts_with($file, $path)) return false;
        }
        // Not in any app path AND not in vendor: treat as vendor to be safe.
        return true;
    }

    /**
     * Trim the project root from paths so error reports are portable
     * across machines (e.g. "/home/forge/app/app/Http/..." → "app/Http/...").
     */
    protected function stripBasePath(string $file): string
    {
        if ($file === '') return $file;
        foreach ($this->appPaths as $path) {
            if ($path === '') continue;
            // Peel back one level so app/ rather than vendor/ prefixes survive.
            $root = dirname($path);
            if ($root !== '' && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                return ltrim(substr($file, strlen($root)), DIRECTORY_SEPARATOR);
            }
            if ($root !== '' && str_starts_with($file, $root . '/')) {
                return ltrim(substr($file, strlen($root)), '/');
            }
        }
        return $file;
    }
}
