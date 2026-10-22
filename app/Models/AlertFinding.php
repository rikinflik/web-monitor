<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A single item recorded from an AlertSource feed (an advisory, a release
 * note, a security announcement).
 *
 * Deduplicated per source by the (alert_source_id, external_id) unique
 * index — external_id is the feed's own stable identifier (GHSA id, RSS
 * <guid>, or the item link as fallback). A feed item that cannot produce a
 * non-empty id must be skipped by the fetcher, never stored here with an
 * empty one — an empty external_id would collapse every id-less item under
 * one source into a single row via the dedup unique key, silently losing
 * genuine advisories.
 *
 * `is_security` is decided once at insert (or by the one-off backfill
 * migration for rows that predate this column) via
 * AlertSecurityClassifier, never recomputed at render — see that class for
 * the rule and AlertController for why the dashboard's cap depends on this
 * being stored rather than computed over only the rows already fetched.
 */
final class AlertFinding extends Model
{
    use HasFactory;

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_LOW = 'low';

    /**
     * Every declared severity, ordered most to least severe — the
     * vocabulary GitHub Advisories reports. RSS/Atom sources never report a
     * severity at all: a null `severity` means "this source does not report
     * severity", not "harmless", so it is deliberately NOT part of this
     * list — see severityRank().
     *
     * @var list<string>
     */
    public const SEVERITIES = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MODERATE,
        self::SEVERITY_LOW,
    ];

    protected $fillable = [
        'alert_source_id',
        'external_id',
        'title',
        'url',
        'severity',
        'is_security',
        'published_at',
        'first_seen_at',
    ];

    protected $casts = [
        'is_security' => 'boolean',
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
    ];

    /**
     * Register model event hooks.
     */
    protected static function booted(): void
    {
        self::saving(function (self $finding): void {
            if (trim((string) $finding->external_id) === '') {
                throw new InvalidArgumentException(
                    'AlertFinding external_id must not be empty or whitespace-only.'
                );
            }
        });
    }

    public function alertSource(): BelongsTo
    {
        return $this->belongsTo(AlertSource::class);
    }

    /**
     * Rank for sorting findings by severity, most severe first. A null or
     * unrecognised severity ranks after every declared one — a
     * display-ordering convenience only, never a claim that an unrated
     * finding is safer than a declared "low".
     */
    public function severityRank(): int
    {
        $index = array_search($this->severity, self::SEVERITIES, true);

        return $index === false ? count(self::SEVERITIES) : $index;
    }
}
