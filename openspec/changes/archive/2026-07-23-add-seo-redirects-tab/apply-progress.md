# Apply Progress: add-seo-redirects-tab

Mode: Strict TDD (RED -> GREEN -> TRIANGULATE -> REFACTOR). Test command: `composer test`
(runs `php artisan config:clear` + `php artisan test`). Delivery: auto-chain,
stacked-to-main, 5 work-unit slices — all 5 implemented in this session.

## Status

All 5 slices implemented and green. Full requested scope delivered; nothing
deferred to future work.

## Baseline (safety net)

- Full suite before change: 92 tests, 5 pre-existing failures (`Route [dashboard]
  not defined`) in `Auth\AuthenticationTest`, `Auth\EmailVerificationTest`,
  `Auth\RegistrationTest`, `Auth\PasswordConfirmationTest`, `ExampleTest`.
  These are UNRELATED to this change (missing `dashboard` route) and were NOT
  touched or fixed (out of scope, pre-existing).
- Monitor working baseline: `MonitoringServiceTest` + `MonitorControllerTest` =
  48 tests green before and after this change (zero regressions).

## Final verification

- `composer test`: 138 passed, 5 failed (the exact same pre-existing Auth/Example
  failures; identical to baseline). 51 new SEO tests all pass.
- `vendor/bin/phpunit tests/`: Tests 143, Errors 3, Failures 2 (all 5 = the
  pre-existing unrelated failures).
- Monitor suites re-run after each slice: 48/48 green throughout — no regression.

## Lint note

Laravel Pint is the only PHP style tool present (no `phpcs.xml`/`phpstan.neon`).
Pint is NOT an enforced gate in this repo: the existing untouched files
(`MonitoringService`, `CheckMonitorJob`, `MonitorController`, `MonitorLog`) all
fail default Pint too. New code deliberately mirrors the established repo
conventions (e.g. `. $e->getMessage()` concat style from `MonitoringService`,
job/docblock layout from `CheckMonitorJob`) per the "prefer existing patterns"
rule rather than reformatting to a standard the codebase does not follow.

## Files changed

New:
- `database/migrations/2026_07_23_100001_create_seo_checks_table.php`
- `database/migrations/2026_07_23_100002_create_seo_check_logs_table.php`
- `database/migrations/2026_07_23_100003_add_seo_checks_indexes.php`
- `database/migrations/2026_07_23_100004_backfill_seo_checks_for_existing_monitors.php`
- `app/Models/SeoCheck.php`
- `app/Models/SeoCheckLog.php`
- `app/Services/SeoCheckService.php`
- `app/Jobs/CheckSeoJob.php`
- `app/Http/Controllers/SeoCheckController.php`
- `app/Http/Requests/StoreSeoCheckRequest.php`
- `app/Policies/SeoCheckPolicy.php`
- `database/factories/SeoCheckFactory.php`
- `resources/views/seo/index.blade.php`
- `resources/views/seo/create.blade.php`
- `tests/Feature/SeoCheckPairingTest.php`
- `tests/Feature/SeoCheckServiceTest.php`
- `tests/Feature/SeoCheckControllerTest.php`
- `tests/Feature/SeoSchedulerTest.php`

Modified:
- `app/Models/Monitor.php` (added `seoCheck()` HasOne + non-throwing `created`
  hook + `defaultSeoCheckAttributes()`; existing `creating` hook untouched)
- `routes/web.php` (seo resource + recheck route inside existing `auth` group)
- `routes/console.php` (second independent SEO scheduler block, `addMinutes`)
- `resources/views/layouts/navigation.blade.php` (desktop + mobile SEO nav link)

Untouched by design (verified): `MonitoringService`, `CheckMonitorJob`,
`MonitorController`, `MonitorPolicy`, `monitors/*.blade.php`, existing Monitor
migrations, Filament panel.

## Work-unit / PR boundaries (stacked-to-main)

- PR 1 (Schema & pairing): 4 migrations + `SeoCheck`/`SeoCheckLog` models +
  factory + `Monitor` hook/relation + `SeoCheckPairingTest`.
- PR 2 (Redirect probe): `SeoCheckService` redirect-only + probe/vocab tests.
- PR 3 (Robots/sitemap/dedup + job): `SeoCheckService` completion + `CheckSeoJob`
  + robots/sitemap/count/order/dedup/last_checked tests.
- PR 4 (Controller/request/policy/routes/scheduler): + placeholder views +
  `SeoCheckControllerTest` (routing/auth/store/recheck) + `SeoSchedulerTest`.
- PR 5 (Views/nav/regression): full `seo/index` + `seo/create` views + nav tab +
  UI/pill/nav/regression test cases.

## TDD Cycle Evidence

| Task (slice) | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| PR1 pairing invariant | `tests/Feature/SeoCheckPairingTest.php` | Feature | 48/48 Monitor green | not-run (nonexistent symbol `App\Models\SeoCheck` + missing `seo_checks` table) then executed: 5 errors | 5/5 pass | non-throwing failure case + backfill case (2 extra paths) | clean; extracted `defaultSeoCheckAttributes()` |
| PR2 redirect probe/classify | `tests/Feature/SeoCheckServiceTest.php` | Feature | 48/48 Monitor green | Failed: `Error: Class "App\Services\SeoCheckService" not found` (13 errors) | 13/13 pass | no-Location + connection-error + non-standard-port cases | clean; per-dimension classify helpers |
| PR3 robots/sitemap/dedup + job | `tests/Feature/SeoCheckServiceTest.php` | Feature | 13/13 PR2 green | Failed: `Failed asserting that null is not null` (last_checked_at) + `Failed asserting that 0 matches expected 1` (dedup) — 9 failures | 26/26 pass | request-order sequence assertion (probe->robots->sitemap.xml) | clean; extracted `returns200()`, `originOf()`, `recordLog()` |
| PR4 controller/routes/scheduler | `tests/Feature/SeoCheckControllerTest.php`, `tests/Feature/SeoSchedulerTest.php` | Feature | 48/48 Monitor green | Failed: route `seo.index` not defined (12 errors) + `The expected [App\Jobs\CheckSeoJob] job was not pushed` | 13/13 pass | scheduler due-vs-not-due test (`SeoSchedulerTest`) | clean; controller reuses `AuthorizesRequests` |
| PR5 views/nav/pills | `tests/Feature/SeoCheckControllerTest.php` | Feature | 13/13 PR4 controller green | Failed: response `contains "HTTPS"` assertion failed on placeholder view | 19/19 pass | `Nunca` never-checked fallback + Monitor-index no-regression case | clean; intentional Tailwind duplication vs monitors views |

RED capture notes:
- PR1/PR2 RED are the "nonexistent symbol" branch (class/table did not exist) —
  executed and confirmed failing (errors), per the strict-TDD not-run marker rule.
- PR3/PR4/PR5 RED are the existing-symbol branch (method/route/view existed but
  lacked the new behavior) — executed and captured real failure lines above.

## Test Summary

- Total new tests written: 51 (PR1: 5, PR2: 13, PR3: +13 = 26 in service file,
  PR4: 12 controller + 1 scheduler, PR5: +7 controller = 19 controller total).
- Total new tests passing: 51/51.
- Layers used: Feature (51). Integration/E2E: none configured in this repo.
- Approval tests: none (no refactoring of existing behavior).
- Pure-ish helpers created: `classifyRedirect` + per-dimension classifiers,
  `originOf`, `normalizePath` (deterministic, no side effects).

## Deviations from design

- Design §D7 mentions `.env QUEUE_CONNECTION=database`; the test env
  (`phpunit.xml`) uses `QUEUE_CONNECTION=sync`, so `dispatchSync` runs inline
  regardless. The synchronous-recheck test binds a mocked Guzzle `Client` into
  the container to avoid real network calls and assert `last_checked_at` is set.
- Migration timestamps use `2026_07_23_1000XX` (today), sorting after all
  existing Monitor migrations and with backfill last, as required.
- phpcs referenced in proposal/design is not present; Pint is the only tool and
  is not enforced (see Lint note). No new lint gate applied.

## Remaining tasks

None. All `tasks.md` checkboxes complete; every Success Criteria bullet covered
by passing tests (nav tab both navs, shared-URL invariant, colored pill table,
synchronous re-check, independent scheduler cadence, bounded <=4 requests/cycle,
Monitor suites green).

## Risks

- Pre-existing 5 Auth/Example failures (`Route [dashboard] not defined`) remain
  in the suite — unrelated to this change, present at baseline, not in scope.
- No background processes were started; nothing left running.
