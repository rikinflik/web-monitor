# Archive Report: add-seo-redirects-tab

**Status:** SUCCESS

**Date Archived:** 2026-07-23

**Archive Path:** `openspec/changes/archive/2026-07-23-add-seo-redirects-tab/`

---

## Artifacts Read

All required artifacts were present and reviewed:

- `proposal.md` — problem statement, intent, scope, design decisions
- `design.md` — concrete implementation contracts and patterns
- `specs/seo-checks/spec.md` — 9 requirements with 31 acceptance scenarios
- `tasks.md` — 5 PR slices (stacked-to-main), task checklist
- `verify-report.md` — verdict PASS, test results, spec coverage, TDD evidence
- `sync-report.md` — CLI sync report, exit code 0, canonical files updated
- `apply-progress.md` — TDD cycle evidence, 51 tests passing, all scope delivered

---

## Verification Gate

**First line of verify-report.md:** `Verdict: PASS` ✓

**Verdict Status:** PASS — all spec requirements verified, 51 new tests passing, zero regressions in existing Monitor suite (138/138 new+existing tests passed; 5 pre-existing unrelated failures in Auth scaffolding, confirmed out of scope).

---

## Sync Report Summary

**Sync Status:** Completed via deterministic CLI

**CLI Invocation:**
```
node "/Users/marc.roma/Sites/localhost/vibeless-claude-sdd/scripts/sdd-sync.mjs" add-seo-redirects-tab
```

**Exit Code:** 0 (success)

**Domain Synced:**
- `seo-checks` (new domain)
  - Mode: `create-copy`
  - Added: 9 requirements
  - Modified: 0
  - Removed: 0

**Canonical Files Created:**
- `openspec/specs/seo-checks/spec.md` (new)

**Active Same-Domain Collisions:** None

---

## Change Summary

### Purpose

Add a second "SEO / Redirects" tab, alongside the existing Monitor tab, that surfaces five per-URL SEO checks (www redirect, HTTPS redirect, trailing-slash redirect, robots.txt reachability, sitemap reachability) for the same shared list of URLs already tracked by Monitor. Checks run periodically and are manually re-runnable with synchronous feedback. The feature does not alter existing Monitor behavior and minimizes outbound HTTP requests per check cycle.

### Scope Delivered

**New Requirements Added to `seo-checks` domain:**

1. Shared URL Source of Truth (1:1 invariant, auto-create, non-throwing)
2. Two-table current-state + history split; dedup; additive reversible migrations; `last_checked_at` index
3. Five checks with exact vocabularies (www/https/trailing-slash/robots/sitemap)
4. Redirect detection uses no-follow (`allow_redirects=false`), reads Location
5. Request-minimizing probe; bounded 3 (common) / 4 (worst)
6. Manual synchronous re-check (`dispatchSync`), bounded timeout
7. Independent periodic scheduling (per-entry minutes, own last_checked_at, decoupled)
8. SEO Tab UI (nav both navs, colored pills, relative time, recheck control, add-URL form, ownership)
9. No regression to Monitor functionality

### Files Changed

**New Files (18 files):**
- 4 migrations: `create_seo_checks_table`, `create_seo_check_logs_table`, `add_seo_checks_indexes`, `backfill_seo_checks_for_existing_monitors`
- 2 models: `SeoCheck.php`, `SeoCheckLog.php`
- 1 service: `SeoCheckService.php`
- 1 job: `CheckSeoJob.php`
- 1 controller: `SeoCheckController.php`
- 1 form request: `StoreSeoCheckRequest.php`
- 1 policy: `SeoCheckPolicy.php`
- 1 factory: `SeoCheckFactory.php`
- 2 views: `seo/index.blade.php`, `seo/create.blade.php`
- 4 test files: `SeoCheckPairingTest.php`, `SeoCheckServiceTest.php`, `SeoCheckControllerTest.php`, `SeoSchedulerTest.php`

**Modified Files (4 files):**
- `app/Models/Monitor.php` — added `seoCheck()` HasOne relation + non-throwing `created` hook
- `routes/web.php` — added SEO resource + recheck route (inside existing `auth` group)
- `routes/console.php` — added independent SEO scheduler block
- `resources/views/layouts/navigation.blade.php` — added desktop + mobile SEO nav links

**Untouched by Design (verified via git):**
- `MonitoringService.php`, `CheckMonitorJob.php`, `MonitorController.php`, `MonitorPolicy.php`
- All existing `monitors/*.blade.php` views
- Existing Monitor migrations and Filament panel

### Test Results

**Total Tests:** 143 run, 138 passed, 5 failed (pre-existing, unrelated)

**New Tests:** 51 all passing
- PR1 (pairing invariant): 5/5 pass
- PR2 (redirect probe): 13/13 pass
- PR3 (robots/sitemap/dedup/job): +13 = 26/26 total in service file
- PR4 (controller/routes/scheduler): 12 controller + 1 scheduler = 13/13 pass
- PR5 (views/nav/pills): +7 controller = 19/19 total controller

**Regression Test Results:**
- Existing Monitor suite (`MonitoringServiceTest` + `MonitorControllerTest`): 48/48 green before and after
- Zero regressions in existing functionality

**TDD Compliance:**
- All 5 PR slices follow strict RED → GREEN → TRIANGULATE → REFACTOR
- RED evidence: 48 captured failures (class not found, route not defined, assertion failures)
- All RED cases verified as genuine failures on missing implementation
- Mutation spot-check: 2 highest-risk assertions inverted and restored, both confirmed GREEN

### Design Decisions (Locked)

- **D1:** `seo_checks` is one-to-one child of `monitors`; no independent URL holder
- **D2:** Auto-create paired `seo_checks` row via `Monitor::created` hook, non-throwing
- **D3:** Two-table split (current state + history), dedup on write
- **D4:** Single redirect probe resolves all 3 canonicalization checks
- **D5:** Requests capped at 3 (common) / 4 (worst) per cycle
- **D6:** New `SeoCheckService` reusing Guzzle DI pattern; `MonitoringService` untouched
- **D7:** Manual re-check synchronous via `dispatchSync`; scheduler uses queued `dispatch`
- **D8:** `interval` stored in MINUTES; scheduler uses `addMinutes` (deliberate divergence from Monitor's `addSeconds`)
- **D9:** Result vocabularies as plain strings + booleans (no enum classes)
- **D10:** SEO-tab form pins concrete Monitor defaults (interval=60, timeout=30, expected_status_code=200)
- **D11:** Own minimal Blade views; no shared component refactor
- **D12:** Backfill existing monitors via data migration

### Verification Coverage

**Spec Scenarios:** All 31 acceptance scenarios from `seo-checks` spec have covering tests

**Success Criteria (from proposal):**
- ✓ New "SEO / Redirects" nav tab appears next to Monitor in both desktop and mobile navigation
- ✓ Adding a URL from either tab results in that URL appearing in both tabs
- ✓ SEO index table shows per URL: www check, HTTPS check, trailing-slash check, robots.txt check, sitemap check (colored pills: green/red/gray)
- ✓ Each row has working manual re-check control that synchronously updates results
- ✓ Periodic scheduler dispatches SEO checks on own per-entry interval
- ✓ `SeoCheckService` outbound request count bounded at most 4 per cycle
- ✓ All new and existing tests pass; zero regressions

**Live Validation:** HTTP-level surfaces validated via Feature harness (full middleware/kernel stack, real Blade rendering)

---

## Assertion Quality Notes

**Minor Warning (non-blocking):**
- Line 213 of `SeoCheckControllerTest.php`: redundant no-op setup (`$seoCheck->update(['robots_ok' => false])` when already defaulting false) — does not constitute a tautology, test still validates correctly

**No Critical Issues:**
- All redirect/robots/sitemap tests assert against fresh DB state and exact vocabulary constants
- Request-count and order tests assert via `Middleware::history`
- Synchronous-recheck test asserts real inline side effect (`last_checked_at` set)
- No smoke-only tests or implementation-detail coupling

---

## Deployment Notes

**Migrations:** All 4 migrations are additive (new tables only) with symmetric `down()` methods. Schema changes run on deploy via normal migrate step.

**Backfill:** Runs automatically as the `backfill_seo_checks_for_existing_monitors` migration, ensuring every existing monitor gains its paired row in the same deploy.

**Scheduler:** New independent `Schedule::call(...)` block activates on deploy; with default `interval = 1440` (daily), initial run causes one-time burst of queued `CheckSeoJob`s when all entries become due (bounded by monitor count).

**Rollback:** Cleanly reversible — revert commit(s), roll back 4 migrations (table drops + observer removal). Zero impact on Monitor/MonitorLog data. All new files are additive; remove them + revert the 2 route/nav edits to fully revert.

---

## Risk Summary

**Residual Risks (documented, non-blocking):**
- Pre-existing 5 Auth scaffolding failures (`Route [dashboard] not defined`) remain in suite — confirmed unrelated to this change, present at baseline, out of scope
- Live browser/JS validation unavailable in test environment (no e2e command, no injected browser skill); HTTP-level surfaces validated via Feature harness
- Lint tooling: no `phpcs.xml`/`phpstan.neon`; Laravel Pint present but not enforced; new code mirrors established repo conventions

**Mitigations Applied:**
- Non-throwing `Monitor::created` hook wrapped in `try/catch` to prevent paired-row creation failure from breaking Monitor creation
- Bounded per-request timeout (10 seconds) on all outbound checks prevents slow URLs from hanging the controller
- Independent SEO scheduler decoupled from Monitor's frequent uptime cadence
- Request-minimizing probe design (single probe resolves all 3 canonicalization checks, capping total to 3-4 requests per cycle)

---

## Sync-Report Precondition

**File:** `openspec/changes/add-seo-redirects-tab/sync-report.md`

**Status:** Complete and successful

**CLI Output (verbatim):**
```
SYNC OK change=add-seo-redirects-tab
| Domain | Mode | Added | Modified | Removed |
| --- | --- | --- | --- | --- |
| seo-checks | create-copy | 9 | 0 | 0 |
RESULT {"change":"add-seo-redirects-tab","dryRun":false,"totalOps":9,"domains":[{"domain":"seo-checks","mode":"create-copy","added":9,"modified":0,"removed":0}]}
```

**Manual Fallback Used:** No — deterministic sync CLI was available and used exclusively; no hand-editing of canonical specs performed.

---

## Archive Summary

This change has been **verified** (Verdict: PASS), **synced** (9 new requirements canonized), and **is ready for archival**. All 31 spec scenarios are covered by 51 passing tests. Zero regressions in existing Monitor functionality. All tasks from `tasks.md` are complete. The feature is fully implemented and tested.

**Archive location:** `openspec/changes/archive/2026-07-23-add-seo-redirects-tab/`

**Skill Resolution:** none (Laravel project; no project/user skills injected)
