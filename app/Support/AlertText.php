<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one place operator-alert text is made safe to send.
 *
 * Truncate first, then escape: a failing check can carry a multi-KB error
 * string, and the richest message is exactly the one that must not silently
 * fail to send against Telegram's 4096-character cap. Escaping is applied after
 * truncation so a cut cannot land inside an HTML entity. The full text always
 * stays in monitor_logs.
 */
final class AlertText
{
    public static function escape(?string $raw, int $limit = 500): string
    {
        return htmlspecialchars(Str::limit((string) $raw, $limit), ENT_QUOTES);
    }
}
