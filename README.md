# d3vnz/helpdesk-logger

Laravel exception reporter that routes errors to a [TicketMate](https://github.com/d3vnz) helpdesk instance — fingerprinted, rate-limited, context-rich. Replaces Sentry for teams that already run TicketMate.

## What it does

When your Laravel app throws an unhandled exception, this package:

1. **Fingerprints** it (`sha1(class + top-app-frame)`) so the same bug doesn't open 500 tickets.
2. **Coalesces bursts** — 1000 errors in 60s = 1 HTTP call to TicketMate with `burst_count=1000`.
3. **Builds rich context** — full stack trace (app + vendor flagged separately), request URL + method, logged-in user, environment, release SHA, PHP/Laravel version.
4. **Sanitizes** — passwords, tokens, secrets, API keys, CSRF, cookies stripped before upload.
5. **Queues the POST** — reporting runs async on your worker; the user's error page is never slowed down.
6. **Respects silence windows** — if the app's domain is put in maintenance mode on the TicketMate side, events still get recorded but no tickets are cut.

TicketMate then fingerprint-dedups, creates a ticket (with a matching GitHub issue via the existing issuetracker flow), runs AI triage for a clean subject + probable-cause note, and auto-assigns the domain.

## Requirements

- PHP **8.2+**
- Laravel **10**, **11**, **12**, or **13**
- A TicketMate instance with a configured `Repository` row for this app

## Installation

```bash
composer require d3vnz/helpdesk-logger
```

Add the endpoint + token to `.env`:

```env
HELPDESK_LOGGER_ENDPOINT=https://helpdesk.d3v.nz
HELPDESK_LOGGER_TOKEN=<the Repository api_token from TicketMate admin>
HELPDESK_LOGGER_ENVIRONMENT="${APP_ENV}"
HELPDESK_LOGGER_RELEASE=  # optional — populate with git SHA on deploy
```

The same token the `d3vnz/issuetracker` package uses — one token, two uses.

### Wire the exception handler

**Laravel 11+** (`bootstrap/app.php`):

```php
use D3vnz\HelpdeskLogger\Facades\Helpdesk;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        Helpdesk::captureExceptions($exceptions);
    })
    ->create();
```

**Laravel 10** (`app/Exceptions/Handler.php`):

```php
use D3vnz\HelpdeskLogger\Facades\Helpdesk;

public function register(): void
{
    $this->reportable(function (\Throwable $e) {
        Helpdesk::report($e);
    });
}
```

That's it. Throw anything and it'll show up in TicketMate within a few seconds.

## Configuration

Publish the config to tweak capture / sanitization / sampling:

```bash
php artisan vendor:publish --tag=helpdesk-logger-config
```

Key knobs (see `config/helpdesk-logger.php` for the full list):

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | Master kill-switch. |
| `burst_window_seconds` | `60` | Coalesce same-fingerprint events in this window. |
| `sample_rate` | `1.0` | Drop-rate for noisy apps (1.0 = report every unique error). |
| `ignore_exceptions` | `[404, 401, CSRF, validation, auth]` | Exceptions silently dropped. |
| `sanitize_keys` | `[password, token, secret, api_key, ...]` | Request-body keys redacted. |
| `capture.request_body` | `true` | Include sanitized request body in reports. |
| `capture.session` | `false` | Session data (off by default — sensitive). |
| `capture.cookies` | `false` | Cookie names (values never captured). |
| `capture.server_env` | `false` | $_SERVER vars (off — CI secrets leak risk). |
| `stack.max_frames` | `40` | Ship the top N frames. |
| `max_context_bytes` | `65536` | Hard cap on context JSON size. |

## Spike protection, in detail

- **Per-fingerprint burst window**: first event fires immediately; subsequent events in the same 60s window silently bump a counter. When the window expires, the next event fires with `burst_count = <silent events + 1>`.
- **Server-side dedup**: TicketMate fingerprint-matches by `(repository, fingerprint)`. Second burst with the same fingerprint appends an occurrence to the existing ticket; reopens it if closed; bumps the count; drops a milestone note at 10 / 50 / 100 / 500 / 1000 / 5000 / 10000.
- **Per-hour ticket cap**: TicketMate rejects NEW-fingerprint tickets past a configurable per-hour limit (default 25). Orphan occurrences still get stored so you see post-incident what you missed.
- **Silence mode**: staff can mute a domain from the TicketMate Domain edit page (15m / 1h / 4h / 24h / forever). During silence, occurrences are stored but no tickets are created or reopened.

## What's NOT captured

Intentional omissions:

- No raw request body is shipped beyond 8KB — larger bodies replaced with a truncation stub.
- No `Authorization` / `Cookie` header values.
- No session VALUES (keys only, and only with `capture.session=true`).
- No `$_SERVER` unless `capture.server_env=true` (CI secrets live there).
- User objects → `id + email + name` only, never model internals.

## Testing from tinker

```php
php artisan tinker
> \Helpdesk::report(new \RuntimeException('test error from tinker'))
```

With `HELPDESK_LOGGER_QUEUE_CONNECTION=sync` in `.env` you'll see the HTTP call happen inline. Check TicketMate's `/admin/tickets` for the new ticket.

## License

MIT — see [LICENSE](LICENSE).
