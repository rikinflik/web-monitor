<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Current SEO/redirect check state for a single monitored URL.
 *
 * One-to-one child of Monitor (via the unique monitor_id FK); the paired row is
 * auto-created by the Monitor::created hook. History is kept in SeoCheckLog.
 */
final class SeoCheck extends Model
{
    use HasFactory;

    // Redirect classification vocabularies (D9).
    public const WWW_TO_NO_WWW = 'www→no-www';
    public const NO_WWW_TO_WWW = 'no-www→www';
    public const HTTP_TO_HTTPS = 'http→https';
    public const HTTPS_TO_HTTP = 'https→http';
    public const WITH_SLASH = 'with /';
    public const WITHOUT_SLASH = 'without /';
    public const NONE = 'none';

    protected $fillable = [
        'monitor_id',
        'www_redirect',
        'https_redirect',
        'trailing_slash_redirect',
        'robots_ok',
        'sitemap_ok',
        'interval',
        'last_checked_at',
    ];

    protected $casts = [
        'robots_ok' => 'boolean',
        'sitemap_ok' => 'boolean',
        'interval' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SeoCheckLog::class);
    }
}
