<?php

namespace App\Http\Controllers;

use App\Models\AlertSource;
use App\Models\Monitor;
use App\Support\ConstrainsFindingsPerSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

/**
 * Two public (no-login) overview surfaces, split by confidentiality: the
 * technology alerts are already public (sourced from php.net, drupal.org,
 * wordpress.org, GitHub Advisories), but which client monitors exist, their
 * URLs, and whether they are down right now, is not.
 *
 * - show() — token-gated (`/overview/{token}`): every monitor currently
 *   down, by name, plus the alerts. Gated by a single global, unguessable
 *   token (config('monitoring.public_overview_token')) rather than
 *   per-monitor opt-in — the user deliberately rejected both a fixed open
 *   URL (would expose which client sites are down to anyone who finds it)
 *   and a per-monitor flag (easy to forget to set). An empty or unset
 *   configured token disables the route entirely: it 404s, the same as a
 *   wrong token and the same as a route that does not exist at all, so a
 *   guesser learns nothing from the response.
 * - open() — token-free (`/overview`, T8): the same alerts, but only a
 *   COUNT of monitors down, plus a login button. Meant to sit as the user's
 *   browser home page, where a token in the URL would serve badly.
 *
 * Two properties protect both pages once reachable:
 * - noindex (`<meta name="robots">` + `X-Robots-Tag` header) keeps them out
 *   of search engines.
 * - `Referrer-Policy: no-referrer`, paired with `rel="noreferrer noopener"`
 *   on every outbound link in the views, stops the token route's own URL
 *   (which carries the secret) from being leaked via the `Referer` header
 *   to the third-party sites (github.com, php.net, drupal.org,
 *   wordpress.org) both pages link to as advisory sources. The open page
 *   carries no token to leak, but keeps the same hardening since it links
 *   out to the same third parties and has no reason to be indexed either.
 */
final class PublicOverviewController extends Controller
{
    use ConstrainsFindingsPerSource;

    /**
     * How many of a source's most recent findings this page renders —
     * tighter than the authenticated dashboard's cap (AlertController::
     * FINDINGS_PER_SOURCE = 10) since this is a glance-only public summary,
     * not the working dashboard. The true total is still shown alongside
     * the cap so nothing looks silently hidden.
     */
    private const FINDINGS_PER_SOURCE = 5;

    /**
     * Render the public overview, or 404 when the supplied token does not
     * match the configured one.
     *
     * The configured-empty check happens before hash_equals() and is
     * unconditional: hash_equals('', '') is true in PHP, so without this
     * guard an unset/empty configured token would let an empty supplied
     * token through. A route segment can never actually be empty in
     * practice, but the guard does not rely on that — it is correct even if
     * called directly.
     */
    public function show(string $token): Response
    {
        $configuredToken = (string) config('monitoring.public_overview_token', '');

        abort_if($configuredToken === '', 404);
        abort_unless(hash_equals($configuredToken, $token), 404);

        $downMonitors = Monitor::query()
            ->where('status', Monitor::STATUS_DOWN)
            ->orderBy('name')
            ->get();

        $sources = $this->loadSources();

        $findingsPerSource = self::FINDINGS_PER_SOURCE;

        return response()
            ->view('public-overview', compact('downMonitors', 'sources', 'findingsPerSource'))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Render the open, no-token counterpart (T8): the same technology
     * alerts, but only a COUNT of monitors currently down — never a name or
     * URL. That guarantee is structural, not a rendering choice: this
     * method never queries Monitor columns beyond `status`, so a monitor's
     * identity never exists in a PHP variable this request could hand to a
     * view in the first place. Intended as the user's own browser home
     * page, so it carries no token and needs no session.
     */
    public function open(): Response
    {
        $downMonitorCount = Monitor::query()
            ->where('status', Monitor::STATUS_DOWN)
            ->count();

        $sources = $this->loadSources();

        $findingsPerSource = self::FINDINGS_PER_SOURCE;

        return response()
            ->view('public-overview-open', compact('downMonitorCount', 'sources', 'findingsPerSource'))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * The tracked technology alert sources, each with its findings capped
     * to FINDINGS_PER_SOURCE via the shared trait — identical for both the
     * token-gated and open pages, since the alerts are already public.
     *
     * @return Collection<int, AlertSource>
     */
    private function loadSources(): Collection
    {
        return AlertSource::query()
            ->withCount('findings')
            ->with(['findings' => fn ($query) => $this->constrainToRecentPerSource($query)])
            ->orderBy('name')
            ->get();
    }
}
