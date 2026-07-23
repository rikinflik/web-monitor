<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Monitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'interval',
        'timeout',
        'expected_status_code',
        'keyword',
        'status',
        'last_checked_at',
        'user_id',
        'public_token',
        'webhook_url',
        'basic_auth_user',
        'basic_auth_password',
    ];

    protected $hidden = [
        'basic_auth_password',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'basic_auth_password' => 'encrypted',
        'expected_status_code' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Monitor $monitor) {
            $monitor->public_token = Str::random(32);
        });

        static::created(function (Monitor $monitor) {
            try {
                $monitor->seoCheck()->create(self::defaultSeoCheckAttributes());
            } catch (\Throwable $e) {
                Log::error("Failed to create SeoCheck for Monitor {$monitor->id}: " . $e->getMessage());
            }
        });
    }

    /**
     * Default attributes for the auto-created paired SeoCheck row.
     *
     * @return array<string, mixed>
     */
    protected static function defaultSeoCheckAttributes(): array
    {
        return [
            'interval' => 1440,
            'www_redirect' => SeoCheck::NONE,
            'https_redirect' => SeoCheck::NONE,
            'trailing_slash_redirect' => SeoCheck::NONE,
            'robots_ok' => false,
            'sitemap_ok' => false,
            'last_checked_at' => null,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MonitorLog::class);
    }

    public function seoCheck(): HasOne
    {
        return $this->hasOne(SeoCheck::class);
    }
}
