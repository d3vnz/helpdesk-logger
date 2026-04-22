<?php

namespace D3vnz\HelpdeskLogger;

use Throwable;

/**
 * sha1(exception_class + top-non-vendor-frame file + line + function)
 *
 * Must match TicketMate's App\Support\ErrorFingerprint recipe so the
 * client can pre-dedupe bursts without hitting the network.
 */
class Fingerprint
{
    /**
     * @param  list<array<string,mixed>>  $normalizedFrames
     */
    public static function compute(Throwable $e, array $normalizedFrames): string
    {
        $topApp = null;
        foreach ($normalizedFrames as $frame) {
            if (empty($frame['is_vendor'])) {
                $topApp = $frame;
                break;
            }
        }

        if ($topApp) {
            $parts = [
                $e::class,
                (string) ($topApp['file'] ?? ''),
                (string) ($topApp['line'] ?? ''),
                (string) ($topApp['function'] ?? ''),
            ];
            return sha1(implode('|', $parts));
        }

        return sha1($e::class . '|' . $e->getMessage());
    }
}
