<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';

    /**
     * Receive status-change notifications for every monitor, including
     * monitors created after this preference was saved.
     */
    public const NOTIFY_ALL = 'all';

    /**
     * Receive status-change notifications only for the monitors explicitly
     * subscribed to through the monitor_notification_user pivot.
     */
    public const NOTIFY_SELECTED = 'selected';

    /**
     * Receive no status-change notifications at all.
     */
    public const NOTIFY_NONE = 'none';

    /**
     * Every valid value for the notify_mode column.
     *
     * @var list<string>
     */
    public const NOTIFY_MODES = [
        self::NOTIFY_ALL,
        self::NOTIFY_SELECTED,
        self::NOTIFY_NONE,
    ];

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'notify_mode',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function monitors(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /**
     * Monitors this user has explicitly subscribed to for notifications.
     *
     * Only meaningful when notify_mode is NOTIFY_SELECTED; the rows are kept
     * when switching to another mode so the selection survives a round trip
     * through "receive everything" and back.
     */
    public function notifiedMonitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'monitor_notification_user')
            ->withTimestamps();
    }

    /**
     * Whether this user wants status-change emails for the given monitor.
     *
     * Ownership is irrelevant: recipients are driven purely by the stored
     * preference, so a user can be notified about monitors owned by others.
     */
    public function wantsNotificationsFor(Monitor $monitor): bool
    {
        return match ($this->notify_mode) {
            self::NOTIFY_ALL => true,
            self::NOTIFY_SELECTED => $this->notifiedMonitors()
                ->whereKey($monitor->getKey())
                ->exists(),
            default => false,
        };
    }
}
