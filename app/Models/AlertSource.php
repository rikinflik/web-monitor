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
 * `security_only` is a known fact about the upstream feed itself, not a
 * guess: true for drupal/wordpress/laravel/vue/angular (every item they
 * return is already a vulnerability disclosure by construction), false for
 * `php` (a general news feed, classified per-item on title via
 * AlertSecurityClassifier instead). See AlertSecurityClassifier for how
 * this flag is consumed.
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

    /**
     * How many hours may pass since `last_checked_at` before a source is
     * considered stale rather than healthy. The single named place this
     * threshold lives — both Blade views (alerts/index, public-overview)
     * read it only indirectly via healthBadge()/isStale(), never as a
     * literal.
     *
     * The check schedule is hourly (routes/console.php:
     * Schedule::command('alerts:check')->hourly()), so 3 hours is roughly
     * three missed runs: enough slack to absorb a single delayed or
     * overlapping run without false-alarming, while still catching a dead
     * queue worker, a dead cron, or a job that silently died into
     * failed_jobs well before "Healthy" has been quietly wrong for days.
     */
    public const STALE_AFTER_HOURS = 3;

    /**
     * Label + Tailwind pill classes per health state, keyed the same way
     * AlertFinding::SEVERITY_BADGES keys its severity pills — the single
     * place both Blade views derive their status pill from, so the
     * precedence below cannot drift between the authenticated dashboard and
     * the public overview.
     *
     * @var array<string, array{label: string, classes: string}>
     */
    private const HEALTH_BADGES = [
        'failing' => ['label' => 'Check failing', 'classes' => 'bg-red-100 text-red-800'],
        'stale' => ['label' => 'Stale', 'classes' => 'bg-yellow-100 text-yellow-800'],
        'healthy' => ['label' => 'Healthy', 'classes' => 'bg-green-100 text-green-800'],
        'never' => ['label' => 'Never checked', 'classes' => 'bg-gray-100 text-gray-800'],
    ];

    protected $fillable = [
        'key',
        'name',
        'kind',
        'config',
        'security_only',
        'last_checked_at',
        'last_success_at',
        'last_error',
    ];

    protected $casts = [
        'config' => 'array',
        'security_only' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];

    public function findings(): HasMany
    {
        return $this->hasMany(AlertFinding::class);
    }

    /**
     * Whether this source has gone quiet for longer than STALE_AFTER_HOURS
     * since its last check attempt — distinct from "never checked"
     * (last_checked_at null), which is its own, more severe state handled
     * separately by healthBadge().
     */
    public function isStale(): bool
    {
        return $this->last_checked_at !== null
            && $this->last_checked_at->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * Label + Tailwind pill classes for this source's health, derived from
     * HEALTH_BADGES so the Blade views never hardcode their own
     * last_error/last_checked_at/isStale() chain (mirrors
     * AlertFinding::severityBadge()).
     *
     * Precedence, most urgent first:
     * 1. last_error set → "Check failing", regardless of staleness — a
     *    concrete known failure reason is more informative than mere
     *    staleness, and a source that is both stale and failing must render
     *    one coherent status, not two conflicting ones.
     * 2. isStale() → "Stale" — checked before, but not recently enough to
     *    trust that the pipeline (queue worker/cron/job) is still alive.
     * 3. last_checked_at set (and not stale) → "Healthy".
     * 4. last_checked_at null → "Never checked" — distinct from "Stale":
     *    there is no data at all, not merely old data.
     *
     * @return array{label: string, classes: string}
     */
    public function healthBadge(): array
    {
        return match (true) {
            $this->last_error !== null => self::HEALTH_BADGES['failing'],
            $this->isStale() => self::HEALTH_BADGES['stale'],
            $this->last_checked_at !== null => self::HEALTH_BADGES['healthy'],
            default => self::HEALTH_BADGES['never'],
        };
    }
}
