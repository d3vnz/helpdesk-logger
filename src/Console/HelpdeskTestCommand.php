<?php

namespace D3vnz\HelpdeskLogger\Console;

use D3vnz\HelpdeskLogger\ContextBuilder;
use D3vnz\HelpdeskLogger\ReleaseDetector;
use D3vnz\HelpdeskLogger\Reporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-shot smoke test: builds a realistic exception payload (same
 * pipeline as the real reporter uses) and POSTs it synchronously
 * to TicketMate. Prints the full result so you can verify the
 * round-trip end-to-end from `artisan helpdesk:test`.
 *
 * Deliberately bypasses:
 *   - the queue (runs the HTTP call inline, not via the worker)
 *   - the SpikeGate (so repeated tests always hit the network)
 *   - the circuit breaker (reset first so past outages don't mask
 *     the current test)
 *
 * Leaves everything intact for the next real exception.
 */
class HelpdeskTestCommand extends Command
{
    protected $signature = 'helpdesk:test
        {--message= : Custom exception message (default auto-generated with timestamp)}
        {--dry-run : Build + print the payload without sending}
        {--fingerprint= : Override the computed fingerprint (useful for testing dedup/reopen flows against an existing ticket)}';

    protected $description = 'Fire a fake exception through d3vnz/helpdesk-logger to verify end-to-end wiring with TicketMate.';

    public function handle(ContextBuilder $builder, Reporter $reporter): int
    {
        $endpoint = (string) config('helpdesk-logger.endpoint');
        $token = (string) config('helpdesk-logger.token');
        if ($endpoint === '' || $token === '') {
            $this->error('HELPDESK_LOGGER_ENDPOINT and HELPDESK_LOGGER_TOKEN must both be set in .env.');
            $this->line('  endpoint = '.($endpoint ?: '(empty)'));
            $this->line('  token    = '.($token ? '(set, '.strlen($token).' chars)' : '(empty)'));
            return self::FAILURE;
        }

        // The whole point of this command is to probe reachability
        // ON DEMAND — so we don't gate it on isEnabled(). If reporting
        // is currently off for this APP_ENV (e.g. local dev), we WARN
        // so the operator knows real exceptions are silent, but the
        // test still fires.
        $logger = app(\D3vnz\HelpdeskLogger\HelpdeskLogger::class);
        if (! $logger->isEnabled()) {
            $this->warn('⚠ Reporting is DISABLED for APP_ENV=' . config('app.env') . '.');
            $this->warn('  This test will send anyway to verify the pipeline,');
            $this->warn('  but real exceptions in this environment are silent.');
            $this->warn('  Override with HELPDESK_LOGGER_ENABLED=true or add');
            $this->warn('  "'.config('app.env').'" to HELPDESK_LOGGER_ENVIRONMENTS.');
            $this->newLine();
        }

        // Throw + catch so PHP populates a real stack trace — using
        // `new RuntimeException(...)` bare works, but catching a thrown
        // one is closer to what the production path actually captures.
        $message = (string) ($this->option('message')
            ?: 'Test exception from artisan helpdesk:test @ '.now()->toDateTimeString());

        try {
            throw new RuntimeException($message);
        } catch (\Throwable $e) {
            $payload = $builder->build($e);
        }

        if ($override = (string) $this->option('fingerprint')) {
            $payload['fingerprint'] = $override;
        }

        $this->line('');
        $this->info('helpdesk-logger: test payload');
        $this->line('  endpoint     = '.$endpoint);
        $this->line('  environment  = '.(string) config('helpdesk-logger.environment'));
        $this->line('  fingerprint  = '.$payload['fingerprint']);
        $this->line('  exception    = '.$payload['exception_class'].': '.$payload['exception_message']);
        $this->line('  frames       = '.count($payload['stack_trace'] ?? []).' captured');
        $this->line('  user         = '.($payload['user_email'] ?? '(not authenticated)'));
        $this->line('  release      = '.($payload['release_sha'] ?? '(not detected)')
            .(($payload['release_sha'] ?? null) ? '  [source: '.(ReleaseDetector::source() ?? 'config').']' : ''));

        $circuit = $reporter->circuitStatus();
        $this->line('  circuit      = '.($circuit['open'] ? 'OPEN until '.$circuit['open_until'] : 'closed').' (failures='.$circuit['failures'].')');

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('--dry-run: payload would have been POSTed to /api/v1/errors/report:');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        // Reset circuit so an old outage doesn't silently skip the test.
        if ($circuit['open']) {
            $this->warn('Circuit was open — resetting before test so it runs.');
            $reporter->resetCircuit();
        }

        $this->newLine();
        $this->line('Sending…');
        $ok = $reporter->send($payload);
        $response = $reporter->lastResponse();

        $this->newLine();
        if ($ok && $response) {
            $this->info('✓ Accepted by TicketMate');
            $data = (array) $response->json();
            $this->line('  status        = '.($data['status'] ?? 'unknown'));
            $this->line('  ticket_id     = '.($data['ticket_id'] ?? 'null'));
            $this->line('  occurrence_id = '.($data['occurrence_id'] ?? 'null'));
            $this->line('  silenced      = '.(!empty($data['silenced']) ? 'yes' : 'no'));
            if (! empty($data['ticket_id'])) {
                $base = rtrim($endpoint, '/');
                $this->line('  ticket URL    = '.$base.'/admin/tickets/'.$data['ticket_id']);
            }
            return self::SUCCESS;
        }

        $this->error('✗ Send failed');
        if ($response) {
            $this->line('  HTTP '.$response->status());
            $this->line('  body: '.mb_substr($response->body(), 0, 400));
        } else {
            $this->line('  No response received — check laravel.log for lines matching "helpdesk-logger".');
        }
        return self::FAILURE;
    }
}
