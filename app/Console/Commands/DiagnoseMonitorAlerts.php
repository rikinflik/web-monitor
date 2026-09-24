<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Notifications\MonitorStatusAlert;
use App\Notifications\OperatorAlerts;
use App\Support\AlertText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report why monitor outage alerts are, or are not, going out.
 *
 * Exists because `tinker --execute` is unusable on hosts that mangle quoted
 * arguments (Plesk's task runner among them): every check here is reachable
 * through a command name and flags, with no shell quoting to survive.
 */
final class DiagnoseMonitorAlerts extends Command
{
    protected $signature = 'monitor:diagnose
        {filter? : Only monitors whose URL or name contains this}
        {--alert : Send a real DOWN alert for the first match}
        {--probe : Make a live HTTPS request to the Telegram API}
        {--install-ca : Copy a readable CA bundle into storage so the scheduler can use it}
        {--reset : Clear the outage state so the next check alerts again}';

    protected $description = 'Report why monitor outage alerts are or are not going out.';

    public function handle(OperatorAlerts $alerts): int
    {
        $this->telegramConfig();
        $this->tlsConfig();
        $this->queueState();

        $monitors = $this->monitors();

        if ($monitors->isEmpty()) {
            $this->warn('No monitors matched.');

            return self::FAILURE;
        }

        $this->monitorTable($monitors);
        $this->swallowedAlertErrors();

        if ($this->option('reset')) {
            $this->resetOutage($monitors->first());
        }

        if ($this->option('alert')) {
            $this->fireAlert($alerts, $monitors->first());
        }

        return self::SUCCESS;
    }

    /**
     * Whether the two env vars the alerting gate depends on are actually set.
     *
     * Values are never printed: a bot token in a task-runner log is a leak.
     */
    protected function telegramConfig(): void
    {
        $token = (string) config('services.telegram-bot-api.token');
        $chat = (string) config('services.telegram-bot-api.alert_chat_id');

        $this->line('<comment>Telegram config</comment>');
        $this->line('  token      : '.($token !== '' ? 'set ('.strlen($token).' chars)' : 'EMPTY'));
        $this->line('  chat id    : '.($chat !== '' ? $chat : 'EMPTY — alerting is OFF'));
        $this->line('  channel    : '.(class_exists(\NotificationChannels\Telegram\TelegramMessage::class)
            ? 'package loaded'
            : 'MISSING — run composer install'));
        $this->newLine();
    }

    /**
     * Where PHP looks for CA certificates, and whether it can actually read it.
     *
     * Monitor checks pass 'verify' => false and so never touch this, which is
     * why uptime checking keeps working while every Telegram send dies with
     * cURL error 77. On panel-managed hosts the usual cause is an open_basedir
     * that excludes the bundle rather than a missing file.
     */
    protected function tlsConfig(): void
    {
        $this->line('<comment>TLS / CA bundle</comment>');

        $defaults = openssl_get_cert_locations();
        $candidates = [
            'curl.cainfo' => ini_get('curl.cainfo'),
            'openssl.cafile' => ini_get('openssl.cafile'),
            'openssl.capath' => ini_get('openssl.capath'),
            'default cert file' => $defaults['default_cert_file'] ?? null,
            'default cert dir' => $defaults['default_cert_dir'] ?? null,
        ];

        foreach ($candidates as $label => $path) {
            if ($path === false || $path === null || $path === '') {
                $this->line(sprintf('  %-18s: (not set)', $label));

                continue;
            }

            $this->line(sprintf(
                '  %-18s: %s  [%s]',
                $label,
                $path,
                match (true) {
                    ! file_exists($path) => 'MISSING',
                    ! is_readable($path) => 'NOT READABLE',
                    default => 'ok',
                },
            ));
        }

        $this->line('  running as        : '.$this->processUser());
        $this->line('  TELEGRAM_CA_BUNDLE: '.$this->configuredBundle());
        $this->candidateBundles();

        $basedir = ini_get('open_basedir');
        $this->line('  open_basedir      : '.($basedir ?: '(not set)'));

        if ($basedir) {
            $this->warn('  open_basedir is set — the CA bundle must sit inside one of those paths.');
        }

        if ($this->option('install-ca')) {
            $this->installCaBundle();
        }

        if ($this->option('probe')) {
            $this->httpsProbe();
        }

        $this->newLine();
    }

    /**
     * Who this process runs as.
     *
     * The same command can succeed for root and fail for the panel's task
     * runner when the CA store is not readable by the subscription user, and
     * that difference is invisible unless it is printed.
     */
    protected function processUser(): string
    {
        $name = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
            : get_current_user();

        return sprintf('%s (uid %s)', $name, function_exists('posix_geteuid') ? posix_geteuid() : '?');
    }

    /**
     * The usual CA bundle locations, so the fix can be copied from the output.
     */
    protected function candidateBundles(): void
    {
        $paths = [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
            '/usr/local/share/certs/ca-root-nss.crt',
        ];

        $this->line('  candidate bundles :');

        foreach ($paths as $path) {
            $this->line(sprintf(
                '    %-42s %s',
                $path,
                match (true) {
                    ! file_exists($path) => 'missing',
                    ! is_readable($path) => 'NOT READABLE by this user',
                    default => 'READABLE — usable for curl.cainfo',
                },
            ));
        }
    }

    /**
     * What the Telegram client is told to verify against, if anything.
     */
    protected function configuredBundle(): string
    {
        $verify = config('services.telegram.http.verify');

        if (! is_string($verify)) {
            return '(not set — using the system default)';
        }

        return $verify.'  ['.match (true) {
            ! file_exists($verify) => 'MISSING',
            ! is_readable($verify) => 'NOT READABLE',
            default => 'ok',
        }.']';
    }

    /**
     * Copy a readable system bundle into the project.
     *
     * Run from a context that can read the system store; the copy then lives
     * beside the application, where the scheduler's user can reach it without
     * anyone needing root to change permissions on /etc.
     */
    protected function installCaBundle(): void
    {
        $source = collect([
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
        ])->first(fn (string $path) => is_readable($path));

        if ($source === null) {
            $this->error('  install-ca: no readable system bundle to copy from.');

            return;
        }

        $target = storage_path('app/certs/cacert.pem');

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            $this->error('  install-ca: could not create '.dirname($target));

            return;
        }

        if (! copy($source, $target)) {
            $this->error('  install-ca: copy failed.');

            return;
        }

        // Explicit, because umask silently narrows both mkdir and copy, and a
        // bundle another user cannot read is the problem this command exists
        // to solve.
        chmod(dirname($target), 0755);
        chmod($target, 0644);

        $this->info('  install-ca: copied '.$source);
        $this->reachability($target);
        $this->info('  install-ca: now add this to .env and re-run with --probe');
        // Relative on purpose: cron may see the app under a different root.
        $this->line('    TELEGRAM_CA_BUNDLE='.ltrim(str_replace(base_path(), '', $target), '/'));
    }

    /**
     * Whether every directory on the way to the bundle is traversable.
     *
     * A world-readable file under a 0700 parent is unreachable for anyone
     * else, and the scheduler's user is exactly the "anyone else" that has to
     * open it. Reporting each step turns a silent failure into a fixable one.
     */
    protected function reachability(string $target): void
    {
        $parts = explode('/', ltrim(dirname($target), '/'));
        $path = '';
        $blocked = false;

        foreach ($parts as $part) {
            $path .= '/'.$part;
            $mode = @fileperms($path);

            if ($mode === false) {
                continue;
            }

            $octal = substr(sprintf('%o', $mode), -4);

            if (((int) $octal[3] & 1) === 0) {
                $blocked = true;
                $this->warn(sprintf('  install-ca: %s is %s — not traversable by other users', $path, $octal));
            }
        }

        $this->line(sprintf('  install-ca: bundle mode %s', substr(sprintf('%o', fileperms($target)), -4)));

        if ($blocked) {
            $this->warn('  install-ca: the scheduler\'s user may still not reach it — widen the directories above, or place the bundle elsewhere.');
        }
    }

    /**
     * Confirm the diagnosis by actually talking to the Telegram API.
     */
    protected function httpsProbe(): void
    {
        $this->line('  probe             : GET https://api.telegram.org ...');

        try {
            $status = \Illuminate\Support\Facades\Http::timeout(10)
                ->get('https://api.telegram.org')
                ->status();
            $this->info("  probe result      : HTTP {$status} — TLS works from this process");
        } catch (\Throwable $e) {
            $this->error('  probe result      : '.str($e->getMessage())->limit(200));
        }
    }

    protected function queueState(): void
    {
        $this->line('<comment>Queue</comment>');
        $this->line('  connection : '.config('queue.default'));

        if (config('queue.default') === 'database') {
            $this->line('  pending    : '.DB::table('jobs')->count());
            $this->line('  failed     : '.DB::table('failed_jobs')->count());
        }

        $this->newLine();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Monitor>
     */
    protected function monitors()
    {
        $filter = $this->argument('filter');

        return Monitor::query()
            ->when($filter, fn ($q) => $q
                ->where('url', 'LIKE', "%{$filter}%")
                ->orWhere('name', 'LIKE', "%{$filter}%"))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\Monitor> $monitors
     */
    protected function monitorTable($monitors): void
    {
        $this->line('<comment>Monitors</comment>');
        $this->table(
            ['id', 'name', 'status', 'last checked', 'down since', 'last notified', 'sent', 'due'],
            $monitors->map(fn (Monitor $m) => [
                $m->id,
                str($m->name)->limit(22),
                $m->status,
                $m->last_checked_at ?? '—',
                $m->down_since ?? '—',
                $m->last_down_notified_at ?? '—',
                $m->down_reminders_sent,
                $m->isDownReminderDue() ? 'YES' : 'no',
            ])->all(),
        );
    }

    /**
     * Alert failures are swallowed by design, so the log is the only trace.
     */
    protected function swallowedAlertErrors(): void
    {
        $path = storage_path('logs/laravel.log');
        $this->line('<comment>Swallowed alert errors</comment>');

        if (! is_readable($path)) {
            $this->line('  (no readable log at '.$path.')');
            $this->newLine();

            return;
        }

        $hits = array_filter(
            array_slice(file($path) ?: [], -2000),
            fn ($line) => str_contains($line, 'Operator alert could not be sent'),
        );

        if ($hits === []) {
            $this->line('  none — the Telegram send never threw');
        }

        foreach (array_slice($hits, -3) as $line) {
            // Keep the exception message and drop the stack trace: cURL states
            // which CAfile and CApath it tried, and that is the whole answer.
            // Generous: the context block sits at the end of the line and is
            // the point of printing it at all, so it must not be cut off.
            $message = str(AlertText::redact(trim($line)))->limit(1600);
            $this->line('  '.$message);
            $this->newLine();
        }

        $this->newLine();
    }

    protected function resetOutage(Monitor $monitor): void
    {
        $monitor->update([
            'status' => Monitor::STATUS_UP,
            'down_since' => null,
            'last_down_notified_at' => null,
            'down_reminders_sent' => 0,
        ]);

        $this->info("Reset monitor {$monitor->id} to up — the next check will alert if it is still down.");
    }

    /**
     * Send the real alert class, not the simpler telegram:test message.
     *
     * Errors stay swallowed here on purpose: this reproduces exactly what a
     * failing check does, and the log section above is where it surfaces.
     */
    protected function fireAlert(OperatorAlerts $alerts, Monitor $monitor): void
    {
        $alerts->send(new MonitorStatusAlert($monitor, Monitor::STATUS_DOWN));
        $this->info("Fired a DOWN alert for monitor {$monitor->id} ({$monitor->name}).");
        $this->line('If nothing arrives, re-run this command and read the log section.');
    }
}
