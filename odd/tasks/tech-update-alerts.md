# Tech Update Alerts

## Objective
Add to WebMonitor an in-app dashboard that periodically polls upstream security/release feeds
for PHP, Drupal, WordPress, Laravel, Vue and Angular and stores new findings, plus a
token-gated public page combining those alerts with the monitors that are currently down.

## Problem / Why
The user runs projects across these six stacks and wants to avoid being caught off guard by
critical security releases. Research confirmed a real gap: `php.net/feed.atom` reported
CVE-2026-109618/109619 the same day, while generic Composer/npm auditing (which already
covers Drupal, Laravel, Vue and Angular, since those ship through a package manager) cannot
see it — the PHP interpreter is not a Composer-managed dependency.

## Scope
- In: `alert_sources`/`alert_findings` schema, models, seeder (6 fixed ecosystems); an
  RSS/Atom fetcher and a GitHub-Advisories fetcher; a check service + queued job; an hourly
  scheduled command; an authenticated dashboard + nav tab + admin-only manual "check now";
  the post-login landing route; a token-gated public page listing only DOWN monitors plus the
  alerts.
- Out (non-goals): scanning the user's own `composer.lock`/`package.json`; WordPress
  plugin/theme coverage via WPScan/Patchstack (needs an API-key decision); Slack/email
  notification of new findings; production deployment/cron (the user's own responsibility).

## Constraints
- Follow `MonitoringService`/`SeoCheckService` conventions: dedup before insert, `ShouldQueue`
  jobs with `tries=3`/`backoff=10`, Policy-based `authorize()`, `#[Group(...)]` attributes,
  `RefreshDatabase`, routes under `auth` middleware, bounded HTTP timeouts.
- No outbound HTTP in tests — `Http::fake()`.
- `alert_sources` is global, not user-owned: no `user_id`; viewing open to any authenticated
  user; manual "check now" gated to `role === 'admin'`.
- **Installing the 6 sources must never require `migrate --seed`** — `DatabaseSeeder` creates
  demo users with hardcoded passwords, so making it a deploy step would provision a
  known-credential admin.
- **Tests run on SQLite `:memory:` while production is MariaDB strict** — column lengths and
  collation are NOT exercised by the suite. Schema limits must be chosen for real data and
  guarded by asserting declared lengths, never assumed safe because tests are green.
- `external_id` must be non-empty: an empty value would collapse every id-less feed item of a
  source into one row under the dedup unique key, silently losing real advisories.
- Public page (user decision, 2026-10-22): lists ONLY monitors currently down; single global
  unguessable token in config; `noindex` + throttle; empty token disables the route (404).
- The global testing.md DTT/Drupal rule does not apply — plain Laravel/PHPUnit, `tests/Feature/`.
- "Browser homepage" is a client-side setting the user applies; the in-app equivalent is the
  post-login landing page.

## Tasks
- [x] T1 — `alert_sources`/`alert_findings` migrations, models, factories, idempotent
  `AlertSourceSeeder` for the 6 ecosystems · AC: the 6 sources install without `migrate --seed`
  with correct `key`/`kind`, and a test proves `(alert_source_id, external_id)` rejects a
  duplicate · Route: delegated · Trigger: multi-file write (9 files)
- [x] T1b — Remediation of the T1 review round: decouple `AlertSourceSeeder` from
  `DatabaseSeeder`; widen `external_id`/`title`/`url`; add `last_success_at`/`last_error` to
  `alert_sources`; reject empty `external_id`; pin dedup collation; strengthen the idempotency,
  duplicate-rejection, cast and relation assertions · AC: full suite green, declared column
  lengths asserted, and a test proves a re-seed preserves findings and `last_checked_at` ·
  Route: delegated · Trigger: multi-file write (7 files)
- [x] T2 — `RssFeedFetcher` (RSS 2.0 + Atom) and `GitHubAdvisoryFetcher`, behind a shared
  `AlertFeedFetcher` interface · AC: a test per fetcher with `Http::fake()` and real-shape
  fixtures proves normalization to `[external_id, title, url, published_at, severity]`, that a
  non-200/malformed response raises `AlertFeedException` (so T3 can record it), and that a
  valid empty feed yields `[]` · Route: delegated · Trigger: multi-file write (6 files)
- [x] T2b — Remediation of the T2 review round (13 defects): reject non-feed XML and non-list
  JSON loudly instead of returning `[]`; carry HTTP status/transient/retry-after on
  `AlertFeedException`; bound response bytes and item count; stop following redirects; sanitize
  the persisted error message (host only, never raw Guzzle text); restore the libxml entity
  loader; pin both named fallbacks, date degradation, the transport-failure clause, stray-request
  prevention and real-feed fixture quirks · AC: full suite green, every defect covered by a test
  that fails when its behaviour is broken, sabotage-proven where the code was already correct ·
  Route: delegated · Trigger: multi-file write (5 files)
- [ ] T3 — `AlertCheckService::check(AlertSource)` (dedup-insert, update `last_checked_at`,
  record `last_success_at`/`last_error`) and `CheckAlertSourceJob` · AC: a test proves a second
  check over the same feed plus one new item inserts exactly one row, and that a failing fetch
  records `last_error` without wiping prior findings
- [ ] T4 — `alerts:check` Artisan command dispatching the job per source, registered hourly in
  `routes/console.php` · AC: `artisan('alerts:check')` with `Queue::fake()` asserts the job
  pushed exactly 6 times
- [ ] T5 — `AlertController@index` + `@checkNow` (admin-only, synchronous), routes, the
  `alerts/index` Blade dashboard, nav tabs, post-login landing · AC: authenticated GET
  `/alerts` is 200 and lists the six sources; non-admin check-now is 403, admin's succeeds;
  dashboard observed rendering in the running app
- [ ] T6 — Token-gated public overview: `config/monitoring.php` token, `PublicOverviewController`,
  `GET /overview/{token}` with `throttle:30,1`, Blade view of DOWN monitors + recent findings ·
  AC: correct token is 200 showing a down monitor but not an up one; wrong and empty-configured
  tokens are 404; response carries `noindex`; observed rendering in the running app

## Checks
- TDD: strict (source: detected runner `composer test` — PHPUnit; matches `openspec/config.yaml`)
- Live target: https://webmonitor.ddev.site (observed via `ddev describe`, 127.0.0.1:54236/54238)
- Test runner: `ddev exec composer test`
- Build: not required
- Commits: **on** (source: explicit user instruction mid-feature, 2026-10-22 — "si tanca ara,
  comiteja i continua"; resolved off at creation, so the record is: off by default, then
  authorized by the user once slices 1–2 were complete). Local commits on a feature branch
  only; push/PR/merge remain the user's own decision and were NOT authorized.
- Branch: cut from `main` for this work rather than committing to the default branch.
- Commit style: follow this repo's own convention (`scope: imperative description`, e.g.
  `telegram: keep bot tokens out of logs`), NOT the global rules' `PRJ-XXX:` Jira format —
  this repo has no Jira keys in its history.
- Base ref: a5650a3733750ed0eaa8d7e4e56df713d19eb85f
- Known pre-existing failures: none — baseline 186 passed (352 assertions)
- Delivery: `force-chained` — slice 1 (T1+T1b+T2) reviewed; later slices T3+T4, T5, T6

## Progress
- Round 1 reviewed slice 1 (T1 persistence, 496 lines): tier **high**, 4 lenses, reason
  `signal` (the task doc's own "update" stem). Behaviour defects consolidated into T1b and
  fixed. Per the round cap those paths are now closed to further lens rounds; the remediation
  was validated by the parent's own suite run and direct behavioural probes instead.
- Round 2 reviews slice 2 (T2 fetchers only, 816 serialized lines). Tier taken from the whole
  candidate (**high**, 4 lenses, 1562 changed lines vs 400 budget); the serialization is sliced
  by pathspec to `app/Services/AlertFeeds` + the two fetcher tests, since the persistence paths
  are capped.
- Delivery: **2793 lines accumulated and uncommitted, 7× the 400 budget.** `force-chained`
  applies: both slices have had their one lens round and one remediation round and are closed
  to further review, so the remaining action is to split delivery. Recommended commits —
  (1) persistence (migrations, models, factories, seeder, `AlertSourceTest`), (2) fetchers
  (`app/Services/AlertFeeds` + their two tests). **Commit before starting T3**, or the next
  slice lands on an unreviewable pile.
- Known limitation carried forward: the response body-size guard is best-effort. Laravel's
  default Guzzle handler has already buffered the body by the time the response object exists,
  so the `Content-Length` pre-check bounds the downstream SimpleXML/`json_decode` step (the
  real OOM multiplier) but not the initial buffer. Closing it fully needs a streaming sink.

## Evidence
- Baseline: `ddev exec composer test` → 186 passed (352 assertions).
- After T1b + T2: `ddev exec composer test` → **218 passed (444 assertions)**, re-run by the
  parent, zero regressions.
- T1 RED observed: `Class "App\Models\AlertSource" not found`, 6 failed (0 assertions).
- T1b/T2 guards sabotage-proven: dropping the unique index, clearing `$casts`, swapping
  `updateOrCreate` for `firstOrCreate`, removing the HTTP timeout and splitting the `affects`
  parameter each made the owning assertion fail, then pass again on restore.
- Parent-verified directly: `ddev artisan migrate` alone (no `--seed`) installs all 6 sources;
  a whitespace-only `external_id` is rejected with `InvalidArgumentException`;
  `git diff HEAD -- database/seeders/DatabaseSeeder.php` is empty (demo-admin coupling gone).
- Dev DB currently has both migrations APPLIED (parent left them so T5/T6 can be observed live).
- Column-width guard caveat: it asserts the DDL this migration compiles to under the MySQL
  grammar, so a *later* migration narrowing the column would not be caught by it.

## Recorded findings — not fixed, by disposition (wording/preference)
- `config` JSON keys are undocumented and `affects` stores a list as a comma-joined string;
  carried into the T2 prompt instead of edited here.
- `severity` has no declared vocabulary (GHSA uses low/moderate/high/critical; RSS has none) —
  decide in T5 where the UI needs a sort order.
- `key` would read better as `ecosystem`/`slug`; renaming after later slices land costs more.
- `AlertSourceTest` also covers `AlertFinding` constraints, so its name under-describes it.
- Unused factory states (`githubAdvisory()`, `checkedAt()`, `withSeverity()`) match the
  `SeoCheckFactory` precedent and are expected to be used by T3/T4.
- A composite `(alert_source_id, published_at)` index would subsume the standalone one; held at
  SUGGESTION with no load evidence (hundreds of rows/year).
- `down()` drops accumulated findings and RSS windows make them unrecoverable — snapshot before
  any rollback once live.
- Round 2 (fetchers): document the `affects` comma-joined contract on the model; dedupe the two
  `parseDate()`/`TIMEOUT` copies; name the intermediate pre-normalization entry shape.
- Pre-existing, carried forward as warnings for later tasks:
  - `app/Rules/NoPrivateUrl.php` passes silently when DNS fails and is inherently TOCTOU — do
    NOT reach for it as the SSRF control when T5 adds an admin-writable `config` surface; the
    control there is a host allowlist plus no-redirects.
  - The repo-wide job `backoff = 10` has no jitter and no exponentiation, so for the three
    GitHub sources every retry lands inside the same rate-limit window — relevant to T4.
  - `phpunit.xml` sets no `failOnWarning`/`failOnRisky`; enabling it would have surfaced the
    `<channel>`-less path as a visible signal.
  - There is no CI at all (`.github/workflows/` absent), so every contract pinned here holds
    only as long as someone runs the suite locally.

## Next step
T1b remediation and T2 fetchers run in parallel; then re-run the suite and the classifier.
