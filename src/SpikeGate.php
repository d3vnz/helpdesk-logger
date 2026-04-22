<?php

namespace D3vnz\HelpdeskLogger;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Client-side spike guard.
 *
 * For each fingerprint we keep a rolling window counter in the cache.
 * The FIRST event of a window returns "report=true, burst_count=1" and
 * sets a "seen" marker. Subsequent events within the window bump the
 * counter and return "report=false". When the window expires, the next
 * event re-opens a new window AND flushes the previous one's total as
 * the `burst_count` on the next actual report.
 *
 * Net effect:
 *   - 1st error      → immediate dispatch, burst_count=1
 *   - 99 more errors in the next 60s → silently counted, no dispatch
 *   - 61st second, new error → dispatch with burst_count=100
 *
 * The cache store is pluggable — defaults to Laravel's default store,
 * which is usually Redis on prod and file/database in dev. Either works
 * because we only need atomic increments + TTLs, both of which Cache
 * supports out of the box.
 */
class SpikeGate
{
    public function __construct(
        protected CacheRepository $cache,
        protected int $windowSeconds = 60,
    ) {}

    /**
     * Decide whether to dispatch an event for this fingerprint.
     *
     * @return array{report: bool, burst_count: int, burst_first_at: ?string}
     *   - report=true: caller should dispatch the HTTP job.
     *     burst_count = total events in the PREVIOUS window (always ≥1).
     *     burst_first_at = ISO 8601 timestamp of the first event in
     *                      the previous window (for the `burst_first_at`
     *                      field on the payload).
     *   - report=false: caller should drop; event was coalesced into
     *     the current window's counter.
     */
    public function shouldDispatch(string $fingerprint): array
    {
        $lockKey = $this->key($fingerprint, 'open');   // "window is open" flag
        $countKey = $this->key($fingerprint, 'count'); // events in current window
        $firstKey = $this->key($fingerprint, 'first'); // first event timestamp

        // If the window's "open" flag is missing, this is the FIRST event of a
        // new window. Report it immediately; flush the previous window's
        // counter (if any) as burst_count + first-at for the payload.
        if (! $this->cache->get($lockKey)) {
            $previousCount = (int) ($this->cache->pull($countKey) ?? 0);
            $previousFirst = $this->cache->pull($firstKey);

            // Seed the new window.
            $this->cache->put($lockKey, true, $this->windowSeconds);
            $this->cache->put($firstKey, now()->toIso8601String(), $this->windowSeconds + 10);
            $this->cache->put($countKey, 1, $this->windowSeconds + 10);

            // Previous window contributes its count to this dispatch (the
            // spikes we silently absorbed while that window was open).
            return [
                'report' => true,
                'burst_count' => max(1, $previousCount + 1),
                'burst_first_at' => $previousFirst,
            ];
        }

        // Window is open — increment counter, don't dispatch.
        $this->cache->increment($countKey, 1);
        return [
            'report' => false,
            'burst_count' => 0,
            'burst_first_at' => null,
        ];
    }

    protected function key(string $fingerprint, string $slot): string
    {
        return "hdlog:{$slot}:{$fingerprint}";
    }
}
