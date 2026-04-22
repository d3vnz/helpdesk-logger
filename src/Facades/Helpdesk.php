<?php

namespace D3vnz\HelpdeskLogger\Facades;

use D3vnz\HelpdeskLogger\HelpdeskLogger;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void report(\Throwable $e)
 * @method static bool isEnabled()
 */
class Helpdesk extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HelpdeskLogger::class;
    }

    /**
     * Wire this package into Laravel 11+ exception handling.
     *
     * MUST be called as a true static method — NOT via the facade's
     * __callStatic proxy — because the callback fires during
     * HandleExceptions bootstrap, which runs BEFORE the service
     * provider's register() method. Resolving a facade at that point
     * would fail with "Unresolvable dependency".
     *
     * We sidestep the proxy by registering a closure that resolves
     * the underlying logger lazily (at exception time, not at
     * bootstrap time). By the time any exception fires, provider
     * registration has long since completed.
     */
    public static function captureExceptions(object $exceptions): void
    {
        HelpdeskLogger::register($exceptions);
    }
}
