<?php

namespace D3vnz\HelpdeskLogger;

use D3vnz\HelpdeskLogger\Jobs\SendErrorReport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Main entry point.
 *
 * Wire-up:
 *   - Laravel 11+  →  withExceptions(fn ($e) => Helpdesk::captureExceptions($e))
 *   - Laravel 10   →  $exceptions->reportable(fn (\Throwable $e) => Helpdesk::report($e))
 *
 * One method actually does the work — `report(Throwable $e)`:
 *   1. Ignore-list check (quiet skip)
 *   2. Build payload via ContextBuilder
 *   3. Spike-gate by fingerprint (coalesce bursts in a 60s window)
 *   4. Dispatch a queued SendErrorReport job
 *
 * Everything is wrapped in try/catch — this class must NEVER throw
 * back into the consumer app's error handler, or we'd create an
 * infinite loop.
 */
class HelpdeskLogger
{
    public function __construct(
        protected Container $container,
        protected ContextBuilder $contextBuilder,
        protected SpikeGate $spikeGate,
    ) {}

    public function isEnabled(): bool
    {
        $cfg = config('helpdesk-logger');
        if (! ($cfg['enabled'] ?? false)) return false;
        if (empty($cfg['endpoint']) || empty($cfg['token'])) return false;
        return true;
    }

    public function report(Throwable $e): void
    {
        try {
            if (! $this->isEnabled()) return;
            if ($this->isIgnored($e)) return;

            $request = $this->currentRequest();
            $payload = $this->contextBuilder->build($e, $request);

            $fingerprint = (string) ($payload['fingerprint'] ?? '');
            if ($fingerprint === '') return;

            // Sampling (1.0 = always report). Applied BEFORE the spike
            // gate so "lucky" noisy-error drops still coalesce in-window.
            $sampleRate = (float) config('helpdesk-logger.sample_rate', 1.0);
            if ($sampleRate < 1.0 && mt_rand(0, 1_000_000) / 1_000_000 > $sampleRate) {
                return;
            }

            $decision = $this->spikeGate->shouldDispatch($fingerprint);
            if (! $decision['report']) {
                // Coalesced — will be flushed as burst_count when the
                // next window opens. Nothing to do now.
                return;
            }

            $payload['burst_count'] = $decision['burst_count'];
            $payload['burst_first_at'] = $decision['burst_first_at'];

            $this->dispatch($payload);
        } catch (Throwable $inner) {
            // Swallow — logging our own failure is the worst we can do.
            // Can't call $this->report() on ourselves without infinite loop.
            Log::warning('helpdesk-logger: report() threw internally', [
                'error' => $inner->getMessage(),
                'original' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience wiring for Laravel 11's bootstrap/app.php style:
     *   ->withExceptions(function (Exceptions $exceptions) {
     *       \Helpdesk::captureExceptions($exceptions);
     *   })
     */
    public function captureExceptions(object $exceptions): void
    {
        if (! method_exists($exceptions, 'report')) return;

        $exceptions->report(function (Throwable $e) {
            $this->report($e);
        });
    }

    protected function isIgnored(Throwable $e): bool
    {
        $ignored = (array) config('helpdesk-logger.ignore_exceptions', []);
        $class = $e::class;
        foreach ($ignored as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === '') continue;
            if ($class === $pattern) return true;
            if (str_starts_with($class, rtrim($pattern, '\\') . '\\')) return true;
            if ($e instanceof $pattern) return true;
        }
        return false;
    }

    /** @param  array<string,mixed>  $payload */
    protected function dispatch(array $payload): void
    {
        $job = new SendErrorReport($payload);

        $connection = config('helpdesk-logger.queue.connection');
        $queue = config('helpdesk-logger.queue.queue', 'default');

        // `sync` connection — useful for `php artisan tinker` testing —
        // runs the job inline. Otherwise pushes to the worker.
        if ($connection === 'sync' || $queue === 'sync') {
            dispatch_sync($job);
            return;
        }

        $pending = dispatch($job)->onQueue((string) $queue);
        if ($connection) {
            $pending->onConnection((string) $connection);
        }
    }

    protected function currentRequest(): ?\Illuminate\Http\Request
    {
        try {
            if ($this->container->bound('request')) {
                $req = $this->container->make('request');
                return $req instanceof \Illuminate\Http\Request ? $req : null;
            }
        } catch (\Throwable) {
            // container not ready (e.g. console context) — fine
        }
        return null;
    }
}
