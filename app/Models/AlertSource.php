<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A polled upstream feed (RSS/Atom or GitHub Advisories) for one of the six
 * tracked ecosystems (PHP, Drupal, WordPress, Laravel, Vue, Angular).
 *
 * Global/account-wide: no user_id, seeded once by AlertSourceSeeder. The
 * `config` column holds per-kind fetch parameters (feed URL, or ecosystem +
 * affected package names for GitHub Advisories).
 *
 * The three check-related timestamps/fields together distinguish "nothing
 * happened" from "the source is broken" — both otherwise look identical
 * (no new findings):
 * - `last_checked_at`: the most recent time a check was attempted, whether
 *   it succeeded or failed.
 * - `last_success_at`: the most recent time a check actually completed
 *   successfully (fetched and processed the feed without error).
 * - `last_error`: why the most recent attempt failed; null when the most
 *   recent attempt succeeded.
 */
final class AlertSource extends Model
{
    use HasFactory;

    public const KIND_RSS = 'rss';

    public const KIND_GITHUB_ADVISORY = 'github_advisory';

    protected $fillable = [
        'key',
        'name',
        'kind',
        'config',
        'last_checked_at',
        'last_success_at',
        'last_error',
    ];

    protected $casts = [
        'config' => 'array',
        'last_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];

    public function findings(): HasMany
    {
        return $this->hasMany(AlertFinding::class);
    }
}
