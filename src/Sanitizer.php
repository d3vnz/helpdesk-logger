<?php

namespace D3vnz\HelpdeskLogger;

/**
 * Redacts sensitive values before upload.
 *
 * Substring match (case-insensitive) on keys — so "x-api-key" and
 * "secret_token" both catch. Values replaced with "[REDACTED]".
 * Arrays are walked recursively. Strings are returned as-is.
 */
class Sanitizer
{
    /** @param  array<int,string>  $sensitiveKeys */
    public function __construct(protected array $sensitiveKeys) {}

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $v) {
                $keyStr = (string) $key;
                $out[$key] = $this->isSensitive($keyStr) ? '[REDACTED]' : $this->sanitize($v);
            }
            return $out;
        }

        if (is_object($value)) {
            // Don't try to sanitize arbitrary objects — stringify with a hint.
            return '[object ' . $value::class . ']';
        }

        return $value;
    }

    protected function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach ($this->sensitiveKeys as $needle) {
            if ($needle === '') continue;
            if (str_contains($lower, strtolower($needle))) return true;
        }
        return false;
    }
}
