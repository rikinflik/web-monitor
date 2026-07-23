<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history row capturing the five SEO check results at a point in time.
 *
 * Mirrors MonitorLog: rows are written by SeoCheckService (deduplicated against
 * the latest row), so no factory is defined.
 */
class SeoCheckLog extends Model
{
    protected $fillable = [
        'seo_check_id',
        'www_redirect',
        'https_redirect',
        'trailing_slash_redirect',
        'robots_ok',
        'sitemap_ok',
        'checked_at',
    ];

    protected $casts = [
        'robots_ok' => 'boolean',
        'sitemap_ok' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function seoCheck(): BelongsTo
    {
        return $this->belongsTo(SeoCheck::class);
    }
}
