<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->resolveTelegramCaBundle();
    }

    /**
     * Point the Telegram HTTP client at a CA bundle it can actually open.
     *
     * Runs here rather than in config/services.php because the path has to be
     * resolved per process, not per deployment: panel-managed hosts run
     * scheduled tasks inside a chroot, so the application root that cron sees
     * is not the one the web context sees, and base_path() is the only thing
     * that is right in both. Baking an absolute path into a cached config file
     * would fix it for one context and break the other.
     *
     * Configured relative, it also survives the site being moved.
     */
    protected function resolveTelegramCaBundle(): void
    {
        $bundle = config('services.telegram-bot-api.ca_bundle');

        if (! is_string($bundle) || $bundle === '') {
            return;
        }

        config([
            'services.telegram.http.verify' => str_starts_with($bundle, '/')
                ? $bundle
                : base_path($bundle),
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
