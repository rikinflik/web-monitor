# Exploration: add-seo-redirects-tab

## 1. Monitor & MonitorLog models, migrations, factories

### Model: `app/Models/Monitor.php`
- Not `final` (existing project convention diverges from user's PHP rule of `final` on new concrete classes — this is a pre-existing class, do not retrofit unless asked).
- `fillable`: `name, url, interval, timeout, expected_status_code, keyword, status, last_checked_at, user_id, public_token, webhook_url, basic_auth_user, basic_auth_password` (lines 15-29).
- `hidden`: `basic_auth_password` (line 31-33).
- `casts`: `last_checked_at => datetime`, `basic_auth_password => encrypted`, `expected_status_code => integer` (lines 35-39).
- `booted()` auto-generates `public_token` via `Str::random(32)` on creation (lines 41-46) — used for the public status page.
- Relations: `user(): BelongsTo` (48-51), `logs(): HasMany` (53-56).

### Model: `app/Models/MonitorLog.php`
- `fillable`: `monitor_id, status, response_time, status_code, error_message, checked_at` (lines 11-18).
- `casts`: `checked_at => datetime`, `status_code => integer`, `response_time => integer` (20-24).
- Relation: `monitor(): BelongsTo` (26-29).
- No factory exists for `MonitorLog` (logs are created directly via `$monitor->logs()->create([...])` in tests and in `MonitoringService`).

### Migrations (in order)
1. `database/migrations/2026_03_04_094741_create_monitors_table.php` — base `monitors` table: `id, name, url, interval(int,60), timeout(int,30), expected_status_code(int,200), keyword(nullable string), status(string,'up'), last_checked_at(nullable timestamp), user_id(FK cascade), public_token(unique string), webhook_url(nullable string), timestamps`.
2. `database/migrations/2026_03_04_094743_create_monitor_logs_table.php` — base `monitor_logs`: `id, monitor_id(FK cascade), status, response_time(int), status_code(nullable int), checked_at(timestamp), timestamps`.
3. `database/migrations/2026_06_16_143618_add_basic_auth_to_monitors_table.php` — adds `basic_auth_user` (nullable string, after `webhook_url`) and `basic_auth_password` (nullable text, after `basic_auth_user`).
4. `database/migrations/2026_06_16_181601_add_error_message_to_monitor_logs_table.php` — adds `error_message` (nullable string, after `status_code`) to `monitor_logs`.
5. `database/migrations/2026_06_16_000001_add_performance_indexes.php` — composite index `[monitor_id, created_at]` on `monitor_logs`; single index on `monitors.last_checked_at`.

Pattern to follow for the new feature: additive migrations, one per logical change, `up()`/`down()` symmetric, indexes added in a dedicated migration when needed for query performance (e.g. `last_checked_at` for periodic-scan queries).

### Factory: `database/factories/MonitorFactory.php`
- Default state includes all Monitor fillable-ish fields except `public_token` (auto-generated) and `interval/timeout/status` defaults matching migration defaults.
- Named states: `down()`, `withKeyword(string)`, `withWebhook(string)`, `withBasicAuth(user, password)` — convenient chainable factory states; a new `SeoCheckFactory` (or similar) should follow this same state-based pattern (e.g. `withRedirect(...)`, `robotsMissing()`, etc.).
- No `MonitorLogFactory` exists — logs are created ad hoc; the new feature can decide whether to add a factory for its "check log"/result model or not, consistent with existing precedent of skipping it for the log table.

## 2. Check execution pipeline

### `app/Services/MonitoringService.php`
- Constructor-injected `GuzzleHttp\Client` (readonly-eligible, but currently declared `protected Client $client` without `readonly` — existing code predates the strict readonly rule; new services should use `private readonly` per project PHP rules).
- `check(Monitor $monitor): void` (lines 19-67) is the single entry point:
  - Builds Guzzle options: `timeout`, `http_errors => false`, `stream => true`, `verify => false`, optional `auth` for basic auth (lines 28-37).
  - Issues `GET` via `$this->client->get($monitor->url, $options)` (line 39).
  - Reads status code, computes response time, reads capped body (`MAX_BODY_BYTES = 524288`) (lines 41-43).
  - Determines `up`/`down` from expected status code + optional keyword match, builds Catalan-language `$errorMessage` on failure (lines 45-54).
  - Catches `GuzzleException`, maps to a friendly Catalan error message via `parseGuzzleError()` (lines 55-59, 69-87) — messages are in Catalan (`"Timeout: el servidor no va respondre a temps"`, etc.) even though UI text elsewhere is Spanish (`Mis Monitores`, `Añadir Monitor`). **Note the language inconsistency**: error messages are Catalan, view labels are Spanish. New SEO check messages/labels should pick one convention consistent with immediate surrounding code or ask; default to matching the Monitor tab's Spanish view labels for UI text, and can reuse Catalan or English for internal log messages — this ambiguity should be resolved explicitly in the proposal.
  - Deduplicates log entries: only inserts a new `MonitorLog` row if status or status_code differs from the latest log (lines 61-64, `recordLog()` at 89-98).
  - Always updates `Monitor.status` and `Monitor.last_checked_at` (`updateStatus()`, lines 100-112), and on status *change* triggers notification (`notifyStatusChange`, 114-117) and webhook (`triggerWebhook`, 119-135).
- For the new SEO checks, redirects must be detected with `allow_redirects => false` and reading the `Location` header — this is a **new** Guzzle option pattern not currently used anywhere in the codebase (current `MonitoringService` does not set `allow_redirects` at all, meaning Guzzle's default of following redirects applies for the uptime check — that's intentionally different from the new SEO check's needs). A new `SeoCheckService` (or similarly named class) should be added rather than overloading `MonitoringService`, to keep responsibilities separated, but can share the injected `Client`.

### `app/Jobs/CheckMonitorJob.php`
- `ShouldQueue`, `Queueable` trait, `tries = 3`, `backoff = 10` (lines 11-16).
- Constructor takes `public Monitor $monitor` (21-24); `handle(MonitoringService $service)` delegates to `$service->check($this->monitor)` (29-32).
- Pattern to mirror: a `CheckSeoJob` (or `CheckSeoEntryJob`) taking the new model instance, resolving a new `SeoCheckService` via DI in `handle()`.

### Scheduler: `routes/console.php`
- `Schedule::call(...)->everyMinute()` (lines 10-16) iterates `Monitor::cursor()` and dispatches `CheckMonitorJob::dispatch($monitor)` when `last_checked_at` is null or the interval has elapsed.
- No separate artisan command exists for this — the schedule closure directly queries and dispatches. The new SEO tab should add an analogous `Schedule::call(...)->everyMinute()` block (or the same block with an added loop) iterating the new SEO entry model and dispatching `CheckSeoJob`. Given the SEO checks are described as fewer state values than the uptime check and are periodic like Monitor, reusing the `interval`/`last_checked_at` field names/semantics on the new model is recommended for consistency.
- No queue-specific config found in this exploration pass beyond `composer.json` `dev` script which runs `php artisan queue:listen --tries=1 --timeout=0` locally; queue connection driver is set via `.env`/`QUEUE_CONNECTION` (present in `.env.example`, not read in detail — default Laravel `database` or `sync` likely; verify via `.env` before assuming behavior, since `sync` queue would run checks inline instead of async).

## 3. Blade rendering: monitor list, tabs, badges, relative time

### Navigation / tabs: `resources/views/layouts/navigation.blade.php`
- Single tab today: `Monitors` (both desktop nav lines 15-18 and mobile nav lines 70-72), using `<x-nav-link :href="route('monitors.index')" :active="request()->routeIs('monitors.*')">`.
- No Livewire/Alpine tab component beyond the existing Breeze-provided `x-dropdown`/hamburger `x-data="{ open: false }"` (Alpine is already present globally via Breeze's stack, but is only used for the nav dropdown/hamburger — not for tab switching). Adding the "SEO / Redirects" tab is a second `<x-nav-link>`/`<x-responsive-nav-link>` entry pointing at a new named route (e.g. `route('seo.index')`), mirroring lines 15-18 and 70-72 exactly, using `request()->routeIs('seo.*')` for active state. This requires no new Alpine/Livewire usage — plain link-based tab, consistent with the "Pure Blade" requirement.

### List view: `resources/views/monitors/index.blade.php`
- `@extends('layouts.app')` / `@section('content')` wrapper pattern (line 1, 3).
- Table structure: `min-w-full divide-y divide-gray-200`, `thead bg-gray-50`, header cells `px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider` (lines 18-27).
- Status badge pattern (lines 34-38): `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">UP</span>` vs `bg-red-100 text-red-800` for DOWN — this exact badge class pattern (rounded-full pill, green/red bg+text pairs) should be reused for each SEO check cell, with a plausible third "neutral/none" state using e.g. `bg-gray-100 text-gray-800` for "none"/N-A values (www none, https none, trailing-slash none).
- Relative time: `{{ $monitor->last_checked_at?->diffForHumans() ?? 'Nunca' }}` (line 41) — reuse this exact `diffForHumans()` call (Carbon, already available via the `datetime` cast) for the new "checked X ago" column; fallback text should match the Spanish UI convention already used here (`'Nunca'`) or an English equivalent depending on final language decision (see ambiguity note below).
- Row actions: view/edit/delete links + a delete confirm form (44-50); "Refresh" is just a link back to `index` (no explicit manual re-check trigger — see section 6).

### Detail view: `resources/views/monitors/show.blade.php`
- Shows stat cards, log history table with status badges reusing the same green/red pill pattern (lines 57-61) and `checked_at->format('d/m/Y H:i:s')` for the log history (absolute time, not relative) — the new SEO tab's per-URL history (if added) could mirror this, though the task only requires a list table, not necessarily a per-entry detail/history page (should be confirmed/scoped in the proposal — is a per-entry "show" page in scope, or just the list table with re-run button?).

### Layout wrapper: `resources/views/layouts/app.blade.php` — not fully read in this pass but is the standard Breeze `app` layout wrapping `navigation` partial + `$slot`/`@yield('content')`. New SEO views should `@extends('layouts.app')` identically.

## 4. Add-new-monitor form: route → controller → validation → store → redirect

- Route: `Route::resource('monitors', MonitorController::class)` inside `auth` middleware group (`routes/web.php` line 13) — gives `monitors.index/create/store/show/edit/update/destroy` names.
- Controller: `app/Http/Controllers/MonitorController.php`:
  - `create()` (24-27) returns `view('monitors.create')` with no data.
  - `store(StoreMonitorRequest $request)` (29-33): `$request->user()->monitors()->create($request->validated())`, then redirect to `monitors.index` with flash `success`.
- Form Request: `app/Http/Requests/StoreMonitorRequest.php` — `authorize()` returns `true` (any authenticated user may create own monitor; ownership is implicit via `$request->user()->monitors()->create()`). `rules()` (18-31): `name` required string max 255; `url` required|url|max:255 + custom `NoPrivateUrl` rule (SSRF guard); `interval` required integer min 60; `timeout` required integer 1-60; `expected_status_code` required integer 100-599; `keyword` nullable; `webhook_url` nullable url + `NoPrivateUrl`; `basic_auth_user`/`basic_auth_password` nullable strings.
- Blade form: `resources/views/monitors/create.blade.php` — plain HTML `<form method="POST" action="{{ route('monitors.store') }}">` with `@csrf`, Tailwind-styled inputs, `@error()` inline validation messages, `old()` value repopulation. **This is a plain Blade view, not an extracted Blade component** — "reuse/adapt the component" in the task description is aspirational; there is no existing `<x-monitor-form>` component to reuse verbatim. The proposal must decide whether to (a) extract a shared Blade component (`<x-url-check-form>`) used by both Monitor and SEO create forms, refactoring the existing `monitors/create.blade.php` to use it too (bigger, riskier change touching existing feature), or (b) simply duplicate/adapt the relevant fields (name + url [+ interval]) into a new `seo/create.blade.php` without refactoring Monitor's form (lower risk, less DRY). This is a key decision the proposal should make explicit.
- Policy: `app/Policies/MonitorPolicy.php` — `create()` returns `true` for any authenticated user (line 27-30); `view/update/delete` check `$user->id === $monitor->user_id` (lines 22-25, 32-35, 37-40); `viewAny` returns `false` (14-17, unused since index is scoped manually in controller, not via policy).
- No explicit `authorize()`/Policy registration snippet found for `MonitorPolicy` binding (likely auto-discovered by Laravel's naming convention `Monitor` → `MonitorPolicy`); a new `SeoCheck` model would need an analogous `SeoCheckPolicy` auto-discovered the same way, or explicit registration in a service provider if auto-discovery fails (verify during implementation).

## 5. Directory structure relevant to this change

```
app/Models/Monitor.php, MonitorLog.php          -> new: SeoCheck.php (or SeoUrl.php), SeoCheckResult.php (or similar)
app/Services/MonitoringService.php               -> new: SeoCheckService.php
app/Jobs/CheckMonitorJob.php                      -> new: CheckSeoJob.php
app/Http/Controllers/MonitorController.php        -> new: SeoCheckController.php
app/Http/Requests/StoreMonitorRequest.php,
                    UpdateMonitorRequest.php       -> new: StoreSeoCheckRequest.php (+Update if edit is in scope)
app/Policies/MonitorPolicy.php                    -> new: SeoCheckPolicy.php
database/migrations/                              -> new: create_seo_checks_table, create_seo_check_results_table (or single wide table, see open question)
database/factories/MonitorFactory.php              -> new: SeoCheckFactory.php
resources/views/monitors/{index,create,edit,show}.blade.php -> new: resources/views/seo/{index,create}.blade.php (+edit/show if scoped)
resources/views/layouts/navigation.blade.php       -> edit in place: add second nav-link tab
routes/web.php                                     -> edit in place: add Route::resource('seo', SeoCheckController::class) inside existing auth group
routes/console.php                                 -> edit in place: add scheduling for SEO checks (new Schedule::call or extend existing)
tests/Feature/MonitoringServiceTest.php,
              MonitorControllerTest.php             -> new: tests/Feature/SeoCheckServiceTest.php, SeoCheckControllerTest.php
```

## 6. Manual re-check for an existing monitor

- **No manual re-check route/button currently exists** for Monitor. The "Refresh" link on `monitors/index.blade.php` (line 8-10) is just a GET link back to `monitors.index` (re-renders the list from DB state) — it does **not** trigger a live re-check; it relies on the periodic scheduler having already run and updated `last_checked_at`/`status`.
- Since the task requires the new SEO checks to be "manually re-runnable per entry," this is a **new capability not present anywhere in the app** (not just adapted from Monitor). The proposal must design this from scratch: likely a `POST /seo/{seoCheck}/recheck` route dispatching `CheckSeoJob::dispatch($seoCheck)` synchronously or via queue, with a button/form per row (mirroring the existing delete-button-as-form pattern at `monitors/index.blade.php` lines 46-50), then redirecting back to `seo.index` with a flash message. Consider whether this should also dispatch synchronously (`CheckSeoJob::dispatchSync()`) so the UI shows fresh results immediately after the redirect, since queue driver may be async (`sync` vs `database` — confirm `.env` `QUEUE_CONNECTION`).

## 7. Existing test patterns

- Location: `tests/Feature/*.php`, `tests/Unit/*.php`; namespace `Tests\Feature` / `Tests\Unit`.
- All feature tests use `use Illuminate\Foundation\Testing\RefreshDatabase;` trait + `use Tests\TestCase;`.
- `@group` PHPDoc annotations per class, e.g. `@group monitoring-service` (`MonitoringServiceTest.php` line 22), `@group monitor-controller` (`MonitorControllerTest.php` line 11) — new tests should use `@group seo-check-service`, `@group seo-check-controller` etc.
- Guzzle mocking pattern (`MonitoringServiceTest.php` lines 28-44): `GuzzleHttp\Handler\MockHandler` + `HandlerStack::create($mock)`, injected into a fresh `new MonitoringService(new Client(['handler' => $stack]))` per test (service is NOT resolved from the container in tests — instantiated directly with a mock client). For request-inspection assertions (e.g. verifying `allow_redirects=false` was set, or asserting on the `Location` header being read), tests push `Middleware::history($history)` onto the stack (lines 34-39) and then inspect `$history[0]['request']` / can also inspect `$history[0]['options']` to assert Guzzle request options like `allow_redirects`.
- `GuzzleHttp\Psr7\Response` used to build mock HTTP responses including headers, e.g. `new Response(301, ['Location' => 'https://example.com/'])` would be the pattern for redirect-check tests.
- `Http::fake()` (Laravel's `Illuminate\Support\Facades\Http`) is used only for the outbound webhook call in `MonitoringService::triggerWebhook` (uses the `Http` facade, not the injected Guzzle `Client`) — the new SEO checks should decide consistently whether to use the injected Guzzle `Client` (mockable via `MockHandler`, consistent with `MonitoringService`) for the 5 outbound checks; recommended to reuse the same injected `Client` approach for consistency and testability.
- Controller test pattern (`MonitorControllerTest.php`): guards tested per-route (guest redirect to login), ownership isolation (`test_index_shows_only_the_authenticated_users_monitors`), request validation edge cases via `assertSessionHasErrors`, authorization via `assertForbidden()` for cross-user access, and cascade-delete assertions (`assertDatabaseMissing`). New `SeoCheckControllerTest` should mirror this full battery.

## 8. Auth / roles

- All Monitor routes wrapped in `Route::middleware(['auth'])->group(...)` (`routes/web.php` lines 12-17) — no role-based restriction on Monitor CRUD; any authenticated user manages only their own monitors (ownership enforced via `user_id` + Policy, not roles).
- `User` model (`app/Models/User.php`) has `ROLE_ADMIN`/`ROLE_USER` constants (lines 14-15) and a `role` fillable column (line 34), used only for `canAccessPanel()` Filament admin panel gating (lines 20-23) — i.e. roles currently only gate the separate Filament `/admin` panel (`app/Providers/Filament/AdminPanelProvider.php`, `app/Filament/Resources/UserResource.php`), not the Monitor feature itself.
- Public status page (`PublicStatusController` + `routes/web.php` line 19-21) is unauthenticated but throttled (`throttle:30,1`), keyed by `public_token` — Monitor model auto-generates this on creation. Decide whether SEO checks need an equivalent public page (not mentioned in task — likely out of scope, but note the `public_token` pattern exists if ever needed).
- New SEO routes should be added inside the same `Route::middleware(['auth'])->group(...)` block, ownership-scoped identically to Monitor (`user_id` FK + Policy), no new role required per current task description.

## 9. Prior art, risks, dependencies, ambiguities

### Prior art to reuse directly
- `NoPrivateUrl` validation rule (`app/Rules/NoPrivateUrl.php`) — SSRF guard, should be reused verbatim on the new SEO entry's `url` field.
- Guzzle `Client` DI pattern from `MonitoringService` for testability via `MockHandler`.
- Badge/pill CSS classes and `diffForHumans()` relative-time pattern from `monitors/index.blade.php`.
- Migration style: small, additive, timestamped migrations; index migration separated.
- Factory state pattern (`down()`, `withKeyword()`, etc. as chainable named states).

### Risks
- **Language inconsistency** already exists in the codebase (Catalan error messages in `MonitoringService`, Spanish UI labels in Blade views, English/Catalan mixed in docblocks/tests) — new code must pick a consistent language per surface and the proposal should state which, to avoid compounding inconsistency.
- **Guzzle default redirect-following** in the existing `MonitoringService::check()` has no explicit `allow_redirects` option (Guzzle default is `true`, following redirects transparently) — this must NOT be touched/changed since it could alter existing Monitor behavior (e.g. change effective final status code checked). The new SEO service must use its own Guzzle calls with `allow_redirects => false` explicitly, isolated from `MonitoringService`.
- **No existing manual re-check mechanism** anywhere in the app (see section 6) — this is new plumbing, not just a mirror of existing functionality; must design route naming, HTTP verb (POST), sync vs queued dispatch, and UI feedback (flash message / redirect) from scratch.
- **Determining www/https/trailing-slash outcomes requires potentially 3 separate outbound requests per check cycle** (one for www variant, one for the given URL's scheme variant, one for trailing slash variant) plus robots.txt and sitemap checks (2 more requests, sitemap needs 2 possible paths) — up to 5-7 HTTP requests per periodic check per entry. This has cost/latency/rate implications not present in the single-request Monitor check; proposal should address timeout budgeting, whether checks run in parallel (Guzzena `Pool`/async) or sequentially, and how `interval` scheduling interacts with potentially longer per-entry check duration.
- **Determining canonical URL normalization**: given a stored URL, need consistent logic to derive the "www toggle" and "scheme toggle" and "trailing-slash toggle" variants to probe (e.g. is `example.com` stored, or `https://example.com`? does the entry's own URL already contain `www.` or not, `/` or not, `http` or `https`?). This normalization logic doesn't exist yet in the codebase (`Monitor.url` is used as-is, no host/scheme parsing) — new logic needed, likely a small value object or static helper.
- Filament admin panel (`app/Providers/Filament/AdminPanelProvider.php`, `UserResource`) is unrelated to Monitor/SEO features directly but shares the `User` model — no impact expected but should not be touched.
- `MonitorPolicy::viewAny()` always returns `false` and is effectively unused (index route doesn't call `$this->authorize('viewAny', ...)`, controller scopes manually via `$request->user()->monitors()`) — mirror this same manual-scoping-in-controller approach for `SeoCheckController::index()` rather than relying on `viewAny` policy, to stay consistent, though this leaves `viewAny` policies as dead code convention-wise (pre-existing pattern, not to fix here).

### Dependencies
- `guzzlehttp/guzzle` — not a direct `composer.json` dependency but transitively required by `laravel/framework` (Laravel's `Http` facade and any direct `GuzzleHttp\Client` usage depend on it being present via Laravel's own composer constraints); confirm `composer.json`/`composer.lock` pins a version compatible with `allow_redirects: false` option (standard Guzzle option, available in all Guzzle 6/7 versions, no risk).
- No new package installation appears necessary — robots.txt/sitemap checks are plain GET requests; redirect/Location header detection is a stock Guzzle option.

### Open questions the proposal must resolve
1. Exact new table/model design: one row per URL with 5 result columns + `last_checked_at` (mirroring Monitor's single-row-with-status-columns approach), versus a Monitor-style two-table split (`seo_checks` + `seo_check_logs` history). Given the task says "store results in the DB with a last-checked timestamp" (singular, not history), a single wide table (`seo_checks`: id, user_id, name, url, www_redirect, https_redirect, trailing_slash_redirect, robots_ok, sitemap_ok, last_checked_at, timestamps) is likely sufficient and simpler — recommend this over a Monitor/MonitorLog-style two-table split unless history/trend is explicitly desired.
2. Whether a per-entry "show" page (detail view, like `monitors/show.blade.php`) is in scope, or only the list table + manual re-check button (task text describes only "a table," suggesting show page is out of scope).
3. Whether editing an existing SEO entry (URL/name) is in scope (task doesn't mention an edit form, only "add" and "re-run").
4. Whether to extract a shared Blade component for the add-URL form (bigger refactor touching Monitor's existing `create.blade.php`) or duplicate/adapt a minimal subset of fields into a new form (lower risk) — task says "reuse/adapt the component" but no such component currently exists; needs explicit decision.
5. Language/locale for new UI strings and internal messages (Spanish view labels vs Catalan service messages currently coexist).
6. Confirm `.env` `QUEUE_CONNECTION` value to decide whether manual re-check should force `dispatchSync()` for immediate feedback vs relying on the queue worker.
