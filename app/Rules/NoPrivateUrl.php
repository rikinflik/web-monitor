<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects URLs that resolve to private, loopback, or reserved IP ranges to prevent SSRF.
 */
final class NoPrivateUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (!$host) {
            $fail('The :attribute must have a valid hostname.');
            return;
        }

        // Strip IPv6 brackets (e.g. [::1])
        $host = trim($host, '[]');

        if (strtolower($host) === 'localhost') {
            $fail('The :attribute must not point to a private or reserved address.');
            return;
        }

        // gethostbyname returns the original string when resolution fails
        $ip = gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            // Could not resolve — skip to avoid blocking legitimate DNS hiccups
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $fail('The :attribute must not point to a private or reserved IP address.');
        }
    }
}
