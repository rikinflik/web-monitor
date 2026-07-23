# Tasks: Add SEO / Redirects Tab

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,400-1,500 (see per-slice breakdown below) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 → PR 5 (stacked-to-main, each independently mergeable) |
| Delivery strategy | auto-chain (`chainedPrStrategy: force-chained` supplied by project config) |
| Chain strategy | stacked-to-main |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

### Per-slice line estimate

| Slice / PR | Files | Est. lines | Risk |
|---|---|---|---|
| PR 1 — Schema & pairing invariant | 4 migrations, `SeoCheck`, `SeoCheckLog`, `SeoCheckFactory`, `Monitor.php` edit, pairing test | ~390 | Medium |
| PR 2 — Redirect probe & classification | `SeoCheckService` (partial: probe/classify), `SeoCheckServiceTest` (probe/vocab cases) | ~300 | Low |
| PR 3 — Robots/sitemap/dedup + job | `SeoCheckService` completion, `CheckSeoJob`, `SeoCheckServiceTest` additions | ~270 | Low |
| PR 4 — Controller, request, policy, routes, scheduler | `SeoCheckController`, `StoreSeoCheckRequest`, `SeoCheckPolicy`, `routes/web.php`, `routes/console.php`, `SeoCheckControllerTest` | ~380 | Medium |
| PR 5 — Views, nav, final regression pass | `seo/index.blade.php`, `seo/create.blade.php`, `navigation.blade.php` edit, full-suite verification | ~155 | Low |
| **Total** | | **~1,495** | **High (aggregate)** |

Each slice is additive/isolated (new files, or edits guarded behind not-yet-wired routes) and does not alter existing Monitor behavior, so it can land on `main` independently before the next slice starts — hence **stacked-to-main** rather than a feature-branch tracker. PR 4 is the first slice that exposes user-facing routes; PR 5 is the first that exposes the nav tab, so the feature only becomes visible to end users once PR 5 lands, even though PRs 1-3 are mergeable earlier.

---

## PR 1 — Schema & Pairing Invariant

**Start state:** only `monitors`/`monitor_logs` tables and `Monitor` model exist.
**Finish state:** `seo_checks`/`seo_check_logs` tables exist (empty until later PRs write to them), every `Monitor::create()` call produces exactly one paired `seo_checks` row, existing monitors are backfilled, and this is proven by a dedicated test. No route, service, or UI references these tables yet.
**Rollback:** drop the 4 new migrations (`down()`), revert `Monitor.php`'s `created` hook and `seoCheck()` relation, delete the new model/factory/test files. Zero impact on `monitors`/`monitor_logs` data.

- [x] **RED**: Add `tests/Feature/SeoCheckPairingTest.php` (`@group seo-check-pairing`), `use RefreshDatabase`, asserting (all will fail — tables/relation don't exist yet):
  - `Monitor::factory()->create()` results in `assertDatabaseCount('seo_checks', 1)` with `monitor_id` matching.
  - The created `seo_checks` row has default values: `interval=1440`, `www_redirect='none'`, `https_redirect='none'`, `trailing_slash_redirect='none'`, `robots_ok=false`, `sitemap_ok=false`, `last_checked_at=null`.
  - Deleting the monitor cascade-removes its `seo_checks` row (`assertDatabaseMissing`) and any `seo_check_logs` rows for it.
  - A backfilled pre-existing monitor (insert a raw `monitors` row via `DB::table('monitors')->insert()` bypassing the model event, then run migrations/seed the backfill) ends up with exactly one `seo_checks` row.
- [x] **GREEN**: `database/migrations/*_create_seo_checks_table.php` — `id`, `monitor_id` (`foreignId->constrained()->cascadeOnDelete()->unique()`), `www_redirect`/`https_redirect`/`trailing_slash_redirect` (`string`, default `'none'`), `robots_ok`/`sitemap_ok` (`boolean`, default `false`), `interval` (`integer`, default `1440`), `last_checked_at` (`timestamp` nullable), `timestamps()`. Symmetric `down()` drops the table.
- [x] **GREEN**: `database/migrations/*_create_seo_check_logs_table.php` — `id`, `seo_check_id` (`foreignId->constrained()->cascadeOnDelete()`), the 5 result columns (same types as above, no defaults needed), `checked_at` (`timestamp`), `timestamps()`. Symmetric `down()` drops the table. Timestamp filename sorts after the `seo_checks` migration.
- [x] **GREEN**: `database/migrations/*_add_seo_checks_indexes.php` — index `seo_checks.last_checked_at`; composite index `seo_check_logs(seo_check_id, created_at)`. Symmetric `down()` drops both indexes.
- [x] **GREEN**: `app/Models/SeoCheck.php` — `final class SeoCheck extends Model` with `HasFactory`; vocabulary constants (`WWW_TO_NO_WWW`, `NO_WWW_TO_WWW`, `HTTP_TO_HTTPS`, `HTTPS_TO_HTTP`, `WITH_SLASH`, `WITHOUT_SLASH`, `NONE`); `$fillable` per design §4.2; casts (`robots_ok`/`sitemap_ok` boolean, `interval` integer, `last_checked_at` datetime); `monitor(): BelongsTo`; `logs(): HasMany`. Drupal-style docblock on the class.
- [x] **GREEN**: `app/Models/SeoCheckLog.php` — `final class SeoCheckLog extends Model` (no `HasFactory`, mirrors `MonitorLog`); `$fillable`/casts per design §4.3; `seoCheck(): BelongsTo`.
- [x] **GREEN**: `app/Models/Monitor.php` — add `seoCheck(): HasOne` (`hasOne(SeoCheck::class)`); extend the existing `booted()` with a `static::created(...)` closure that calls `$monitor->seoCheck()->create([...defaults...])` wrapped in `try/catch (\Throwable $e)` logging via `Log::error(...)` and continuing (no re-throw). Do not touch the existing `static::creating(...)` closure.
- [x] **GREEN**: `database/migrations/*_backfill_seo_checks_for_existing_monitors.php` — `up()` inserts one `seo_checks` row (defaults per above) for every `monitors` row lacking one, via a chunked `Monitor::whereDoesntHave('seoCheck')->chunkById(...)` or raw insert-select; `down()` is a documented no-op (comment explaining the table-drop in the sibling migration's `down()` already removes all rows).
- [x] **GREEN**: run `ddev exec vendor/bin/phpunit tests/Feature/SeoCheckPairingTest.php` (or `composer test -- --filter=seo-check-pairing` per project's actual runner) and confirm green.
- [x] **TRIANGULATE**: add a case to `SeoCheckPairingTest.php` proving the non-throwing contract — e.g. force `$monitor->seoCheck()` to fail (mock/partial or a monitor id collision scenario) and assert `Monitor::create()` still succeeds and returns a persisted `Monitor` (use `Log::spy()`/`Log::shouldReceive('error')` to assert the failure was logged rather than thrown).
- [x] **REFACTOR**: review `Monitor.php`'s `booted()` for clarity (extract the default-attributes array to a small private static method if it improves readability); confirm `phpcs.xml` rules pass on all new/changed files (`ddev phpcs`).
- [x] **VERIFY**: `composer test` full suite green (including pre-existing `MonitoringServiceTest`/`MonitorControllerTest` — zero regressions).

---

## PR 2 — Redirect Probe & Classification (SeoCheckService, part 1)

**Depends on:** PR 1 merged (needs `seo_checks` table + `SeoCheck` model).
**Start state:** `SeoCheck`/`SeoCheckLog` models and tables exist; no service exists yet.
**Finish state:** `SeoCheckService::check()` exists and correctly resolves www/https/trailing-slash from a single no-follow probe request, persisting those 3 columns; robots/sitemap are not yet implemented (deferred to PR 3) — `check()` may leave `robots_ok`/`sitemap_ok` at their existing/default values in this slice, documented in the PR description as an intentional interim state.
**Rollback:** delete `app/Services/SeoCheckService.php` and `tests/Feature/SeoCheckServiceTest.php`; no other file depends on this yet (not wired into any job/controller/route).

- [x] **RED**: Create `tests/Feature/SeoCheckServiceTest.php` (`@group seo-check-service`), mirroring `MonitoringServiceTest`'s Guzzle harness (`MockHandler` + `HandlerStack::create` + `Middleware::history`), with a `makeService(MockHandler $mock)` / `makeServiceWithHistory(...)` helper instantiating `new SeoCheckService(new Client(['handler' => $stack]))` directly. Setup helper: `Monitor::factory()->create()` then use its auto-created `$monitor->seoCheck` (per design §4.12 gotcha — never call `SeoCheck::factory()->create()` directly against a fresh monitor). Write these failing cases:
  - `allow_redirects=false` and `http_errors=false` asserted on the captured probe request via `Middleware::history`.
  - `https://www.example.com/` → `Location: https://example.com/` (301) ⇒ `www_redirect === SeoCheck::WWW_TO_NO_WWW`.
  - `https://example.com/` → `Location: https://www.example.com/` ⇒ `NO_WWW_TO_WWW`.
  - `http://example.com/` → `Location: https://example.com/` ⇒ `HTTP_TO_HTTPS`.
  - `https://example.com/` → `Location: http://example.com/` ⇒ `HTTPS_TO_HTTP`.
  - `https://example.com/page` → `Location: https://example.com/page/` ⇒ `WITH_SLASH`.
  - `https://example.com/page/` → `Location: https://example.com/page` ⇒ `WITHOUT_SLASH`.
  - 200 response (no redirect) ⇒ all three `NONE`.
  - 3xx whose `Location` changes none of the three dimensions ⇒ all three `NONE`.
  - persistence: after `check()`, the `seo_checks` row's 3 redirect columns reflect the computed values.
- [x] **GREEN**: `app/Services/SeoCheckService.php` — `final class SeoCheckService` with `private readonly Client $client` constructor injection; `private const TIMEOUT = 10`; public `check(SeoCheck $seoCheck): void` that: resolves `$url = $seoCheck->monitor->url`; issues the probe `GET $url` with `['allow_redirects' => false, 'http_errors' => false, 'timeout' => self::TIMEOUT, 'verify' => false]`; if 3xx + `Location` header present, calls `classifyRedirect()`; else defaults all three to `SeoCheck::NONE`; updates the `seo_checks` row's 3 redirect columns (robots/sitemap left as-is in this slice — do not overwrite them yet, or set explicit TODO-free stub calls to be completed in PR 3, whichever keeps the class compiling cleanly without dead code). Private `classifyRedirect(string $from, string $to): array` per design §2.4 (diff scheme/host/path). Private `originOf(string $url): string`. Drupal-style docblocks on the class and each non-trivial private method.
- [x] **GREEN**: run the new test file and confirm all cases pass.
- [x] **TRIANGULATE**: add edge-case tests — a 3xx response with no `Location` header (⇒ all three `NONE`, no exception); a `GuzzleException`/connection error on the probe request (⇒ all three degrade to `NONE`, no exception propagates); a URL with a non-standard port preserved through classification.
- [x] **REFACTOR**: confirm `classifyRedirect()` has no duplicated scheme/host/path parsing logic vs `originOf()`; extract a shared `parse_url` helper if warranted. `ddev phpcs` clean.
- [x] **VERIFY**: `composer test --filter=seo-check-service` (or equivalent) green; full suite still green.

---

## PR 3 — Robots/Sitemap/Dedup Completion + CheckSeoJob

**Depends on:** PR 2 merged (extends the same `SeoCheckService::check()` method).
**Start state:** `SeoCheckService` resolves only the 3 redirect columns.
**Finish state:** `SeoCheckService::check()` fully implements all 5 checks (adds `robots_ok`/`sitemap_ok`), writes deduplicated `seo_check_logs` history rows, and updates `last_checked_at`; `CheckSeoJob` exists so the service is queue-dispatchable (still not wired into any route/schedule until PR 4).
**Rollback:** revert `SeoCheckService.php` to its PR-2 state, delete `app/Jobs/CheckSeoJob.php` and the added test cases; no route/schedule references `CheckSeoJob` yet.

- [x] **RED**: extend `tests/Feature/SeoCheckServiceTest.php` with failing cases:
  - `robots.txt` returns 200 ⇒ `robots_ok === true`; returns 404/500 ⇒ `false`; throws a Guzzle exception ⇒ `false` (no exception propagates).
  - `sitemap.xml` returns 200 ⇒ `sitemap_ok === true` AND assert (via request history) that **no** `sitemap_index.xml` request was made.
  - `sitemap.xml` non-200 + `sitemap_index.xml` 200 ⇒ `sitemap_ok === true`.
  - both sitemap URLs non-200 ⇒ `sitemap_ok === false`.
  - **request count bounded**: common case (`sitemap.xml` 200) ⇒ `assertCount(3, $history)` (probe + robots + sitemap.xml); worst case (fallback needed) ⇒ `assertCount(4, $history)`.
  - **history dedup**: two consecutive `check()` calls producing identical result sets ⇒ `assertEquals(1, $seoCheck->logs()->count())`; a third call with a differing result (e.g. toggle `robots_ok`) ⇒ `assertEquals(2, ...)`.
  - `last_checked_at` is non-null and fresh after `check()`.
  - every captured request in `Middleware::history` carries `timeout === SeoCheckService::TIMEOUT` (or the equivalent bounded-timeout assertion).
- [x] **GREEN**: complete `app/Services/SeoCheckService.php` — private `checkRobots(string $origin): bool` (`GET {origin}/robots.txt`, same Guzzle options, `try/catch` degrades to `false`); private `checkSitemap(string $origin): bool` (`GET {origin}/sitemap.xml`, short-circuit on 200, else one fallback `GET {origin}/sitemap_index.xml`); private `recordLog(SeoCheck $seoCheck, array $results): void` comparing against `$seoCheck->logs()->latest('checked_at')->first()` and inserting only on a diff across the 5 values (mirrors `MonitoringService::recordLog()`); wire all of the above into `check()` alongside the PR-2 redirect logic, then persist the full 5-column update + `last_checked_at = now()`.
- [x] **GREEN**: `app/Jobs/CheckSeoJob.php` — `class CheckSeoJob implements ShouldQueue`, `use Queueable`; `public int $tries = 3; public int $backoff = 10;` (mirrors `CheckMonitorJob`); constructor `public SeoCheck $seoCheck`; `handle(SeoCheckService $service): void { $service->check($this->seoCheck); }`. Docblock explaining sync vs. queued dispatch usage (forward-reference to PR 4's controller/scheduler).
- [x] **GREEN**: run full `SeoCheckServiceTest` suite, confirm green.
- [x] **TRIANGULATE**: add a case asserting the full outbound request sequence/order for one end-to-end `check()` call (probe → robots → sitemap[.xml] → optional sitemap_index) using `Middleware::history` to assert both count and URL order, guarding against a future regression that reorders or duplicates requests.
- [x] **REFACTOR**: review `SeoCheckService::check()` for readability now that all 5 checks are wired (e.g. extract a private `buildResults(): array` if the method has grown unwieldy). `ddev phpcs` clean.
- [x] **VERIFY**: `composer test` full suite green, zero regressions in `MonitoringServiceTest`.

---

## PR 4 — Controller, Request, Policy, Routes, Scheduler

**Depends on:** PR 3 merged (needs a complete `SeoCheckService`/`CheckSeoJob`).
**Start state:** service/job exist but are unreachable from any HTTP route or scheduler.
**Finish state:** `POST /seo/{seoCheck}/recheck`, `GET /seo`, `GET /seo/create`, `POST /seo` are live and ownership-guarded; the independent SEO scheduler block runs in `routes/console.php`. No Blade view exists yet — `index`/`create` controller actions return views that don't exist until PR 5, so this PR's own tests must not `assertOk()` on those two GET routes; instead exercise them via `assertViewIs()`-style assertions is not possible without views, so scope this PR's tests to routes that don't require rendering a missing view, OR stub minimal placeholder views here and let PR 5 replace them (see note in first task).
**Rollback:** revert the 2 route-file edits, delete controller/request/policy/test files. `seo.*` routes disappear; no impact on `monitors.*` routes.

- [x] **Decision point (record in the PR description)**: to keep `index`/`create` testable in this slice without pulling Blade work forward, add minimal placeholder views (`resources/views/seo/index.blade.php`, `resources/views/seo/create.blade.php` — bare `@extends('layouts.app')` shells with no table/form markup yet) in this PR, and let PR 5 replace their contents with the full design. This keeps PR 4 scoped to routing/authorization behavior while still allowing `assertOk()`/`assertRedirect()` assertions. Document this explicitly as an interim placeholder in the PR body.
- [x] **RED**: Create `tests/Feature/SeoCheckControllerTest.php` (`@group seo-check-controller`), mirroring `MonitorControllerTest`'s structure, with failing cases:
  - Guest redirected from `seo.index`, `seo.create`, `seo.store`, `seo.recheck`.
  - Authenticated index shows only the current user's monitors' SEO rows (isolation via a distinguishing string in a stub view, or via `assertViewHas('monitors', ...)`).
  - `store` with valid `name`+`url` creates exactly one `monitors` row (`assertDatabaseHas`) with pinned defaults `interval=60`, `timeout=30`, `expected_status_code=200`, AND exactly one paired `seo_checks` row (`assertDatabaseCount`); redirects to `seo.index`.
  - Validation: missing `name` ⇒ `assertSessionHasErrors('name')`; invalid `url` ⇒ error; private-IP URL (`http://192.168.1.1/`) ⇒ `NoPrivateUrl` error.
  - `recheck`: owner recheck ⇒ redirect to `seo.index` + flash `success`; cross-user recheck ⇒ `assertForbidden()`.
  - `recheck` runs synchronously: bind a mocked Guzzle client (or a fake `SeoCheckService`) into the container so `CheckSeoJob::dispatchSync()` executes inline during the POST, then assert `$seoCheck->fresh()->last_checked_at` is non-null immediately after the response (no queue worker involved).
- [x] **GREEN**: `app/Http/Requests/StoreSeoCheckRequest.php` — `authorize(): true`; `rules()` returns `name` (`required|string|max:255`) and `url` (`required|url|max:255`, `new NoPrivateUrl()`), reusing the existing rule class.
- [x] **GREEN**: `app/Policies/SeoCheckPolicy.php` — `viewAny` false; `view`/`update`/`delete` check `$user->id === $seoCheck->monitor->user_id`; `create` true. Auto-discovered via Laravel's `SeoCheck` → `SeoCheckPolicy` naming convention.
- [x] **GREEN**: `app/Http/Controllers/SeoCheckController.php` — `use AuthorizesRequests`; `index()` returns `$request->user()->monitors()->with('seoCheck')->latest()->get()` to `seo.index`; `create()` returns `seo.create`; `store(StoreSeoCheckRequest $request)` creates the monitor with pinned defaults (D10) and redirects with a Spanish flash message; `recheck(SeoCheck $seoCheck)` calls `$this->authorize('update', $seoCheck)` then `CheckSeoJob::dispatchSync($seoCheck)` then redirects with a Spanish flash message.
- [x] **GREEN**: `routes/web.php` — inside the existing `auth` middleware group, add `Route::resource('seo', SeoCheckController::class)->only(['index', 'create', 'store']);` and `Route::post('seo/{seoCheck}/recheck', [SeoCheckController::class, 'recheck'])->name('seo.recheck');`.
- [x] **GREEN**: `routes/console.php` — append the new, independent `Schedule::call(function () { SeoCheck::cursor()->each(...) })->everyMinute();` block per design §4.10, gated on `last_checked_at` null or `addMinutes($seoCheck->interval)->isPast()`, dispatching `CheckSeoJob::dispatch($seoCheck)` (queued). Do not modify the existing Monitor scheduling block.
- [x] **GREEN**: run the new controller test suite, confirm green.
- [x] **TRIANGULATE**: add a scheduler-focused test (e.g. in a small `tests/Feature/SeoSchedulerTest.php` or folded into the controller test) asserting: an entry with `last_checked_at = null` gets a `CheckSeoJob` queued when the scheduled closure runs (use `Bus::fake()` and invoke the closure directly, or `Queue::fake()` + `Artisan::call('schedule:run')` if the project's test harness supports it); an entry checked recently within its `interval` does NOT get a job dispatched.
- [x] **REFACTOR**: confirm the controller has no duplicated authorization logic vs. `MonitorController`; `ddev phpcs` clean on all new files.
- [x] **VERIFY**: `composer test` full suite green; manually confirm (or via test) that `MonitorController`'s own routes/tests are unaffected.

---

## PR 5 — Blade Views, Navigation Tab, Final Regression Pass

**Depends on:** PR 4 merged (routes/controller must exist for views to link to).
**Start state:** placeholder `seo/index.blade.php` / `seo/create.blade.php` shells from PR 4; no nav entry.
**Finish state:** full table UI with per-check colored pills, relative-time column, per-row re-check form, add-URL form; "SEO / Redirects" nav tab visible in desktop + mobile navigation; full spec-driven UI acceptance scenarios pass; complete no-regression verification across the whole change.
**Rollback:** revert the 2 Blade files to their PR-4 placeholder state (or delete if PR 4 didn't add placeholders) and remove the 2 nav-link snippets from `navigation.blade.php`. No impact on `monitors/*.blade.php` or Monitor nav markup.

- [x] **RED**: extend `tests/Feature/SeoCheckControllerTest.php` (or add view-assertion cases) with failing cases:
  - Index renders `assertSee()` for each of the 5 check column headers and the "checked X ago"/"Nunca" text.
  - A monitor with `www_redirect = SeoCheck::WWW_TO_NO_WWW` renders a green-class pill; `robots_ok = false` renders a red-class pill; `NONE` renders a gray-class pill (assert via `assertSee` of the relevant CSS class strings or badge text, matching however `monitors/index.blade.php` asserts its own status pills today).
  - Nav: an authenticated page response `assertSee('SEO / Redirects')` and, when visiting a `seo.*` route, the link's active-state class is present (mirror however the existing Monitor active-state is asserted, if such an assertion exists today — otherwise add one consistent with the codebase's Blade-testing convention).
- [x] **GREEN**: `resources/views/seo/index.blade.php` — `@extends('layouts.app')`; table columns Nombre, URL, www, HTTPS, Trailing slash, robots.txt, Sitemap, Última revisión, Acciones per design §4.11; pill classes (`bg-green-100 text-green-800` / `bg-red-100 text-red-800` / `bg-gray-100 text-gray-800`); `{{ $monitor->seoCheck?->last_checked_at?->diffForHumans() ?? 'Nunca' }}`; per-row re-check `<form method="POST" action="{{ route('seo.recheck', $monitor->seoCheck) }}">@csrf<button>Revisar</button></form>`; header "+ Añadir URL" link to `route('seo.create')`.
- [x] **GREEN**: `resources/views/seo/create.blade.php` — minimal form posting to `route('seo.store')`, `@csrf`, `name`+`url` fields reusing `monitors/create.blade.php`'s Tailwind input classes, `@error()` messages, `old()` repopulation; cancel link to `route('seo.index')`.
- [x] **GREEN**: `resources/views/layouts/navigation.blade.php` — add desktop `<x-nav-link :href="route('seo.index')" :active="request()->routeIs('seo.*')">{{ __('SEO / Redirects') }}</x-nav-link>` immediately after the existing Monitor nav-link, and the matching `<x-responsive-nav-link>` after the existing mobile Monitor link. Do not modify any existing Monitor nav markup/lines.
- [x] **GREEN**: run the extended test cases, confirm green.
- [x] **TRIANGULATE**: add a case for an SEO entry that has never been checked (`last_checked_at = null`) rendering "Nunca" rather than throwing on `diffForHumans()` of a null value; add a case confirming the Monitor tab's own index page still renders identically (`assertSee` on an existing Monitor-tab fixture string) to catch any accidental navigation-markup regression.
- [x] **REFACTOR**: diff `seo/index.blade.php`/`seo/create.blade.php` against `monitors/index.blade.php`/`monitors/create.blade.php` to confirm intentional duplication only (no leftover TODOs or dead placeholder markup from PR 4).
- [x] **VERIFY (full change gate)**:
  - `composer test` — entire suite green, including all `SeoCheck*` tests plus the untouched `MonitoringServiceTest`/`MonitorControllerTest` suites (zero regressions per spec's "No Regression To Existing Monitor Functionality" requirement).
  - `ddev phpcs` — no new violations.
  - Manual/automated confirmation of every proposal Success Criteria bullet: nav tab visible both navs, shared-URL invariant across tabs, colored pill table, synchronous re-check, independent scheduler cadence, bounded request count (≤4/cycle), Monitor suites green.
