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
 */
final class AlertFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_source_id',
        'external_id',
        'title',
        'url',
        'severity',
        'published_at',
        'first_seen_at',
    ];

    protected $casts = [
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
}
