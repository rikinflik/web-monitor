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

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    /**
     * Every declared severity, ordered most to least severe — the
     * vocabulary GitHub Advisories' REST `GET /advisories` endpoint reports
     * (the one GitHubAdvisoryFetcher actually calls: `unknown | low | medium
     * | high | critical`). RSS/Atom sources never report a severity at all:
     * a null `severity` means "this source does not report severity", not
     * "harmless", so it is deliberately NOT part of this list — see
     * severityRank().
     *
     * @var list<string>
     */
    public const SEVERITIES = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
    ];

    /**
     * Accepted input spellings that normalizeSeverity() maps to a canonical
     * SEVERITIES value before anything is stored. `moderate` is GitHub's
     * GraphQL `SecurityAdvisorySeverity` enum spelling for the same severity
     * the REST endpoint calls `medium` — accepted as input, never stored.
     *
     * @var array<string, string>
     */
    private const SEVERITY_ALIASES = [
        'moderate' => self::SEVERITY_MEDIUM,
    ];

    /**
     * Label + Tailwind pill classes per declared severity, keyed by the
     * canonical SEVERITIES value — the single place the Blade views derive
     * their severity pill from (see severityBadge()). A severity added to
     * SEVERITIES without a matching entry here falls through to
     * self::UNRATED_BADGE, which test_every_declared_severity_has_a_non_unrated_badge()
     * in AlertFindingTest catches.
     *
     * @var array<string, array{label: string, classes: string}>
     */
    private const SEVERITY_BADGES = [
        self::SEVERITY_CRITICAL => ['label' => 'Critical', 'classes' => 'bg-red-100 text-red-800'],
        self::SEVERITY_HIGH => ['label' => 'High', 'classes' => 'bg-orange-100 text-orange-800'],
        self::SEVERITY_MEDIUM => ['label' => 'Medium', 'classes' => 'bg-yellow-100 text-yellow-800'],
        self::SEVERITY_LOW => ['label' => 'Low', 'classes' => 'bg-blue-100 text-blue-800'],
    ];

    /**
     * @var array{label: string, classes: string}
     */
    private const UNRATED_BADGE = ['label' => 'Unrated', 'classes' => 'bg-gray-100 text-gray-800'];

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

    /**
     * Normalize a raw severity value from a feed into the canonical
     * vocabulary — lowercased and trimmed, with known aliases (SEVERITY_ALIASES)
     * mapped to their canonical value. Anything that is not, after that, a
     * declared SEVERITIES value (including GitHub REST's own "unknown")
     * degrades to null, so it renders honestly as "Unrated" rather than
     * carrying a real-looking label that silently ranks last — see
     * severityRank() and severityBadge().
     *
     * Call this at the fetcher boundary (before a finding is ever stored),
     * never at render time: it is what keeps the stored vocabulary — and so
     * the ranking SQL's case-sensitive `CASE severity WHEN ...` match —
     * consistent regardless of the feed's original casing.
     */
    public static function normalizeSeverity(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtolower(trim($raw));
        $normalized = self::SEVERITY_ALIASES[$normalized] ?? $normalized;

        return in_array($normalized, self::SEVERITIES, true) ? $normalized : null;
    }

    /**
     * Label + Tailwind pill classes for this finding's severity, derived
     * from SEVERITY_BADGES so the Blade views never hardcode their own
     * severity → label/pill chain (and so a severity added to SEVERITIES
     * without a matching badge entry degrades visibly to "Unrated" rather
     * than being silently mislabeled).
     *
     * @return array{label: string, classes: string}
     */
    public function severityBadge(): array
    {
        return self::SEVERITY_BADGES[$this->severity] ?? self::UNRATED_BADGE;
    }

    /**
     * Schemes safe to render into an href="{{ }}" attribute.
     *
     * @var list<string>
     */
    private const SAFE_URL_SCHEMES = ['http', 'https'];

    /**
     * The url, only when it is safe to render as a clickable href — null
     * otherwise, so the Blade views can fall back to plain, non-link text.
     *
     * Both fetchers already blank an item's url at insert time when its
     * scheme is not http/https (see App\Support\SanitizesFeedUrls), since
     * Blade's auto-escaping stops attribute breakout but not a
     * javascript:/data: scheme, which would execute in the viewer's own
     * origin. This is the second, independent guard at the actual render
     * boundary: a row that reached this column by any other path (a future
     * fetcher, a manual seed, a bug) still cannot produce a dangerous href.
     */
    public function safeUrl(): ?string
    {
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));

        return in_array($scheme, self::SAFE_URL_SCHEMES, true) ? $this->url : null;
    }
}
