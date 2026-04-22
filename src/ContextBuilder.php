<?php

namespace D3vnz\HelpdeskLogger;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Throwable;

/**
 * Assembles the full payload TicketMate's /api/v1/errors/report expects.
 *
 * Pulls from the current request (if any), the auth factory (for
 * user-context), the configured environment flags, and the normalized
 * exception. Sanitizer walks the result to redact secrets.
 */
class ContextBuilder
{
    /** @param  array<string,mixed>  $config */
    public function __construct(
        protected array $config,
        protected Sanitizer $sanitizer,
        protected StackFrameNormalizer $stackNormalizer,
        protected ?AuthFactory $auth = null,
    ) {}

    /** @return array<string,mixed> */
    public function build(Throwable $e, ?Request $request = null): array
    {
        $frames = $this->stackNormalizer->normalize($e);
        $fingerprint = Fingerprint::compute($e, $frames);

        $payload = [
            'fingerprint' => $fingerprint,
            'exception_class' => $e::class,
            'exception_message' => $this->shorten((string) $e->getMessage(), 8000),
            'exception_code' => (string) $e->getCode() ?: null,
            'stack_trace' => $frames,
            'occurred_at' => now()->toIso8601String(),

            'environment' => (string) ($this->config['environment'] ?? 'production'),
            // Auto-detects from .git/HEAD + common platform env vars so
            // Forge deploys don't need to plumb a SHA through env. Manual
            // `HELPDESK_LOGGER_RELEASE` env var always wins.
            'release_sha' => $this->config['release'] ?: ReleaseDetector::detect(),
            'php_version' => PHP_VERSION,
            'framework' => $this->detectFramework(),
            'server_name' => $this->config['server_name'] ?? (gethostname() ?: null),
            'app_version' => $this->config['app_version'] ?? null,
        ];

        if ($request) {
            $payload = array_merge($payload, $this->requestContext($request));
        }

        if ($this->config['capture']['user'] ?? true) {
            $payload = array_merge($payload, $this->userContext());
        }

        $payload['context'] = $this->sanitizer->sanitize($this->extraContext($request));

        $payload['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);

        return array_filter($payload, fn ($v) => $v !== null);
    }

    /** @return array<string,mixed> */
    protected function requestContext(Request $request): array
    {
        return array_filter([
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
            'route_name' => optional($request->route())->getName(),
            'ip' => $request->ip(),
            'user_agent' => $this->shorten((string) $request->userAgent(), 1000),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string,mixed> */
    protected function userContext(): array
    {
        $user = null;
        try { $user = $this->auth?->guard()?->user(); } catch (\Throwable) { /* guard not ready */ }
        if (! $user) return [];

        $id = null;
        if (method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        }

        $email = $this->safeGet($user, 'email');
        $name = $this->safeGet($user, 'name') ?? $this->safeGet($user, 'full_name');

        return array_filter([
            'user_id_ref' => $id !== null ? (string) $id : null,
            'user_email' => $email,
            'user_name' => $name,
        ]);
    }

    /**
     * Everything else that didn't map to a first-class column —
     * sanitized request body, selected headers, session hint, env flags.
     *
     * @return array<string,mixed>
     */
    protected function extraContext(?Request $request): array
    {
        $out = [];

        if ($this->config['capture']['app_env_hint'] ?? true) {
            $out['app'] = [
                'env' => (string) config('app.env', ''),
                'debug' => (bool) config('app.debug', false),
                'timezone' => (string) config('app.timezone', ''),
                'locale' => (string) config('app.locale', ''),
            ];
        }

        if ($request) {
            if ($this->config['capture']['request_body'] ?? true) {
                $out['request_body'] = $this->shortenJson($request->all(), 8000);
            }

            if ($this->config['capture']['request_headers'] ?? true) {
                $out['request_headers'] = $this->selectHeaders($request);
            }

            if ($this->config['capture']['cookies'] ?? false) {
                $out['cookies'] = array_keys($request->cookies->all()); // names only, never values
            }

            if (($this->config['capture']['session'] ?? false) && $request->hasSession()) {
                try {
                    $out['session_keys'] = array_keys($request->session()->all());
                } catch (\Throwable) { /* session may not be started */ }
            }
        }

        if ($this->config['capture']['server_env'] ?? false) {
            // Rarely useful + frequently leaks secrets (CI keys, etc.)
            // — off by default. Opt in explicitly.
            $out['server_env'] = $this->serverEnvSubset();
        }

        return $out;
    }

    /** @return array<string,string> */
    protected function selectHeaders(Request $request): array
    {
        // Whitelist — safer than redaction on a blacklist. Cookie /
        // Authorization intentionally excluded.
        $allow = ['accept', 'accept-language', 'content-type', 'content-length',
                  'origin', 'referer', 'x-forwarded-for', 'x-request-id',
                  'x-real-ip', 'host'];
        $out = [];
        foreach ($allow as $h) {
            $v = $request->headers->get($h);
            if ($v !== null) $out[$h] = $this->shorten((string) $v, 500);
        }
        return $out;
    }

    /** @return array<string,string> */
    protected function serverEnvSubset(): array
    {
        $interesting = ['SERVER_SOFTWARE', 'SERVER_NAME', 'SERVER_PORT', 'HTTPS',
                        'REQUEST_SCHEME', 'SCRIPT_NAME', 'PHP_SELF'];
        $out = [];
        foreach ($interesting as $k) {
            if (isset($_SERVER[$k])) $out[$k] = (string) $_SERVER[$k];
        }
        return $out;
    }

    protected function detectFramework(): string
    {
        if (function_exists('app') && class_exists(\Illuminate\Foundation\Application::class)) {
            try {
                return 'Laravel ' . app()->version();
            } catch (\Throwable) {
                return 'Laravel';
            }
        }
        return 'PHP ' . PHP_VERSION;
    }

    protected function safeGet(object $user, string $prop): ?string
    {
        try {
            $val = $user->{$prop} ?? null;
            return $val !== null ? (string) $val : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function shorten(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
    }

    /** @return array<string,mixed>|string */
    protected function shortenJson(array $array, int $max): array|string
    {
        $encoded = json_encode($array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) return '[unserializable]';
        if (strlen($encoded) <= $max) return $array;
        return [
            '_truncated' => true,
            '_size_bytes' => strlen($encoded),
            'keys' => array_keys($array),
        ];
    }
}
