<?php

namespace D3vnz\HelpdeskLogger;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Final HTTP leg — POSTs a payload to TicketMate's errors/report endpoint.
 * Called from the queued SendErrorReport job.
 *
 * Never throws into the consumer app — the worst we do on a failed POST
 * is log a warning so the operator sees the issue in the app's own log.
 */
class Reporter
{
    /** @param  array<string,mixed>  $config */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
    ) {}

    /** @param  array<string,mixed>  $payload */
    public function send(array $payload): bool
    {
        $endpoint = rtrim((string) ($this->config['endpoint'] ?? ''), '/');
        $token = (string) ($this->config['token'] ?? '');
        if ($endpoint === '' || $token === '') {
            Log::debug('helpdesk-logger: endpoint or token empty — skipping report');
            return false;
        }

        try {
            $response = $this->http
                ->withToken($token)
                ->acceptJson()
                ->timeout((float) ($this->config['timeout'] ?? 4.0))
                ->retry(2, 250, throw: false)
                ->post("{$endpoint}/api/v1/errors/report", $payload);

            if (! $response->successful()) {
                Log::warning('helpdesk-logger: non-2xx from TicketMate', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('helpdesk-logger: post failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);
            return false;
        }
    }
}
