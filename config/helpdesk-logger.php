<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Master switch. Set false to disable ALL error reporting without removing
    | the package. Kills the bootstrap::captureExceptions() wiring in a panic.
    */
    'enabled' => (bool) env('HELPDESK_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Endpoint + auth
    |--------------------------------------------------------------------------
    | Base URL of the TicketMate instance + the per-repository api_token from
    | /admin/repositories/{id}/edit. SAME token the d3vnz/issuetracker package
    | uses — one token, two uses.
    */
    'endpoint' => env('HELPDESK_LOGGER_ENDPOINT', env('TICKETMATE_API_URL')),
    'token' => env('HELPDESK_LOGGER_TOKEN', env('TICKETMATE_API_TOKEN')),
    'timeout' => (float) env('HELPDESK_LOGGER_TIMEOUT', 4.0),

    /*
    |--------------------------------------------------------------------------
    | Environment metadata
    |--------------------------------------------------------------------------
    | Stamped on every occurrence — lets you see at a glance whether an error
    | started after a specific deploy / upgrade.
    */
    'environment' => env('HELPDESK_LOGGER_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('HELPDESK_LOGGER_RELEASE'), // e.g. git SHA, set on deploy
    'app_version' => env('HELPDESK_LOGGER_APP_VERSION'),
    'server_name' => env('HELPDESK_LOGGER_SERVER_NAME', gethostname() ?: null),

    /*
    |--------------------------------------------------------------------------
    | Queueing
    |--------------------------------------------------------------------------
    | Reporting is always queued so it doesn't block the failing request's
    | error page from rendering. Set connection='sync' in local dev to
    | debug the HTTP call without a worker running.
    */
    'queue' => [
        'connection' => env('HELPDESK_LOGGER_QUEUE_CONNECTION'), // null = default
        'queue' => env('HELPDESK_LOGGER_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker (failover when TicketMate is down)
    |--------------------------------------------------------------------------
    | If the TicketMate endpoint becomes unreachable — network partition,
    | TM deploy, DB crash — we SILENTLY STOP sending after a handful of
    | failures. This prevents each failing exception from timing out the
    | queue worker and spamming the app log. A canary send on recovery
    | reopens the circuit.
    |
    |   failure_window_seconds  Rolling window in which `failures_to_open`
    |                           fails must accrue before opening.
    |   failures_to_open        Consecutive (within-window) failures that
    |                           trip the breaker.
    |   open_duration_seconds   How long the circuit stays open before the
    |                           next send() attempts a canary call.
    */
    'circuit_breaker' => [
        'failure_window_seconds' => (int) env('HELPDESK_LOGGER_CIRCUIT_WINDOW', 60),
        'failures_to_open' => (int) env('HELPDESK_LOGGER_CIRCUIT_THRESHOLD', 3),
        'open_duration_seconds' => (int) env('HELPDESK_LOGGER_CIRCUIT_OPEN_FOR', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    | The store used by the SpikeGate + circuit breaker. Null = Laravel's
    | default (usually Redis on prod, file/database in dev). Override when
    | the default store is volatile/ephemeral and you want circuit state
    | to survive, e.g. CACHE_STORE=array with HELPDESK_LOGGER_CACHE_STORE=redis.
    */
    'cache_store' => env('HELPDESK_LOGGER_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Spike protection
    |--------------------------------------------------------------------------
    | Primary dedup happens server-side in TicketMate by fingerprint. This
    | client-side cache layer stops pathological loops (same exception 1000×
    | per second) from queueing a Job per event. Events within `burst_window`
    | for the same fingerprint are COALESCED into a single dispatch with
    | `burst_count` on the payload.
    */
    'burst_window_seconds' => (int) env('HELPDESK_LOGGER_BURST_WINDOW', 60),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    | 1.0 = report every event (default). Drop below 1 for EXTREMELY noisy
    | apps where the burst-window coalescing isn't enough. Note: sampling is
    | applied PER FINGERPRINT's first-hit-in-window, so rare errors are
    | never dropped — only noisy ones.
    */
    'sample_rate' => (float) env('HELPDESK_LOGGER_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Ignore list
    |--------------------------------------------------------------------------
    | Exception classes matched by exact name or namespace prefix. Matched
    | exceptions are silently dropped before any work is done.
    | HttpException 404s are on this list by default — users hitting dead
    | URLs is not a bug you need a ticket for.
    */
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitization
    |--------------------------------------------------------------------------
    | Keys redacted from request body / headers / session before upload.
    | Matched case-insensitively, substring-match (so "x-api-key" is caught
    | by "api_key"). Values replaced with "[REDACTED]".
    */
    'sanitize_keys' => [
        'password', 'password_confirmation', 'current_password',
        'token', 'api_key', 'apikey', 'secret', 'private_key',
        'authorization', 'cookie', 'auth',
        'credit_card', 'card_number', 'cvv', 'ssn', 'tax_id',
        '_token', 'x-csrf-token', 'x-api-key',
        'access_token', 'refresh_token', 'bearer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context capture
    |--------------------------------------------------------------------------
    | Which signals to include in each report. Turn sections off if you
    | have privacy / compliance concerns. Values default to true.
    */
    'capture' => [
        'request_body' => true,
        'request_headers' => true,
        'session' => false, // off by default — session data is sensitive
        'cookies' => false, // off by default — many sites store tokens in cookies
        'user' => true,     // user id/email/name only — never credentials
        'server_env' => false, // $_SERVER env leaks (CI secrets, etc.)
        'app_env_hint' => true, // APP_ENV / APP_DEBUG — safe, non-secret
    ],

    /*
    |--------------------------------------------------------------------------
    | Stack trace
    |--------------------------------------------------------------------------
    | How many frames to ship. Vendor frames are captured but flagged so TM
    | can fold them in the UI.
    */
    'stack' => [
        'max_frames' => (int) env('HELPDESK_LOGGER_MAX_FRAMES', 40),
        // Paths treated as "app" (anything NOT matching → is_vendor=true).
        // Defaults to base_path('app') + base_path('bootstrap') + base_path('config').
        'app_paths' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Max context bytes
    |--------------------------------------------------------------------------
    | Hard cap on sanitized context JSON size. Anything over is replaced
    | with a summary stub. Prevents a 20MB request body becoming a 20MB
    | error report.
    */
    'max_context_bytes' => (int) env('HELPDESK_LOGGER_MAX_CONTEXT_BYTES', 65536), // 64KB
];
