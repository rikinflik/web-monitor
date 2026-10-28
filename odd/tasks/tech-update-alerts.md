# Tech Update Alerts

## Objective
An in-app dashboard that hourly polls upstream security/release feeds for PHP, Drupal,
WordPress, Laravel, Vue and Angular and stores new findings, plus a token-gated public page
combining those alerts with the monitors currently down.

## Problem / Why
Research confirmed a real gap: `php.net/feed.atom` reported CVE-2026-109618/109619 the same day,
while generic Composer/npm auditing (which already covers the other five, since they ship
through a package manager) cannot see it — the PHP interpreter is not a Composer dependency.

## Scope
- In: `alert_sources`/`alert_findings` schema, models, seeder (6 fixed ecosystems); RSS/Atom +
  GitHub-Advisories fetchers; check service + queued job; hourly command; authenticated
  dashboard + nav tab + admin-only "check now"; token-gated public page listing only DOWN
  monitors plus the alerts.
- Out: scanning the user's own `composer.lock`/`package.json`; WordPress plugin/theme coverage
  via WPScan/Patchstack (needs an API-key decision); production deployment/cron.
- Out, re-confirmed 2026-10-28 after the review round raised it again: **notifying** on a new
  critical finding or a persistently failing source. The repo has the Telegram pipeline
  (`OperatorAlerts`, `MonitorStatusAlert`) and discovery today depends on a human opening
  `/alerts`. User's call: keep it out, close this feature first. It needs its own design —
  dedup, what counts as "new", noise control.

## Constraints
- Follow `MonitoringService`/`SeoCheckService` conventions: dedup before insert, `ShouldQueue`
  with `tries=3`/`backoff=10`, `#[Group(...)]`, `RefreshDatabase`, `auth` middleware, bounded
  HTTP timeouts. No outbound HTTP in tests — `Http::fake()`.
- `alert_sources` is global, not user-owned: no `user_id`; viewing open to any authenticated
  user; "check now" gated to `role === 'admin'`.
- **Installing the 6 sources must never require `migrate --seed`** — `DatabaseSeeder` creates
  demo users with hardcoded passwords, so that would provision a known-credential admin.
- **Tests run on SQLite `:memory:`, production is MariaDB strict** — lengths and collation are
  not exercised. Choose for real data; assert declared lengths. This bit twice: once on column
  widths, once on `CASE severity` ordering (see T7a).
- `external_id` must be non-empty, or every id-less item of a source collapses into one row
  under the dedup key, silently losing advisories.
- Public page (user decision 2026-10-22): ONLY monitors currently down; single global token in
  config; `noindex` + throttle; empty token disables the route (404).
- Plain Laravel/PHPUnit in `tests/Feature/` — the global DTT/Drupal testing rule does not apply.

## Tasks
- [x] T1/T1b — schema, models, factories, idempotent `AlertSourceSeeder`; then widen columns,
  add `last_success_at`/`last_error`, reject empty `external_id`, pin dedup collation
- [x] T2/T2b — `RssFeedFetcher` + `GitHubAdvisoryFetcher` behind `AlertFeedFetcher`; then 13
  review defects: reject non-feed XML/non-list JSON loudly, carry HTTP status on
  `AlertFeedException`, bound bytes and item count, stop following redirects, sanitize the
  persisted error to host-only, restore the libxml entity loader
- [x] T3 — `AlertCheckService::check()` (dedup-insert, timestamps, `last_error`) + job
- [x] T4 — `alerts:check` command, registered hourly in `routes/console.php`
- [x] T5/T5b — `AlertController` + dashboard + nav; then two defects only a real run showed:
  an identifying User-Agent (wordpress.org's WAF 403s the default) and a 10-row cap in the
  query (php returned 495). Post-login landing deliberately NOT changed — nobody asked.
- [x] T5c — flag security findings rather than filtering (user decision, option 3 of 3). The
  flag is a **stored, indexed column set at insert**, not computed at render: under a 10-row
  cap a render-time flag would let the critical PHP advisory fall off within weeks.
- [x] T6 — token-gated public overview: config token, `PublicOverviewController`,
  `GET /overview/{token}` with `throttle:30,1`, Blade view of DOWN monitors + findings ·
  Route: delegated · Trigger: multi-file write (6 files)
- [x] T7a — **severity vocabulary was wrong at the boundary.** `SEVERITIES` declared `moderate`
  (GitHub's *GraphQL* spelling); the REST `/advisories` endpoint this fetcher calls returns
  `medium`. 19 stored advisories were labelled "Unrated" and sorted below `low` inside the cap
  — the exact burial this feature exists to prevent. Normalized at the fetcher boundary
  (lowercase, `moderate` as alias, unknown → null), which also closes the MariaDB-vs-SQLite
  collation divergence. Ranking SQL that was duplicated character-for-character across both
  controllers extracted into one trait · Route: delegated · Trigger: multi-file write (11 files)
- [x] T7b — safety/ops: reject non-http(s) item URLs (a hostile feed could land `javascript:`
  on both surfaces); throttle `alerts.check-now`; make the flash report the real per-source
  outcome; make the backfill migration re-runnable after a partial failure ·
  Route: delegated · Trigger: multi-file write (15 files)
- [x] T7c — four tests could not fail for the behaviour they named; each now sabotage-proven ·
  Route: delegated · Trigger: same work unit as T7b
- [x] T7d — both public pages compile Tailwind locally instead of `cdn.tailwindcss.com` (user
  decision 2026-10-28). The CDN script runs in-page on a URL that **is** the secret, so it can
  read `window.location.href` — the one token-leak channel the `Referrer-Policy`/`rel` hardening
  does not close — and a JIT dev CDN renders the status page unstyled exactly when it is
  consulted · Route: delegated · Trigger: multi-file write (8 files)
- [x] T7e — staleness badge (user decision 2026-10-28). "Healthy" meant only "the last attempt
  set no `last_error`", so a stopped worker or dead cron left all six green forever.
  `AlertSource::STALE_AFTER_HOURS = 3` against the hourly schedule; precedence is
  failing → stale → healthy → never checked · Route: delegated · Trigger: same work unit as T7d
- [x] T8 — Open public homepage (user decision 2026-10-28, superseding the 2026-10-22 "single
  global token" shape for this use case). The user wants the alerts as their **browser home
  page**, which a token URL serves badly. The insight that makes it safe: the two halves of the
  page have completely different confidentiality. The **advisories are already public** — they
  come from php.net, drupal.org, wordpress.org and GitHub Advisories, so there is nothing to
  protect. The **monitors are not** — client names, URLs and the fact they are down now is the
  part that must never be open. So: `GET /overview` with **no token**, showing the alerts in
  full plus a "N webs caigudes" count and a login button, and **never a monitor name or URL**.
  `GET /overview/{token}` stays exactly as it is for the detailed, named view. Also add the
  severity pills the public view has never had (it only ever rendered the `Security` flag), since
  "is it critical or trivial" is the whole point of a glance page · AC: `GET /overview` is 200
  with no session and no token; the rendered HTML contains the count but **not** any monitor
  name or URL, proven by a test that creates a down monitor with a distinctive name and asserts
  `assertDontSee` on both name and URL; the login button links to `route('login')`;
  `/overview/{token}` still shows the names; observed in the running app ·
  Route: delegated · Trigger: multi-file write (7 files)
- [x] T9 — Lay the public source cards out across the screen width (user request 2026-10-28).
  Six cards stacked in a 768px column ran well past one screen while most of the width sat
  empty, which defeats a glance page. Responsive grid (1 col → 2 at `md` → 3 at `xl`) in a
  `max-w-7xl` container, vertical rhythm tightened, full title carried as a tooltip since
  narrow columns truncate harder · AC: measured, not eyeballed — content height vs viewport ·
  Route: inline · Trigger: none (view-only change, 3 files)
- [x] T9b — Reclaim the header's wasted height for the cards (user request 2026-10-28).
  The top card spent ~130px to show one number and a button. Now a single slim bar; on the
  token page the down list became a compact grid of rows instead of one full-width box per
  monitor. Empty-state copy unified to "Totes les webs actives" on both pages — a deliberate
  wording change, so the two tests asserting the old sentence were updated to match, NOT to
  make a red test go green · Route: inline · Trigger: none (view-only, 3 views + 2 tests)

## Checks
- TDD: strict (source: detected runner `composer test` — PHPUnit; matches `openspec/config.yaml`)
- Live target: https://webmonitor.ddev.site (observed via `ddev describe`; DDEV reassigns host
  ports each restart)
- Test runner: `ddev exec composer test` · Build: `ddev exec npm run build` (T7d made the public
  pages depend on `public/build/`, which is **gitignored** — deploys must run it)
- Commits: **on** (source: explicit user instruction mid-feature, 2026-10-22). Local commits on
  this branch only; push/PR/merge were never authorized and have not happened.
- Commit style: this repo's own `scope: imperative`, NOT the global `PRJ-XXX:` Jira format.
- Base ref: `a5650a3` · Known pre-existing failures: none

## Progress
- Review rounds: slice 1 (T1 persistence) and slice 2 (T2 fetchers) each had their lens round
  and remediation and are **closed**. Round 3 covered `e91ce3d..HEAD` — tier **high**, 4 lenses,
  reasons all `signal`, 2024 lines over 29 files; the candidate exceeded the serializer's bound
  so it went out as two pathspec slices covering every changed path.
- **Round cap reached.** Per candidate: one lens round, one remediation round, then stop. T7a–e
  is that remediation; it was verified by the parent's own suite runs, direct behavioural probes
  and live observation — never by a second lens pass.
- Delivery: `force-chained`. Remediation committed as one work unit (`56ecda5`, 1651 lines):
  its edits interleave inside shared files, so splitting it by path would have invented
  boundaries rather than recorded them.

## Review boundary
- Last reviewed boundary: `794d0a3` (end of round 3's candidate). The T4 deferral is closed —
  it was inside that candidate and is no longer unreviewed.
- `56ecda5` (the remediation) is **deliberately not re-reviewed**: the round cap forbids it.

## Evidence
- Suite: 186 baseline → 218 (T2) → 308 (T6) → 327 (T7a) → **360 passed (801 assertions)**, each
  re-run by the parent, zero regressions.
- Parent-verified directly, not taken from any envelope: `migrate` alone (no `--seed`) installs
  all 6 sources; whitespace-only `external_id` rejected; `safeUrl()` nulls `javascript:` and
  `data:` and passes `http(s)`; `alerts.check-now` carries `throttle:5,1`; `ddev artisan migrate`
  reports "Nothing to migrate" so the guarded `up()` is a true no-op; `severityRankSql` appears
  only in the shared trait, zero times in either controller.
- Sabotage-proven across rounds: dropping the unique index, clearing `$casts`, swapping
  `updateOrCreate` for `firstOrCreate`, removing the HTTP timeout, reordering `SEVERITIES`, and
  breaking `is_security DESC` (which failed assertions in *both* controllers' tests at once —
  the proof the two surfaces now share one implementation).
- T6 `Live: performed` — correct token → 200 with `noindex` + `no-referrer`; wrong token → 404;
  exactly the two DOWN monitors listed and neither UP one; 28 outbound links all
  `rel="noreferrer noopener"`; 0 console errors. Empty-token 404 not observed live (it would
  mean editing the user's `.env`) — covered by test and by the `abort_if` preceding `hash_equals`.
- T7a `Live: performed`, with an honest limitation: all six cards render after the trait
  refactor, caps and ordering intact — but **no "Medium" pill is visible on live data**, so the
  user-visible half was not observed. The census explains why and confirms the fix is right:
  angular holds 14 high + 6 medium, laravel 2 critical + 11 high + 13 medium + 1 low, so higher
  severities fill both caps. Verified directly instead: `medium` → rank 2, label "Medium".
- T7d/T7e `Live: performed` — public page CSS now served from the app's own origin, zero
  third-party requests. Stale badge observed on **both** screens by temporarily ageing one
  source (vue → 5h): 5 Healthy + 1 "Stale · Checked 5 hours ago", then restored to its exact
  original timestamp. Temporary admin created for each check and deleted after (user count back
  to 2); browser closed and `.playwright-cli/` artifacts deleted each time.
- T8 `Live: performed` — `GET /overview` with no session and no token: 200, `noindex`,
  `no-referrer`, "2 webs caigudes", login button → `/login`, severity pills now rendering
  (3 Critical, 7 High, 1 Low). **Leak guard checked against the real dev data**, not only in
  test: zero occurrences of "Manusa", "mansua", "manusa.btisdev.com", "mansua.com" or
  "Col·lectiu" anywhere in the response. `/overview/{token}` still names both down monitors.
  The worker made the guarantee structural — `open()` passes an int count, never a Monitor
  collection — and sabotage-proved it by rendering a name and watching `assertDontSee` fail.
- **Styling regression found by T8's live check and fixed here.** `tailwind.config.js` scanned
  `resources/views/**` but not `app/`. T7a and T7e had moved the badge class strings out of
  Blade into `AlertFinding::severityBadge()` and `AlertSource::healthBadge()`, so Tailwind never
  saw them and dropped `bg-orange-100` and `bg-yellow-100` from the build: the High and Medium
  severity pills and the **Stale badge** were rendering with no styling at all. Added
  `./app/**/*.php` to the content globs and rebuilt.
  **This also corrects the T7e live record above**: that observation confirmed the *text*
  "Stale" was present, not that it was styled — an accessibility snapshot reports DOM text, not
  what CSS computes, which is exactly the trap `odd-live-check` warns about. Re-verified
  properly this time via computed style: `rgb(254,249,195)` on `rgb(133,77,14)` — yellow-100 on
  yellow-800.
- T9 `Live: performed` — measured `document.body` height against `window.innerHeight` at five
  viewport sizes rather than judging by eye. Open page fits with **no scroll** at 1920x1080,
  1512x945, 1440x900 and 1280x800; ~32px over at 1366x768. The token page runs ~21px over at
  1512x945 because it additionally carries the named down-monitor card — expected, and it still
  shows both names, so no regression from the layout change.
- T9b `Live: performed` — measured the container's real bottom edge, not `body` height, since
  `min-h-screen` floors the latter to the viewport and would have hidden any overflow. Open page
  content now ends at **673px** and the token page at **735px**, constant across widths at three
  columns: the open page fits with no scroll down to a 720px-tall window, the token page down to
  768px. Leak guard re-checked against real data after the rewrite — still zero occurrences of
  any monitor name or URL on the open page, count still correct.
- T7b's flash was **not** observed live: pressing "Check now" spends real GitHub quota, and the
  failure branch cannot be triggered without breaking a feed. Both branches are covered by test
  (RED observed: `Session has unexpected key [success]`).

## Recorded findings — not fixed, by disposition
- Carried from earlier rounds: `config` JSON keys undocumented and `affects` comma-joined;
  `key` would read better as `ecosystem`; `down()` drops findings that RSS windows make
  unrecoverable — snapshot before any rollback; a composite `(alert_source_id, published_at)`
  index would subsume the standalone one (no load evidence).
- The response body-size guard is best-effort: Guzzle has already buffered by the time the
  response exists, so the `Content-Length` pre-check bounds the parse step, not the buffer.
- Round 3, accepted rather than fixed: the public page lists **every** user's down monitors
  behind one global token — deliberate (user rejected both an open URL and a per-monitor flag),
  and the app already treats monitors as shared (`ProfileController` hands every monitor to any
  authenticated user). Revisit together if that ever stops being true.
- Round 3, not fixed: `checkNow()` still runs six synchronous fetches in-request, so a
  non-`AlertFeedException` 500s the POST and skips the remaining sources; the ranking subquery
  has no date bound, so cost grows with an append-only table that has no retention policy;
  `AlertSourceSeeder` carries a `security_only`-specific `Schema::hasColumn` check that each
  future column will need again.
- Pre-existing, carried forward: `NoPrivateUrl` is TOCTOU and silent on DNS failure — do not
  reach for it as the SSRF control; job `backoff = 10` has no jitter, so the three GitHub
  sources retry inside the same rate-limit window; `phpunit.xml` sets no
  `failOnWarning`/`failOnRisky`; `vendor/bin/phpcs` is not installed; **there is no CI**, so
  every contract pinned here holds only as long as someone runs the suite locally.

## Next step
Feature complete through T9b. Commits `56ecda5` (remediation), `3dd72a5` (open page + Tailwind
glob fix), `b15c0a8` (grid layout) and `e9d25fb` (header compaction); nothing is pushed and no
PR exists, delivery stays the user's call. If this is deployed, the deploy must
run `npm run build` (T7d) and have a queue worker plus the scheduler running, or the new Stale
badge will be telling the truth about a dead pipeline.
