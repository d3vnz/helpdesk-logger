<?php

namespace D3vnz\HelpdeskLogger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void report(\Throwable $e)
 * @method static void captureExceptions(\Illuminate\Foundation\Configuration\Exceptions $exceptions)
 * @method static bool isEnabled()
 */
class Helpdesk extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \D3vnz\HelpdeskLogger\HelpdeskLogger::class;
    }
}
