<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Monitor extends Model
{
    use HasFactory;

    public const STATUS_UP = 'up';
    public const STATUS_DOWN = 'down';

    protected $fillable = [
        'name',
        'url',
        'interval',
        'timeout',
        'expected_status_code',
        'keyword',
        'status',
        'down_since',
        'last_down_notified_at',
        'down_reminders_sent',
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
        'down_since' => 'datetime',
        'last_down_notified_at' => 'datetime',
        'down_reminders_sent' => 'integer',
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

    public function isDown(): bool
    {
        return $this->status === self::STATUS_DOWN;
    }

    /**
     * Minutes to wait after the last email before the next reminder is due.
     *
     * Walks the configured backoff one step per reminder already sent and
     * sticks on the final step, so a long outage settles into a steady rhythm
     * instead of repeating the initial burst.
     */
    public function nextReminderDelayMinutes(): int
    {
        /** @var list<int> $steps */
        $steps = config('monitoring.down_reminder_backoff_minutes', [15]);

        return $steps[min($this->down_reminders_sent, count($steps) - 1)];
    }

    /**
     * Whether a "still down" reminder should go out on this check.
     *
     * A monitor that is down but has never been emailed about is always due —
     * that covers rows predating the reminder feature.
     */
    public function isDownReminderDue(): bool
    {
        if (! $this->isDown()) {
            return false;
        }

        if (! $this->last_down_notified_at) {
            return true;
        }

        // Due *at* the boundary, not a tick after it, so a check landing exactly
        // on the step still sends.
        return now()->greaterThanOrEqualTo(
            $this->last_down_notified_at->copy()->addMinutes($this->nextReminderDelayMinutes()),
        );
    }

    /**
     * How long the current (or just-ended) outage lasted, in words.
     *
     * @param \Illuminate\Support\Carbon|null $downSince
     *   Outage start, passed explicitly by the recovery path because the column
     *   has already been cleared by then.
     */
    public function outageDuration(?Carbon $downSince = null): ?string
    {
        $start = $downSince ?? $this->down_since;

        if (! $start) {
            return null;
        }

        return $start->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE, true, 2);
    }

    /**
     * Users subscribed to this monitor through their notification preference.
     */
    public function notificationSubscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'monitor_notification_user')
            ->withTimestamps();
    }

    /**
     * Every user that should receive a status-change email for this monitor.
     *
     * Recipients are resolved from the users' notify_mode preference, not from
     * monitor ownership: "all" users always match, "selected" users match only
     * when subscribed, and "none" users never match.
     *
     * @return Collection<int, User>
     */
    public function notificationRecipients(): Collection
    {
        return User::query()
            ->where(function ($query) {
                $query
                    ->where('notify_mode', User::NOTIFY_ALL)
                    ->orWhere(function ($subscribed) {
                        $subscribed
                            ->where('notify_mode', User::NOTIFY_SELECTED)
                            ->whereHas(
                                'notifiedMonitors',
                                fn ($monitors) => $monitors->whereKey($this->getKey()),
                            );
                    });
            })
            ->get();
    }
}
