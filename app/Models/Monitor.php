<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Monitor extends Model
{
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
    ];

    protected static function booted(): void
    {
        static::creating(function (Monitor $monitor) {
            $monitor->public_token = Str::random(32);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MonitorLog::class);
    }
}
