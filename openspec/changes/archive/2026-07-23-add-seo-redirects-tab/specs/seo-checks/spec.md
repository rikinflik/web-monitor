# SEO Checks Specification

## Purpose

Provide per-URL SEO/redirect hygiene checks (www canonicalization, HTTPS
canonicalization, trailing-slash canonicalization, `robots.txt` reachability,
and sitemap reachability) for the same shared list of URLs already tracked by
the Monitor feature. Checks run periodically on their own cadence and can be
manually re-run per entry with immediate feedback, with current state and a
lightweight history persisted, without altering existing Monitor behavior and
while minimizing outbound HTTP requests per check cycle.

## Requirements

### Requirement: Shared URL Source of Truth

The `monitors` table MUST remain the single source of truth for every tracked
URL's `name`, `url`, and ownership. The SEO feature MUST NOT introduce an
independent URL field. Each `monitors` row MUST have exactly one linked
`seo_checks` row (one-to-one via `seo_checks.monitor_id`, unique). A URL added
from either the Monitor tab or the SEO tab MUST result in exactly one `monitors`
row and exactly one linked `seo_checks` row, so both tabs always list the
identical set of URLs.

The paired `seo_checks` row MUST be auto-created when a `Monitor` is created
(via a `Monitor::created` observer/hook) with default interval and default
(unresolved) check state, so the invariant "every monitor has exactly one
seo_checks row" holds regardless of which tab the URL was added from. Failure to
create the paired row MUST NOT roll back or break Monitor creation.

#### Scenario: Adding a URL from the Monitor tab appears in both tabs

- GIVEN an authenticated user on the Monitor tab
- WHEN the user submits the add-monitor form with a valid name and URL
- THEN exactly one `monitors` row is created
- AND exactly one linked `seo_checks` row is auto-created with default interval and unresolved check state
- AND the URL appears in both the Monitor index list and the SEO index list

#### Scenario: Adding a URL from the SEO tab appears in both tabs

- GIVEN an authenticated user on the SEO tab
- WHEN the user submits the add-URL form with a valid name and URL
- THEN exactly one `monitors` row is created (with sensible interval/timeout/expected_status_code defaults for the Monitor side)
- AND exactly one linked `seo_checks` row is auto-created
- AND the URL appears in both the SEO index list and the Monitor index list

#### Scenario: One-to-one invariant holds on every monitor creation

- GIVEN any code path that calls `Monitor::create(...)`
- WHEN the monitor is persisted
- THEN a single paired `seo_checks` row exists for that monitor
- AND no monitor ever has zero or more than one `seo_checks` row

#### Scenario: Paired-row creation failure does not break monitor creation

- GIVEN the paired `seo_checks` insert fails for an edge-case reason
- WHEN a `Monitor` is created
- THEN the `Monitor` creation still succeeds (the failure is logged and execution continues, not thrown)

#### Scenario: Deleting a monitor removes its SEO data

- GIVEN a monitor with a linked `seo_checks` row and `seo_check_logs` history
- WHEN the monitor is deleted
- THEN the linked `seo_checks` row is removed via cascade delete
- AND its `seo_check_logs` rows are removed via cascade delete
- AND no `monitors`/`monitor_logs` data for other URLs is affected

### Requirement: Two-Table Current-State and History Split

The feature MUST persist SEO state across two tables mirroring the
Monitor/MonitorLog pattern:

- `seo_checks` MUST hold the current state per URL: `monitor_id`, the five check
  result columns (`www_redirect`, `https_redirect`, `trailing_slash_redirect`,
  `robots_ok`, `sitemap_ok`), its own `interval` (minutes), `last_checked_at`,
  and timestamps.
- `seo_check_logs` MUST hold append-only history rows: `seo_check_id`, the five
  result values at that point in time, `checked_at`, and timestamps (FK to
  `seo_checks`, cascade delete).

History writes MUST be deduplicated the same way `MonitoringService::recordLog()`
avoids inserting unchanged consecutive rows: a new `seo_check_logs` row MUST be
inserted only when at least one of the five result values differs from the most
recent log row for that entry.

Schema changes MUST be additive (new tables only), one migration per logical
change, with symmetric `up()`/`down()` methods, and MUST include an index on
`seo_checks.last_checked_at` mirroring the existing `monitors.last_checked_at`
index for the periodic-scan query.

#### Scenario: Current state is updated on every check

- GIVEN an SEO check runs for an entry
- WHEN the service computes the five results
- THEN the entry's `seo_checks` row is updated with the five result columns and a fresh `last_checked_at`

#### Scenario: History row inserted only when results change

- GIVEN an entry whose latest `seo_check_logs` row has result set R
- WHEN a new check produces result set R (identical to the latest)
- THEN no new `seo_check_logs` row is inserted
- WHEN a subsequent check produces a result set differing in at least one of the five values
- THEN exactly one new `seo_check_logs` row is inserted with the new values and a `checked_at` timestamp

#### Scenario: Migrations are reversible with no impact on Monitor data

- GIVEN the SEO migrations have been applied
- WHEN the migrations are rolled back via their `down()` methods
- THEN the `seo_checks` and `seo_check_logs` tables are dropped
- AND `monitors` and `monitor_logs` data is unaffected

### Requirement: Five Per-URL SEO Checks With Fixed Result Vocabularies

The service MUST compute exactly five checks per URL, each recording a value from
its exact vocabulary:

- **www redirect**: one of `www→no-www`, `no-www→www`, or `none`.
- **https redirect**: one of `http→https`, `https→http`, or `none`.
- **trailing slash redirect**: one of `with /`, `without /`, or `none`.
- **robots.txt**: a boolean where `GET {origin}/robots.txt` returning HTTP 200
  MUST be recorded as OK (✓) and any other outcome as not-OK (✗).
- **sitemap**: a boolean where `GET {origin}/sitemap.xml` OR
  `GET {origin}/sitemap_index.xml` returning HTTP 200 MUST be recorded as OK (✓)
  and any other outcome as not-OK (✗).

The three redirect checks (www, https, trailing slash) MUST be derived from the
actual redirect behavior of the stored URL and MUST NOT be inferred from any
value the service did not observe.

#### Scenario: www redirect direction is classified

- GIVEN the stored URL redirects to a target differing only by the `www.` host prefix
- WHEN the check runs
- THEN the www result is `www→no-www` when the prefix is removed, or `no-www→www` when the prefix is added
- AND when the host does not differ by exactly the `www.` prefix, the www result is `none`

#### Scenario: https redirect direction is classified

- GIVEN the stored URL redirects to a target differing in scheme
- WHEN the check runs
- THEN the https result is `http→https` when upgrading, or `https→http` when downgrading
- AND when the scheme does not differ, the https result is `none`

#### Scenario: trailing-slash redirect direction is classified

- GIVEN the stored URL redirects to a target differing only by a trailing `/` in the path
- WHEN the check runs
- THEN the trailing-slash result is `with /` when a trailing slash is added, or `without /` when it is removed
- AND when the path does not differ by exactly a trailing slash, the trailing-slash result is `none`

#### Scenario: robots.txt reachability

- GIVEN a `GET {origin}/robots.txt`
- WHEN it returns HTTP 200
- THEN `robots_ok` is recorded as ✓ (true)
- AND WHEN it returns any non-200 status or errors, `robots_ok` is recorded as ✗ (false)

#### Scenario: sitemap reachability with fallback

- GIVEN a `GET {origin}/sitemap.xml`
- WHEN it returns HTTP 200
- THEN `sitemap_ok` is recorded as ✓ (true) and no fallback request is made
- AND WHEN `sitemap.xml` does not return 200, a single `GET {origin}/sitemap_index.xml` is made
- AND `sitemap_ok` is ✓ (true) only if the fallback returns HTTP 200, otherwise ✗ (false)

### Requirement: Redirect Detection Uses No-Follow Requests

All redirect-related requests MUST be issued with Guzzle `allow_redirects` set
to `false` (explicitly) and `http_errors` set to `false`, isolated in a new
`SeoCheckService`. The service MUST determine redirect outcomes by reading the
`Location` response header of a 3xx response, comparing the stored URL and the
`Location` target by scheme, host, and path. The existing `MonitoringService`
redirect-following behavior for uptime checks MUST NOT be modified.

#### Scenario: Redirect target is read from the Location header without following

- GIVEN the stored URL returns a 3xx response with a `Location` header
- WHEN the service issues its probe request
- THEN the request is issued with `allow_redirects => false` and `http_errors => false`
- AND the service reads the `Location` header to classify the redirect dimensions rather than following the redirect

#### Scenario: Existing Monitor uptime check is untouched

- GIVEN the existing `MonitoringService::check()` uptime path
- WHEN SEO checks are added
- THEN `MonitoringService` continues to use its existing implicit redirect-following behavior
- AND no SEO code path modifies `MonitoringService`

### Requirement: Request-Minimizing Probe Design

The service MUST resolve all three canonicalization checks (www, https, trailing
slash) from a single redirect probe request to the stored URL. If that response
is a 3xx with a `Location` header, the service MUST diff scheme/host/path to
classify each dimension; any dimension that does not differ MUST be recorded as
`none`. If the response is not a redirect (or a redirect that changes none of the
three dimensions), all three MUST be recorded as `none` from that same single
request, with no additional probing requests for www/https/trailing slash.

Total outbound requests per check cycle MUST be capped: 1 redirect probe + 1
`robots.txt` request + 1 sitemap request (with at most 1 sitemap fallback). The
common case MUST be 3 requests (probe + robots + `sitemap.xml` hit) and the worst
case MUST be at most 4 requests (sitemap fallback needed). The service MUST NEVER
issue one request per individual check dimension.

#### Scenario: Single probe resolves all three canonicalization checks

- GIVEN one redirect probe of the stored URL
- WHEN the service classifies www/https/trailing-slash
- THEN no additional outbound request is issued for any of those three dimensions

#### Scenario: Non-redirecting URL yields none without extra requests

- GIVEN the stored URL returns 200 (or a redirect changing none of the three dimensions)
- WHEN the probe completes
- THEN www, https, and trailing-slash results are all `none`
- AND no extra probe request is issued to test hypothetical variants

#### Scenario: Outbound request count is bounded

- GIVEN a full SEO check cycle for one entry
- WHEN `sitemap.xml` returns 200
- THEN exactly 3 outbound requests are made (probe + robots + sitemap.xml)
- AND WHEN the sitemap fallback is needed, at most 4 outbound requests are made (probe + robots + sitemap.xml + sitemap_index.xml)

### Requirement: Manual Synchronous Re-Check

The feature MUST expose a per-entry manual re-check via `POST /seo/{seoCheck}/recheck`
inside the authenticated route group. The re-check MUST dispatch the SEO check
synchronously (`CheckSeoJob::dispatchSync($seoCheck)`) inline in the request, so
fresh results are shown immediately regardless of the configured queue
connection. After completion it MUST redirect back to `seo.index` with a flash
message and the updated results and `last_checked_at`.

A bounded per-request timeout (mirroring `MonitoringService`'s timeout option)
MUST be applied so a single slow/unreachable URL cannot hang the controller
indefinitely.

#### Scenario: Manual re-check shows fresh results immediately

- GIVEN an authenticated owner viewing an entry in the SEO index
- WHEN the owner submits that entry's re-check form
- THEN the SEO check runs synchronously in the request
- AND the response redirects to `seo.index` with a flash message
- AND the entry's freshly computed results and updated `last_checked_at` are shown, without depending on a background queue worker

#### Scenario: Slow URL cannot hang the re-check indefinitely

- GIVEN a stored URL that is slow or unreachable
- WHEN a manual re-check runs
- THEN each outbound request is bounded by a per-request timeout
- AND the controller returns rather than hanging indefinitely

### Requirement: Independent Periodic Scheduling

The periodic SEO scheduler MUST be a separate `Schedule::call(...)->everyMinute()`
block in `routes/console.php`, decoupled from Monitor's uptime scheduling block.
It MUST iterate `SeoCheck::cursor()` and dispatch `CheckSeoJob` (queued via normal
`dispatch()`) when an entry's `last_checked_at` is null or its own `interval`
(minutes) has elapsed. `seo_checks.interval` MUST be independent of
`monitors.interval` and MUST default to a much longer period (e.g. 1440 minutes /
daily). SEO checks MUST NOT run on Monitor's frequent uptime cadence, and the
Monitor scheduling loop MUST remain unaltered.

#### Scenario: SEO entry is checked when due

- GIVEN an SEO entry whose `last_checked_at` is null or whose `interval` minutes have elapsed
- WHEN the SEO scheduler tick runs
- THEN a `CheckSeoJob` is dispatched (queued) for that entry

#### Scenario: SEO entry is not checked before its interval elapses

- GIVEN an SEO entry checked recently within its `interval`
- WHEN the SEO scheduler tick runs
- THEN no `CheckSeoJob` is dispatched for that entry

#### Scenario: SEO cadence is decoupled from Monitor cadence

- GIVEN the SEO scheduling block and the Monitor scheduling block
- WHEN the scheduler runs
- THEN SEO checks are gated by `seo_checks.interval` (defaulting to daily), independent of `monitors.interval`
- AND the existing Monitor per-minute uptime loop is unchanged

### Requirement: SEO Tab UI

The application MUST present a new "SEO / Redirects" tab beside the existing
"Monitor" tab in both desktop and mobile navigation, with active state driven by
`request()->routeIs('seo.*')`, without altering the existing Monitor tab markup
or behavior. The SEO index MUST render a table with one column per check
(www, https, trailing slash, robots.txt, sitemap), a colored badge/pill or icon
per cell (green for OK/normalized, red for a detected mismatch/failure, gray for
`none`/not-applicable), a relative "checked X ago" column via `diffForHumans()`
(with a "never checked" fallback consistent with the existing Monitor UI), and a
per-row manual re-check control. The SEO tab MUST provide its own minimal
add-URL form (name + url only) that reuses the existing input styling and the
`NoPrivateUrl` validation rule and lands the new URL in the shared list. Entries
MUST be ownership-scoped via the linked monitor's `user_id`.

#### Scenario: SEO tab appears without altering the Monitor tab

- GIVEN the authenticated navigation
- WHEN the page renders
- THEN a "SEO / Redirects" nav link appears beside "Monitor" in both desktop and mobile navigation
- AND its active state is highlighted when the current route matches `seo.*`
- AND the existing "Monitor" nav link markup and behavior are unchanged

#### Scenario: SEO index table renders one column per check with colored cells

- GIVEN an owner with SEO entries
- WHEN the SEO index renders
- THEN each row shows name, url, and one cell per check (www, https, trailing slash, robots.txt, sitemap)
- AND each cell is a colored badge/icon: green for OK/normalized, red for mismatch/failure, gray for `none`/not-applicable
- AND a "checked X ago" relative-time column is shown, with a never-checked fallback for entries never checked

#### Scenario: Ownership isolation on the SEO index

- GIVEN two users each owning distinct monitors
- WHEN a user views the SEO index
- THEN only SEO entries linked to monitors owned by that user are listed
- AND attempting to re-check an entry linked to another user's monitor is forbidden

#### Scenario: SEO add-URL form validates and lands in the shared list

- GIVEN an authenticated user on the SEO create form
- WHEN the user submits an invalid or private/SSRF-risk URL
- THEN validation errors are returned (including the `NoPrivateUrl` rule)
- AND WHEN the user submits a valid name and URL, a shared `monitors` row and its paired `seo_checks` row are created and appear in both tabs

### Requirement: No Regression To Existing Monitor Functionality

The change MUST NOT alter existing Monitor behavior, data, validation, routes, or
views beyond the additive nav-link entry, the additive `Monitor::created`
observer/hook, and the additive route/schedule blocks. Existing Monitor and
MonitoringService tests MUST continue to pass with zero regressions.

#### Scenario: Existing Monitor flows continue to work

- GIVEN the SEO feature is added
- WHEN existing Monitor create/index/show/edit/delete and uptime-check flows run
- THEN they behave identically to before the change
- AND existing `MonitoringServiceTest` and `MonitorControllerTest` suites pass with no regressions
