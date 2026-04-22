<?php

namespace D3vnz\HelpdeskLogger;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Final HTTP leg — POSTs a payload to TicketMate's errors/report endpoint.
 * Called from the queued SendErrorReport job.
 *
 * Never throws into the consumer app — the worst we do on a failed POST
 * is log a warning so the operator sees the issue in the app's own log.
 *
 * ## Failover
 *
 * A cache-backed circuit breaker shields the consumer app when
 * TicketMate is down. Behaviour:
 *
 *   - CLOSED (default): every send() attempts the HTTP POST. HTTP
 *     failures (non-2xx, timeout, connection refused) increment a
 *     rolling failure counter in the cache.
 *   - OPEN: after `failures_to_open` failures within the current
 *     failure-window, the circuit opens for `open_duration_seconds`.
 *     While open, send() returns immediately without touching the
 *     network — saves the worker timing out on every exception while
 *     TM is unreachable. Occurrences captured during this window are
 *     still coalesced by the SpikeGate, so when the circuit closes and
 *     the next event fires, the `burst_count` on that payload reflects
 *     what we silently absorbed.
 *   - Recovery: the first send() after open_duration_seconds elapses
 *     acts as a canary. Success → counter reset + circuit stays closed.
 *     Failure → circuit reopens for another open_duration_seconds.
 *
 * This ALSO indirectly guards against the only realistic
 * "double-error" scenario: if the SendErrorReport job itself were to
 * throw (e.g., Redis dead), Laravel's exception handler would run
 * OUR reportable closure again. Combined with the SpikeGate's
 * fingerprint coalescing, this never produces more than 1 outbound
 * call per fingerprint per window — but the circuit breaker makes
 * the answer "zero" once it's clear TM is down.
 */
class Reporter
{
    protected const CACHE_KEY_PREFIX = 'helpdesk-logger:circuit';

    /** @param  array<string,mixed>  $config */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
        protected ?CacheRepository $cache = null,
    ) {}

    /** Last Response from a send() call — useful for the helpdesk:test command. */
    protected ?Response $lastResponse = null;

    /** @param  array<string,mixed>  $payload */
    public function send(array $payload): bool
    {
        $this->lastResponse = null;

        $endpoint = rtrim((string) ($this->config['endpoint'] ?? ''), '/');
        $token = (string) ($this->config['token'] ?? '');
        if ($endpoint === '' || $token === '') {
            Log::debug('helpdesk-logger: endpoint or token empty — skipping report');
            return false;
        }

        if ($this->circuitOpen()) {
            Log::debug('helpdesk-logger: circuit open — skipping report (TicketMate recently unreachable)');
            return false;
        }

        try {
            $response = $this->http
                ->withToken($token)
                ->acceptJson()
                ->timeout((float) ($this->config['timeout'] ?? 4.0))
                ->retry(2, 250, throw: false)
                ->post("{$endpoint}/api/v1/errors/report", $payload);

            $this->lastResponse = $response;

            if (! $response->successful()) {
                Log::warning('helpdesk-logger: non-2xx from TicketMate', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                $this->recordFailure();
                return false;
            }

            $this->recordSuccess();
            return true;
        } catch (\Throwable $e) {
            Log::warning('helpdesk-logger: post failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);
            $this->recordFailure();
            return false;
        }
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /**
     * Public for the helpdesk:test command — clears any residual
     * open-circuit state so an ad-hoc test reflects the current
     * reachability of TicketMate, not an old outage.
     */
    public function resetCircuit(): void
    {
        if (! $this->cache) return;
        $this->cache->forget($this->key('open_until'));
        $this->cache->forget($this->key('failures'));
    }

    /**
     * Exposed so the test command can surface circuit state in its
     * output without leaking cache internals.
     *
     * @return array{open: bool, open_until: ?string, failures: int}
     */
    public function circuitStatus(): array
    {
        if (! $this->cache) {
            return ['open' => false, 'open_until' => null, 'failures' => 0];
        }
        $openUntil = $this->cache->get($this->key('open_until'));
        $failures = (int) ($this->cache->get($this->key('failures')) ?? 0);
        return [
            'open' => $openUntil !== null,
            'open_until' => $openUntil,
            'failures' => $failures,
        ];
    }

    /* ================================================================== *
     * Circuit breaker internals
     * ================================================================== */

    protected function circuitOpen(): bool
    {
        if (! $this->cache) return false;
        return (bool) $this->cache->get($this->key('open_until'));
    }

    protected function recordFailure(): void
    {
        if (! $this->cache) return;

        $window = (int) $this->circuitConfig('failure_window_seconds', 60);
        $threshold = (int) $this->circuitConfig('failures_to_open', 3);
        $openFor = (int) $this->circuitConfig('open_duration_seconds', 300);

        // Increment the rolling failure counter. `add()` seeds to 1 with
        // the TTL on first hit; subsequent hits bump via increment().
        $key = $this->key('failures');
        if ($this->cache->add($key, 1, $window)) {
            $count = 1;
        } else {
            $count = (int) $this->cache->increment($key, 1);
        }

        if ($count >= $threshold) {
            $until = now()->addSeconds($openFor)->toIso8601String();
            $this->cache->put($this->key('open_until'), $until, $openFor);
            $this->cache->forget($key);
            Log::warning('helpdesk-logger: circuit opened — suspending sends', [
                'until' => $until,
                'consecutive_failures' => $count,
            ]);
        }
    }

    protected function recordSuccess(): void
    {
        if (! $this->cache) return;

        // Close the circuit if it was open — a successful send means
        // TicketMate is back. Belt-and-braces: also clear the failure
        // counter so a single past blip doesn't push us halfway to the
        // threshold.
        if ($this->cache->get($this->key('open_until'))) {
            Log::info('helpdesk-logger: circuit closed — TicketMate reachable again');
            $this->cache->forget($this->key('open_until'));
        }
        $this->cache->forget($this->key('failures'));
    }

    protected function circuitConfig(string $key, int $default): int
    {
        $circuit = (array) ($this->config['circuit_breaker'] ?? []);
        return (int) ($circuit[$key] ?? $default);
    }

    protected function key(string $slot): string
    {
        return self::CACHE_KEY_PREFIX . ':' . $slot;
    }
}
