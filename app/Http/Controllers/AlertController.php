<?php

namespace App\Http\Controllers;

use App\Jobs\CheckAlertSourceJob;
use App\Models\AlertSource;
use App\Models\User;
use App\Support\ConstrainsFindingsPerSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Authenticated dashboard for the six tracked AlertSource ecosystems
 * (PHP, Drupal, WordPress, Laravel, Vue, Angular).
 *
 * Sources are global (no user_id): index() is open to any authenticated
 * user, there is no ownership scoping to apply here (unlike
 * MonitorController, which scopes through $request->user()->monitors()).
 * checkNow() forces an immediate poll and is restricted to admins, mirroring
 * the role gate already used by User::canAccessPanel() for the Filament
 * admin panel.
 */
final class AlertController extends Controller
{
    use ConstrainsFindingsPerSource;

    /**
     * How many of a source's most recent findings the dashboard renders.
     * Some sources carry a feed's entire history rather than just recent
     * items — a single real run left php.net/feed.atom with 495 rows for
     * one source alone — so rendering every row is both unusable and
     * unbounded. 10 is a deliberate choice: enough for a single glance-at
     * screen without scrolling, small enough that the query never has to
     * hydrate the long tail the dashboard would not show anyway. The true
     * total is still surfaced via withCount() below, so nothing looks
     * silently hidden.
     */
    private const FINDINGS_PER_SOURCE = 10;

    /**
     * List every tracked source with its recent findings, capped per source
     * and ordered by severity (most severe first) then recency — see
     * AlertFinding::severityRank(). The cap and the ordering are both
     * enforced in SQL (constrainToRecentPerSource()), not in PHP or Blade:
     * loading every row just to display a handful is exactly the cost this
     * guards against.
     */
    public function index(Request $request): View
    {
        $sources = AlertSource::query()
            ->withCount('findings')
            ->with(['findings' => fn ($query) => $this->constrainToRecentPerSource($query)])
            ->orderBy('name')
            ->get();

        $isAdmin = $request->user()->role === User::ROLE_ADMIN;

        return view('alerts.index', compact('sources', 'isAdmin'));
    }

    /**
     * Force an immediate synchronous poll of every tracked source so the
     * redirect reflects fresh results right away, mirroring how
     * SeoCheckController::recheck() uses CheckSeoJob::dispatchSync().
     */
    public function checkNow(Request $request): RedirectResponse
    {
        abort_if($request->user()->role !== User::ROLE_ADMIN, 403);

        $sources = AlertSource::all();

        $sources->each(fn (AlertSource $source) => CheckAlertSourceJob::dispatchSync($source));

        [$flashKey, $message] = $this->summarizeCheckOutcome($sources->map->fresh());

        return redirect()->route('alerts.index')->with($flashKey, $message);
    }

    /**
     * Build an operator-facing flash that reflects what checkNow() actually
     * did, rather than a hardcoded success string: AlertCheckService::check()
     * swallows every per-source AlertFeedException into last_error and
     * returns normally (see its class docblock), so re-reading each source's
     * freshly-recorded last_error after the synchronous run is the only way
     * to tell a real outcome here — this is the operator's confirmation
     * signal after a manual recovery attempt, so it must carry information
     * rather than always looking identical.
     *
     * @param  Collection<int, AlertSource>  $refreshedSources
     * @return array{0: string, 1: string} the session flash key
     *                                     ('success'|'error') and its message
     */
    private function summarizeCheckOutcome(Collection $refreshedSources): array
    {
        $failed = $refreshedSources->filter(fn (AlertSource $source) => $source->last_error !== null);

        if ($failed->isEmpty()) {
            return ['success', sprintf(
                'Check completed successfully for all %d sources.',
                $refreshedSources->count()
            )];
        }

        return ['error', sprintf(
            '%d of %d source checks failed: %s.',
            $failed->count(),
            $refreshedSources->count(),
            $failed->pluck('name')->implode(', ')
        )];
    }
}
