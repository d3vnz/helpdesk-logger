<?php

namespace D3vnz\HelpdeskLogger\Jobs;

use D3vnz\HelpdeskLogger\Reporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued POST to TicketMate. Keeps the failing request's error page
 * snappy — reporting happens async on the worker.
 *
 * `tries=1` on purpose: retrying a failed error report just multiplies
 * noise and risks a runaway loop if TM itself is the problem. The
 * Reporter does one internal retry on transport failure, which is plenty.
 */
class SendErrorReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 10;

    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload) {}

    public function handle(Reporter $reporter): void
    {
        $reporter->send($this->payload);
    }
}
