# Design: Add SEO / Redirects Tab

This design turns the locked proposal (`proposal.md`) and spec (`specs/seo-checks/spec.md`)
into concrete implementation contracts. It mirrors the existing Monitor architecture
(`Monitor` / `MonitorLog` / `MonitoringService` / `CheckMonitorJob` / `MonitorController`
/ `StoreMonitorRequest` / `routes/web.php` / `routes/console.php` / `resources/views/monitors/*`
/ `layouts/navigation.blade.php`) as closely as the differing check semantics allow, and
calls out every place where a deliberate divergence is required.

All identifiers, comments, and code text are English. User-facing Blade labels follow the
existing Spanish convention already used across `resources/views/monitors/*.blade.php`.

---

## 1. Key Decisions (with rationale)

### D1 — `seo_checks` is a one-to-one child of `monitors`, never an independent URL holder
`monitors` stays the single source of truth for `name` / `url` / ownership. `seo_checks`
carries only `monitor_id` (unique FK) plus check state. Rationale: satisfies the spec's
"Shared URL Source of Truth" requirement and guarantees both tabs list the identical set of
URLs with zero duplication or drift.

### D2 — Auto-create the paired `seo_checks` row via a `Monitor::created` hook, non-throwing
The pairing invariant is enforced in `Monitor::booted()` with a `static::created(...)`
closure (mirroring the existing `static::creating(...)` closure that sets `public_token`),
wrapped in `try/catch (\Throwable)` that logs and continues on failure. Rationale: keeping
it inside `booted()` matches the one place Monitor already customizes lifecycle behavior, so
we do not introduce a separate observer-registration surface. Non-throwing behavior satisfies
the spec scenario "Paired-row creation failure does not break monitor creation." A dedicated
`Monitor` observer class was considered and rejected: it would add a second lifecycle
mechanism alongside the existing `booted()` hook, splitting Monitor's creation logic across
two files for no functional gain.

### D3 — Two-table split (`seo_checks` current state + `seo_check_logs` history), dedup on write
Directly mirrors `Monitor` / `MonitorLog`. `seo_checks` holds current state; `seo_check_logs`
is append-only history. A new history row is inserted only when at least one of the five
result values differs from the latest log row — the same guard as
`MonitoringService::recordLog()` (which compares `status`/`status_code`). Rationale: the spec
mandates this split and dedup semantics explicitly; reusing the pattern keeps the mental model
identical to Monitor.

### D4 — Single redirect probe resolves all three canonicalization checks
One `GET` with `allow_redirects => false` + `http_errors => false`; if the response is 3xx
with a `Location` header, diff scheme/host/path of stored-URL vs `Location` to derive
www / https / trailing-slash simultaneously. Any non-differing dimension → `none`. A non-3xx
response (or a 3xx that changes none of the three dimensions) yields `none` for all three with
no extra probing. Rationale: the proposal's hard constraint is to minimize outbound requests;
this caps canonicalization at exactly one request instead of up to three.

### D5 — Outbound requests capped at 3 (common) / 4 (worst) per cycle
1 probe + 1 `robots.txt` + 1 `sitemap.xml`; only if `sitemap.xml` is not 200 do we issue a
single `sitemap_index.xml` fallback. Rationale: spec "Request-Minimizing Probe Design"; the
count is asserted directly in tests via Guzzle `Middleware::history`.

### D6 — New `SeoCheckService`, reusing the Guzzle DI pattern, `MonitoringService` untouched
`SeoCheckService` takes a constructor-injected `GuzzleHttp\Client` exactly like
`MonitoringService`, but declares it `private readonly` (per the project PHP rules for new
code — `MonitoringService` predates that rule and stays as-is). `MonitoringService`'s implicit
redirect-following for uptime checks is NOT modified. Rationale: separation of concerns +
spec "Existing Monitor uptime check is untouched"; sharing the injected client keeps the
`MockHandler` test pattern identical.

### D7 — Manual re-check dispatches synchronously; scheduler dispatches queued
`POST /seo/{seoCheck}/recheck` calls `CheckSeoJob::dispatchSync($seoCheck)`. The confirmed
`.env` `QUEUE_CONNECTION=database` (async) means a plain `dispatch()` would NOT update results
before the redirect renders. The periodic scheduler uses queued `CheckSeoJob::dispatch()`,
consistent with `CheckMonitorJob`. Rationale: spec "Manual Synchronous Re-Check" — fresh
results must show immediately without a running worker.

### D8 — `interval` stored in MINUTES; scheduler uses `addMinutes` (deliberate divergence)
This is the one intentional divergence from Monitor's scheduler, which uses
`addSeconds($monitor->interval)`. The spec explicitly defines `seo_checks.interval` as
**minutes**, default **1440** (daily). Copying `addSeconds` verbatim would make 1440 mean 24
minutes, contradicting the spec. Therefore the SEO scheduler block uses
`$seoCheck->last_checked_at->addMinutes($seoCheck->interval)`. Rationale: SEO cadence is
naturally daily-scale; minutes keep the stored number human-readable (1440 = 1 day) and the
divergence is isolated to one line and documented here. The loop structure
(`Schedule::call(fn) -> everyMinute()`, `Model::cursor()->each(...)`, null-or-elapsed guard)
is otherwise byte-for-byte the Monitor pattern.

### D9 — Result vocabularies stored as plain strings + booleans (no enum classes)
The three redirect columns are `string` columns defaulting to `'none'`, storing the exact
spec tokens (`www→no-www`, `no-www→www`, `http→https`, `https→http`, `with /`, `without /`,
`none`). `robots_ok` / `sitemap_ok` are `boolean` columns defaulting `false`. Token strings
are exposed as class constants on `SeoCheck` to avoid magic strings in the service and views.
Rationale: mirrors Monitor's plain-string `status` handling and stays within the proposal's
declared file list (no new enum files). Backed PHP enums were considered (cleaner typing) but
rejected to (a) keep parity with Monitor's `status` string and (b) avoid the token characters
`→`, space, and `/` complicating enum case naming. The `→` (U+2192) is stored in `utf8mb4`
columns (project default), which handles it natively.

### D10 — SEO-tab create form pins concrete Monitor defaults
The SEO create form exposes only `name` + `url`. `SeoCheckController::store()` fills the
remaining Monitor columns with pinned defaults matching the values pre-filled in the Monitor
create form: `interval => 60`, `timeout => 30`, `expected_status_code => 200`. `status`
(`'up'`) and `public_token` come from the migration default / the existing `creating` hook.
Rationale: "pin concrete defaults" — a URL added from the SEO tab behaves exactly as one added
via the Monitor form with its default field values, with no surprising cadence.

### D11 — Own minimal Blade views, no shared component refactor
New `seo/index.blade.php` and `seo/create.blade.php` duplicate the relevant Tailwind input /
badge / table markup rather than extracting a shared `<x-...>` component out of the existing
`monitors/create.blade.php`. Rationale: the proposal locks this to avoid touching working
Monitor views; there is no pre-existing shared form component to reuse.

### D12 — Backfill existing monitors via a data migration
Because the pairing invariant is enforced only from the `Monitor::created` hook onward, any
monitors that already exist when this feature ships would have no `seo_checks` row. A dedicated
data migration inserts one paired row (with defaults) for every monitor lacking one.
Rationale: the task requires addressing backfill; without it, existing monitors would be
invisible on the SEO tab and never scheduled.

---

## 2. Data Flow

### 2.1 URL creation (either tab) → paired rows
```
User submits Monitor form            User submits SEO form
  POST monitors.store                  POST seo.store
        |                                    |
  StoreMonitorRequest                  StoreSeoCheckRequest (name+url only)
        |                                    | + pinned defaults (D10)
        v                                    v
  $user->monitors()->create(...)   <---  $user->monitors()->create(...)
        |
        v
  Monitor::booted() creating hook -> sets public_token
  Monitor row inserted
        |
        v
  Monitor::booted() created hook (D2) -> $monitor->seoCheck()->create(defaults)
        |
        v
  seo_checks row exists (1:1). Row now visible in BOTH tab list views.
```

### 2.2 Periodic check (queued)
```
Schedule::call(...)->everyMinute()          (routes/console.php, new block)
  SeoCheck::cursor()->each:
     due? (last_checked_at null OR addMinutes(interval) isPast)
        -> CheckSeoJob::dispatch($seoCheck)     [queued: database driver]
              -> handle(SeoCheckService)
                    -> SeoCheckService::check($seoCheck)
```

### 2.3 Manual re-check (synchronous)
```
POST /seo/{seoCheck}/recheck
  SeoCheckController::recheck
     authorize('update', $seoCheck)            (SeoCheckPolicy, monitor->user_id)
     CheckSeoJob::dispatchSync($seoCheck)       [inline, ignores queue driver]
        -> SeoCheckService::check($seoCheck)
     redirect()->route('seo.index')->with('success', ...)
```

### 2.4 `SeoCheckService::check()` internals (per entry, <= 4 requests)
```
url    = $seoCheck->monitor->url
origin = scheme://host[:port]  (parse_url of $url)

1) probe:  GET $url  { allow_redirects:false, http_errors:false, timeout:T, verify:false }
   if 3xx AND has Location header:
        classifyRedirect($url, Location) -> [www, https, trailing_slash]
   else:  [none, none, none]

2) robots: GET {origin}/robots.txt { same options } -> robots_ok = (status===200)

3) sitemap:GET {origin}/sitemap.xml { same options }
        if status===200 -> sitemap_ok=true  (STOP, no fallback)
        else GET {origin}/sitemap_index.xml -> sitemap_ok=(status===200)

each GuzzleException is caught locally -> that dimension degrades to none/false

persist:
  build result set R = {www, https, trailing_slash, robots_ok, sitemap_ok}
  recordLog: insert seo_check_logs row only if R differs from latest log row (D3)
  update seo_checks: R + last_checked_at = now()
```

`classifyRedirect(string $from, string $to): array`
- parse scheme/host/path of both.
- **www**: `fromHost === 'www.'.toHost` → `www→no-www`; `toHost === 'www.'.fromHost`
  → `no-www→www`; else `none`.
- **https**: `from=http && to=https` → `http→https`; `from=https && to=http`
  → `https→http`; else `none`.
- **trailing slash**: normalize empty path to `/`; `toPath === rtrim(fromPath,'/').'/'`
  and lengths differ by 1 → `with /`; `fromPath === rtrim(toPath,'/').'/'` → `without /`;
  else `none`.

---

## 3. Concrete File Changes

### 3.1 New files
| Path | Purpose |
|------|---------|
| `app/Models/SeoCheck.php` | Current-state model, 1:1 to Monitor, HasMany logs, vocab constants |
| `app/Models/SeoCheckLog.php` | Append-only history model |
| `app/Services/SeoCheckService.php` | Request-minimizing probe logic (D4/D5/D6) |
| `app/Jobs/CheckSeoJob.php` | Queued job (dispatch) + sync path (dispatchSync) |
| `app/Http/Controllers/SeoCheckController.php` | `index`, `create`, `store`, `recheck` |
| `app/Http/Requests/StoreSeoCheckRequest.php` | name + url validation with `NoPrivateUrl` |
| `app/Policies/SeoCheckPolicy.php` | Ownership via `seoCheck->monitor->user_id` |
| `database/migrations/XXXX_XX_XX_XXXXXX_create_seo_checks_table.php` | `seo_checks` table |
| `database/migrations/XXXX_XX_XX_XXXXXX_create_seo_check_logs_table.php` | `seo_check_logs` table |
| `database/migrations/XXXX_XX_XX_XXXXXX_add_seo_checks_indexes.php` | index migration |
| `database/migrations/XXXX_XX_XX_XXXXXX_backfill_seo_checks_for_existing_monitors.php` | data backfill (D12) |
| `database/factories/SeoCheckFactory.php` | factory + named states |
| `resources/views/seo/index.blade.php` | list + per-check badges + re-check form |
| `resources/views/seo/create.blade.php` | name + url form |
| `tests/Feature/SeoCheckServiceTest.php` | probe/count/vocab tests, `@group seo-check-service` |
| `tests/Feature/SeoCheckControllerTest.php` | guard/ownership/validation/recheck, `@group seo-check-controller` |

> Migration filenames use Laravel's timestamp prefix (`YYYY_MM_DD_HHMMSS_`). They MUST sort
> **after** the existing `monitors`/`monitor_logs` migrations so the FK targets exist, and the
> backfill migration MUST sort **after** the three table/index migrations.

### 3.2 Modified files
| Path | Change |
|------|--------|
| `app/Models/Monitor.php` | add `seoCheck(): HasOne`; add `static::created(...)` hook inside existing `booted()` (D2) |
| `routes/web.php` | inside the existing `auth` group: `Route::resource('seo', SeoCheckController::class)->only(['index','create','store'])` + `Route::post('seo/{seoCheck}/recheck', [SeoCheckController::class, 'recheck'])->name('seo.recheck')` |
| `routes/console.php` | append a second, independent `Schedule::call(...)->everyMinute()` block for SEO (D8) |
| `resources/views/layouts/navigation.blade.php` | add desktop `<x-nav-link>` + mobile `<x-responsive-nav-link>` for "SEO / Redirects" with `request()->routeIs('seo.*')` |

### 3.3 Untouched by design
`app/Services/MonitoringService.php`, `app/Jobs/CheckMonitorJob.php`,
`app/Http/Controllers/MonitorController.php`, `app/Policies/MonitorPolicy.php`,
`resources/views/monitors/*.blade.php`, all existing Monitor migrations, the Filament panel.

---

## 4. Contracts

### 4.1 DB schema

**`seo_checks`** (`create_seo_checks_table`)
| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint unsigned` PK | `$table->id()` |
| `monitor_id` | `bigint unsigned` | `foreignId->constrained()->cascadeOnDelete()`, **`->unique()`** (1:1) |
| `www_redirect` | `string` | `->default('none')` — vocab: `www→no-www`/`no-www→www`/`none` |
| `https_redirect` | `string` | `->default('none')` — vocab: `http→https`/`https→http`/`none` |
| `trailing_slash_redirect` | `string` | `->default('none')` — vocab: `with /`/`without /`/`none` |
| `robots_ok` | `boolean` | `->default(false)` |
| `sitemap_ok` | `boolean` | `->default(false)` |
| `interval` | `integer` | `->default(1440)` — **minutes** (D8) |
| `last_checked_at` | `timestamp nullable` | |
| `created_at`/`updated_at` | timestamps | `$table->timestamps()` |

**`seo_check_logs`** (`create_seo_check_logs_table`)
| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigint unsigned` PK | |
| `seo_check_id` | `bigint unsigned` | `foreignId->constrained()->cascadeOnDelete()` |
| `www_redirect` | `string` | value at check time |
| `https_redirect` | `string` | |
| `trailing_slash_redirect` | `string` | |
| `robots_ok` | `boolean` | |
| `sitemap_ok` | `boolean` | |
| `checked_at` | `timestamp` | |
| `created_at`/`updated_at` | timestamps | |

**`add_seo_checks_indexes`** (mirrors `add_performance_indexes`)
- `seo_checks`: `$table->index('last_checked_at');`
- `seo_check_logs`: `$table->index(['seo_check_id', 'created_at']);`
- `down()` drops both, symmetric.

**`backfill_seo_checks_for_existing_monitors`** (D12)
- `up()`: for each `monitors` row without a matching `seo_checks` row, insert one with
  `interval => 1440`, redirect columns `'none'`, booleans `false`, `last_checked_at => null`,
  `created_at`/`updated_at => now()`. Use a chunked query (`Monitor::whereDoesntHave('seoCheck')`)
  or a raw insert-select to avoid loading the entire table.
- `down()`: no-op (the table drop in `create_seo_checks_table::down()` removes all rows). Documented
  as intentional so a full rollback still cleanly removes everything.

All migrations: `up()`/`down()` symmetric where meaningful, additive (new tables only), one
logical change per file — matching the existing migration style.

### 4.2 `App\Models\SeoCheck`
```php
final class SeoCheck extends Model
{
    use HasFactory;

    // Vocabulary constants (D9)
    public const WWW_TO_NO_WWW = 'www→no-www';
    public const NO_WWW_TO_WWW = 'no-www→www';
    public const HTTP_TO_HTTPS = 'http→https';
    public const HTTPS_TO_HTTP = 'https→http';
    public const WITH_SLASH    = 'with /';
    public const WITHOUT_SLASH = 'without /';
    public const NONE          = 'none';

    protected $fillable = [
        'monitor_id', 'www_redirect', 'https_redirect', 'trailing_slash_redirect',
        'robots_ok', 'sitemap_ok', 'interval', 'last_checked_at',
    ];

    protected $casts = [
        'robots_ok' => 'boolean',
        'sitemap_ok' => 'boolean',
        'interval' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    public function monitor(): BelongsTo;   // belongsTo(Monitor::class)
    public function logs(): HasMany;        // hasMany(SeoCheckLog::class)
}
```

### 4.3 `App\Models\SeoCheckLog`
```php
final class SeoCheckLog extends Model
{
    protected $fillable = [
        'seo_check_id', 'www_redirect', 'https_redirect', 'trailing_slash_redirect',
        'robots_ok', 'sitemap_ok', 'checked_at',
    ];
    protected $casts = [
        'robots_ok' => 'boolean',
        'sitemap_ok' => 'boolean',
        'checked_at' => 'datetime',
    ];
    public function seoCheck(): BelongsTo;  // belongsTo(SeoCheck::class)
}
```
(No `HasFactory` — mirrors `MonitorLog`, which has no factory; history rows are created via
`$seoCheck->logs()->create([...])` inside the service.)

### 4.4 `App\Models\Monitor` additions
```php
public function seoCheck(): HasOne;  // hasOne(SeoCheck::class)

protected static function booted(): void
{
    static::creating(function (Monitor $monitor) {
        $monitor->public_token = Str::random(32);   // existing, unchanged
    });

    static::created(function (Monitor $monitor) {    // NEW (D2)
        try {
            $monitor->seoCheck()->create([
                'interval' => 1440,
                'www_redirect' => SeoCheck::NONE,
                'https_redirect' => SeoCheck::NONE,
                'trailing_slash_redirect' => SeoCheck::NONE,
                'robots_ok' => false,
                'sitemap_ok' => false,
                'last_checked_at' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to create SeoCheck for Monitor {$monitor->id}: " . $e->getMessage());
        }
    });
}
```

### 4.5 `App\Services\SeoCheckService`
```php
final class SeoCheckService
{
    private const TIMEOUT = 10; // seconds per outbound request (bounded, spec: no hang)

    public function __construct(private readonly Client $client) {}

    public function check(SeoCheck $seoCheck): void;

    // Internal helpers (private):
    private function probeRedirects(string $url): array;        // ['www'=>, 'https'=>, 'trailing_slash'=>]
    private function classifyRedirect(string $from, string $to): array;
    private function checkRobots(string $origin): bool;         // GET /robots.txt === 200
    private function checkSitemap(string $origin): bool;        // sitemap.xml, fallback sitemap_index.xml
    private function originOf(string $url): string;             // scheme://host[:port]
    private function recordLog(SeoCheck $seoCheck, array $results): void; // dedup vs latest (D3)
}
```
Guzzle options for every request: `['allow_redirects' => false, 'http_errors' => false,
'timeout' => self::TIMEOUT, 'verify' => false]`. `verify => false` matches
`MonitoringService`'s option set. Each request wrapped in `try/catch (GuzzleException)` →
degrade to `none`/`false`.

### 4.6 `App\Jobs\CheckSeoJob`
```php
class CheckSeoJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;      // mirrors CheckMonitorJob
    public int $backoff = 10;   // mirrors CheckMonitorJob
    public function __construct(public SeoCheck $seoCheck) {}
    public function handle(SeoCheckService $service): void
    {
        $service->check($this->seoCheck);
    }
}
```

### 4.7 `App\Http\Controllers\SeoCheckController`
```php
class SeoCheckController extends Controller
{
    use AuthorizesRequests;

    // Scope via the shared monitor's ownership; eager-load seoCheck for the table.
    public function index(Request $request): View
    {
        $monitors = $request->user()->monitors()->with('seoCheck')->latest()->get();
        return view('seo.index', compact('monitors'));
    }

    public function create(): View
    {
        return view('seo.create');
    }

    public function store(StoreSeoCheckRequest $request): RedirectResponse
    {
        $request->user()->monitors()->create($request->validated() + [
            'interval' => 60,               // D10 pinned Monitor defaults
            'timeout' => 30,
            'expected_status_code' => 200,
        ]);
        // Monitor::created hook auto-creates the paired seo_checks row.
        return redirect()->route('seo.index')->with('success', 'URL añadida correctamente.');
    }

    public function recheck(SeoCheck $seoCheck): RedirectResponse
    {
        $this->authorize('update', $seoCheck);   // SeoCheckPolicy -> monitor->user_id
        CheckSeoJob::dispatchSync($seoCheck);     // D7 synchronous
        return redirect()->route('seo.index')->with('success', 'Verificación completada.');
    }
}
```
> Note: `index` passes `$monitors` (each with `->seoCheck`) rather than raw `SeoCheck`
> models, reusing the exact ownership scoping (`$request->user()->monitors()`) already proven
> in `MonitorController::index`. The Blade reads `$monitor->seoCheck` per row.

### 4.8 `App\Http\Requests\StoreSeoCheckRequest`
```php
class StoreSeoCheckRequest extends FormRequest
{
    public function authorize(): bool { return true; }  // ownership via user()->monitors()->create()
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'url'  => ['required', 'url', 'max:255', new NoPrivateUrl()],
        ];
    }
}
```

### 4.9 `App\Policies\SeoCheckPolicy` (auto-discovered: `SeoCheck` → `SeoCheckPolicy`)
```php
class SeoCheckPolicy
{
    public function viewAny(User $user): bool { return false; }             // mirrors MonitorPolicy (unused)
    public function view(User $user, SeoCheck $seoCheck): bool  { return $user->id === $seoCheck->monitor->user_id; }
    public function create(User $user): bool { return true; }
    public function update(User $user, SeoCheck $seoCheck): bool { return $user->id === $seoCheck->monitor->user_id; } // used by recheck
    public function delete(User $user, SeoCheck $seoCheck): bool { return $user->id === $seoCheck->monitor->user_id; }
}
```

### 4.10 Routes
`routes/web.php` — inside the existing `Route::middleware(['auth'])->group(...)`:
```php
Route::resource('seo', SeoCheckController::class)->only(['index', 'create', 'store']);
Route::post('seo/{seoCheck}/recheck', [SeoCheckController::class, 'recheck'])->name('seo.recheck');
```
Route-model binding: `{seoCheck}` resolves `App\Models\SeoCheck` by id. Names produced:
`seo.index`, `seo.create`, `seo.store`, `seo.recheck`.

`routes/console.php` — appended new block (independent of the Monitor block):
```php
Schedule::call(function () {
    SeoCheck::cursor()->each(function (SeoCheck $seoCheck) {
        if (!$seoCheck->last_checked_at || $seoCheck->last_checked_at->addMinutes($seoCheck->interval)->isPast()) {
            CheckSeoJob::dispatch($seoCheck);
        }
    });
})->everyMinute();
```

### 4.11 Blade

`resources/views/layouts/navigation.blade.php` — after the existing Monitor nav-link
(desktop, lines ~15-18) add:
```blade
<x-nav-link :href="route('seo.index')" :active="request()->routeIs('seo.*')">
    {{ __('SEO / Redirects') }}
</x-nav-link>
```
and after the mobile responsive Monitor link (lines ~70-72) the matching
`<x-responsive-nav-link>`. Existing Monitor markup untouched.

`resources/views/seo/index.blade.php` — `@extends('layouts.app')`, same header/table shell as
`monitors/index.blade.php`. Columns: **Nombre**, **URL**, **www**, **HTTPS**, **Trailing
slash**, **robots.txt**, **Sitemap**, **Última revisión**, **Acciones**. Per cell a pill using
the existing badge classes:
- OK / normalized (a non-`none` redirect value, or `robots_ok`/`sitemap_ok` true) →
  `bg-green-100 text-green-800`.
- failure (`robots_ok`/`sitemap_ok` false) → `bg-red-100 text-red-800`.
- `none` (redirect dimensions with no redirect) → `bg-gray-100 text-gray-800`.
Relative time: `{{ $monitor->seoCheck?->last_checked_at?->diffForHumans() ?? 'Nunca' }}`.
Re-check control: a small inline `<form method="POST" action="{{ route('seo.recheck', $monitor->seoCheck) }}">@csrf<button>Revisar</button></form>` mirroring the existing
delete-button-as-form pattern. Header actions: a "+ Añadir URL" link to `route('seo.create')`.

`resources/views/seo/create.blade.php` — `@extends('layouts.app')`, minimal form posting to
`route('seo.store')` with `@csrf`, only `name` + `url` fields, reusing the exact Tailwind input
classes, `@error()` messages, and `old()` repopulation from `monitors/create.blade.php`.
Cancel link back to `route('seo.index')`.

### 4.12 `database/factories/SeoCheckFactory` + observer interaction (important gotcha)
```php
class SeoCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'www_redirect' => SeoCheck::NONE,
            'https_redirect' => SeoCheck::NONE,
            'trailing_slash_redirect' => SeoCheck::NONE,
            'robots_ok' => false,
            'sitemap_ok' => false,
            'interval' => 1440,
            'last_checked_at' => null,
        ];
    }
    public function withWwwRedirect(string $direction = SeoCheck::WWW_TO_NO_WWW): static;
    public function withHttpsRedirect(string $direction = SeoCheck::HTTP_TO_HTTPS): static;
    public function withTrailingSlash(string $direction = SeoCheck::WITH_SLASH): static;
    public function robotsMissing(): static;   // ['robots_ok' => false]
    public function robotsOk(): static;         // ['robots_ok' => true]
    public function sitemapMissing(): static;
    public function sitemapOk(): static;
    public function checkedAt(?Carbon $when): static;
}
```
**Gotcha (documented):** because `Monitor::created` auto-inserts a `seo_checks` row and
`monitor_id` is UNIQUE, `SeoCheck::factory()->create()` with a fresh `Monitor::factory()`
would attempt a **duplicate** row and violate the unique constraint. Therefore the canonical
test setup is:
```php
$monitor = Monitor::factory()->for($user)->create();
$monitor->seoCheck->update(['www_redirect' => SeoCheck::WWW_TO_NO_WWW, 'robots_ok' => true, ...]);
```
The factory's `definition()`/states are still useful for building attribute arrays via
`->make()`/`->raw()` and for `HasFactory` compliance, but tests should prefer updating the
auto-created relation over calling `->create()` directly. This constraint is called out in the
test strategy and the risks section.

---

## 5. Test Strategy (TDD — `composer test` = `php artisan test`)

Framework note: this Laravel repo uses PHPUnit Feature tests (`Tests\Feature`,
`Illuminate\Foundation\Testing\RefreshDatabase`, `@group` tags) — the project's own convention,
which governs here (the user's global Drupal/DTT testing rule does not apply to this Laravel
project). Strict TDD: write failing tests first, then implement to green; final gate is
`composer test`.

### 5.1 `tests/Feature/SeoCheckServiceTest.php` — `@group seo-check-service`
Mirror `MonitoringServiceTest`'s Guzzle harness (`MockHandler` + `HandlerStack::create` +
`Middleware::history`), instantiating `new SeoCheckService(new Client(['handler' => $stack]))`
directly (not from the container). Setup helper builds a monitor + uses its auto-created
`seoCheck` (per the §4.12 gotcha).

Cases:
- **allow_redirects=false asserted** — push `Middleware::history`, run check, assert
  `$history[0]['options']['allow_redirects'] === false` and `http_errors === false` on every
  captured request.
- **Location parsing / vocab** (one test per dimension, using `new Response(301, ['Location' => ...])`):
  - `https://www.example.com/` → `Location: https://example.com/` ⇒ `www→no-www`.
  - `https://example.com/` → `Location: https://www.example.com/` ⇒ `no-www→www`.
  - `http://example.com/` → `Location: https://example.com/` ⇒ `http→https`.
  - `https://example.com/` → `Location: http://example.com/` ⇒ `https→http`.
  - `https://example.com/page` → `Location: https://example.com/page/` ⇒ `with /`.
  - `https://example.com/page/` → `Location: https://example.com/page` ⇒ `without /`.
  - 200 (no redirect) ⇒ all three `none`.
  - 3xx whose Location changes none of the three dimensions ⇒ all three `none`.
- **robots** — `robots.txt` 200 ⇒ `robots_ok true`; 404/500/exception ⇒ `false`.
- **sitemap fallback** — `sitemap.xml` 200 ⇒ `sitemap_ok true`, and assert **no**
  `sitemap_index.xml` request was made; `sitemap.xml` 404 + `sitemap_index.xml` 200 ⇒ `true`;
  both non-200 ⇒ `false`.
- **request count bounded (spec)** — common case asserts `assertCount(3, $history)` (probe +
  robots + sitemap.xml); worst case asserts `assertCount(4, $history)` (sitemap fallback);
  never one-request-per-dimension.
- **persistence** — after `check()`, `seo_checks` row has the five result columns updated and a
  non-null `last_checked_at`.
- **history dedup (D3)** — two identical result sets ⇒ 1 `seo_check_logs` row; a differing
  second result set ⇒ 2 rows (mirrors the Monitor dedup tests).
- **bounded timeout** — assert the `timeout` option is present/`self::TIMEOUT` on requests.

### 5.2 `tests/Feature/SeoCheckControllerTest.php` — `@group seo-check-controller`
Mirror `MonitorControllerTest`'s battery, using `RefreshDatabase`:
- **Guards**: guest redirected from `seo.index`, `seo.create`, `seo.store`, `seo.recheck`.
- **Index isolation**: user sees only URLs of monitors they own (`assertSee`/`assertDontSee`).
- **Store**: valid `name`+`url` creates one `monitors` row (`assertDatabaseHas`) AND exactly one
  paired `seo_checks` row; redirects to `seo.index`; pinned Monitor defaults applied
  (`interval=60`, `timeout=30`, `expected_status_code=200`).
- **Validation**: missing `name` ⇒ error; invalid `url` ⇒ error; private IP url
  (`http://192.168.1.1/`) ⇒ `NoPrivateUrl` error.
- **Recheck ownership**: owner recheck ⇒ redirect + flash; cross-user recheck ⇒
  `assertForbidden()`.
- **Recheck is synchronous** — with `QUEUE_CONNECTION` async in play, seed a `SeoCheckService`
  mock/bind a mock Guzzle client in the container so `dispatchSync` runs the probe inline, then
  assert `last_checked_at` is non-null immediately after the POST (no worker). Alternatively
  assert via `Bus::fake()` that the job was dispatched synchronously — prefer the state
  assertion (`last_checked_at` freshly set) to prove end-to-end sync behavior.

### 5.3 Model/observer test (pairing invariant)
A focused test (either in the controller suite or a small dedicated file) asserting:
- `Monitor::create(...)` ⇒ exactly one `seo_checks` row (`assertDatabaseCount('seo_checks', 1)`).
- Deleting the monitor cascade-removes its `seo_checks` and `seo_check_logs`
  (`assertDatabaseMissing`), mirroring `test_deleting_a_monitor_also_removes_its_logs`.
- Paired-row creation failure path is logged, not thrown (can be asserted by faking `Log` and
  forcing a failure, or documented as covered by the non-throwing `try/catch`).

### 5.4 No-regression gate
Existing `MonitoringServiceTest` and `MonitorControllerTest` MUST stay green. Watch two
interaction points: (a) tests that `Monitor::factory()->create()` now also create a
`seo_checks` row — this is additive and should not break existing assertions, but any test
using `assertDatabaseCount` on unrelated tables is unaffected; (b) the `seo_checks`/`seo_check_logs`
tables must exist under `RefreshDatabase` (the new migrations ensure this).

---

## 6. Rollout

1. **Merge order is a single change**; migrations run on deploy via the project's normal
   migrate step. New tables are additive — no downtime, no lock on `monitors`.
2. **Backfill** runs automatically as the `backfill_seo_checks_for_existing_monitors` migration
   immediately after the table/index migrations, so existing monitors gain their paired row in
   the same deploy. Chunked to avoid loading all monitors at once.
3. **Scheduler**: the new `Schedule::call(...)` block activates on deploy; with default
   `interval = 1440`, each backfilled/new entry runs at most daily. Because `last_checked_at`
   starts null, all entries become due on the first scheduler tick after deploy — acceptable
   given the daily cadence and the queued (non-blocking) dispatch, but note a one-time burst of
   queued `CheckSeoJob`s on first run (bounded by the number of monitors). If that burst is a
   concern at scale, a follow-up could stagger initial `last_checked_at`; out of scope here.
4. **Queue worker**: periodic checks require the existing `database` queue worker
   (`php artisan queue:listen`, already in the `composer dev` script). Manual re-check does NOT
   depend on the worker (synchronous, D7).
5. **Verification post-deploy**: SEO tab visible; adding a URL from either tab appears in both;
   a manual re-check updates the row immediately; `composer test` green in CI.

### Rollback
- Revert the change commit(s) and roll back the four new migrations. `down()` of the two
  `create_*` migrations drops `seo_checks` / `seo_check_logs` entirely (backfill `down()` is a
  no-op by design). `monitors` / `monitor_logs` data is untouched.
- Removing the `Monitor::created` hook + `seoCheck()` relation fully reverts Monitor to current
  behavior with no data loss. All other new files (service, job, controller, request, policy,
  views, factory, routes/nav edits) are additive and revert cleanly.

---

## 7. Tradeoffs

| Decision | Trade gained | Trade given up |
|----------|--------------|----------------|
| D4/D5 single probe (<=4 req) | 2-3x fewer outbound requests per cycle at scale | Cannot detect canonicalization of a *variant* the stored URL doesn't itself redirect to; only observes real redirect behavior (which is what a user/crawler sees) |
| D7 synchronous re-check | Immediate fresh results regardless of queue driver | Request duration bound to up to 4 sequential HTTP calls (mitigated by per-request `TIMEOUT`) |
| D8 minutes + `addMinutes` | Human-readable daily interval matching the spec | One-line divergence from Monitor's `addSeconds` scheduler (documented) |
| D9 plain strings + booleans | Parity with Monitor's `status` string; no new files; native `→` via utf8mb4 | Weaker compile-time typing than backed enums (mitigated by class constants) |
| D11 duplicated Blade views | Zero risk to working Monitor views | Some markup duplication (badge/input classes) vs a shared component |
| D2 `booted()` created hook | Single lifecycle location; non-throwing keeps Monitor creation safe | Introduces coupling: any future Monitor-creation path implicitly creates a `seo_checks` row; the factory/observer duplicate gotcha (§4.12) must be respected in tests |
| Two-table split (D3) | History available for future trend UI; matches Monitor pattern | Extra table + writes now, though history UI is out of scope for this change |
| Sequential requests (no Guzzle Pool) | Simpler, no async complexity in v1 | Higher per-entry latency; revisit parallelization only if daily cadence proves insufficient |
