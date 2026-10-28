{{--
    Shared "Technology Alerts" card, included by both public-overview.blade.php
    (token-gated) and public-overview-open.blade.php (open, no token) — T8
    requires the two surfaces to render alerts identically, and this partial
    is what keeps that true rather than two copies silently drifting apart.

    Expects:
    - $sources: Collection<AlertSource> with `findings` eager-loaded and
      capped (see App\Support\ConstrainsFindingsPerSource) and `findings_count`
      available via withCount().
    - $findingsPerSource: int, the per-source cap, for the "Showing N most
      recent findings per source" prose.
--}}
<div class="bg-white shadow-lg rounded-lg overflow-hidden">
    <div class="px-6 py-3 border-b border-gray-100">
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Technology Alerts</h2>
        <p class="text-xs text-gray-400 mt-1">Showing the {{ $findingsPerSource }} most recent findings per source, security-flagged findings first.</p>
    </div>

    {{--
        Grid, not a vertical stack: six stacked cards pushed the whole page
        well past one screen on a wide display while leaving most of the
        width empty. This page is meant to be a browser home page answering
        "is anything on fire" at a glance, so fitting without scrolling is
        the point. Falls back to one column on narrow screens.
    --}}
    <div class="p-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($sources as $source)
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-base font-semibold text-gray-900">{{ $source->name }}</h3>
                    <div class="flex items-center space-x-2">
                        @php($healthBadge = $source->healthBadge())
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $healthBadge['classes'] }}">{{ $healthBadge['label'] }}</span>
                        {{-- Without this, a month-dead source and one checked a minute ago
                             were pixel-identical here — this page previously rendered no
                             timestamp at all, unlike the authenticated dashboard. --}}
                        <span class="text-xs text-gray-400">
                            Checked {{ $source->last_checked_at?->diffForHumans() ?? 'never' }}
                        </span>
                    </div>
                </div>

                @if ($source->last_error)
                    <div class="mb-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2">
                        <p>{{ $source->last_error }}</p>
                        <p class="text-xs text-red-500 mt-1">
                            Last successful check: {{ $source->last_success_at?->diffForHumans() ?? 'never' }}
                        </p>
                    </div>
                @endif

                @if ($source->findings_count > 0)
                    <p class="text-xs text-gray-500 mb-2">
                        Showing {{ $source->findings->count() }} of {{ $source->findings_count }} finding{{ $source->findings_count === 1 ? '' : 's' }}
                    </p>
                @endif

                <ul class="space-y-2">
                    @forelse ($source->findings as $finding)
                        <li class="flex items-center justify-between text-sm">
                            @if ($finding->safeUrl())
                            <a
                                href="{{ $finding->safeUrl() }}"
                                target="_blank"
                                rel="noreferrer noopener"
                                class="text-indigo-600 hover:text-indigo-900 truncate mr-3"
                                title="{{ $finding->title }}"
                            >{{ $finding->title }}</a>
                            @else
                            {{-- Narrow grid columns truncate hard, so carry the full
                                 title in the tooltip rather than losing it. --}}
                            <span class="truncate mr-3" title="{{ $finding->title }}">{{ $finding->title }}</span>
                            @endif
                            <span class="shrink-0 flex items-center space-x-1">
                                @if ($finding->is_security)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Security</span>
                                @endif
                                @php($severityBadge = $finding->severityBadge())
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $severityBadge['classes'] }}">{{ $severityBadge['label'] }}</span>
                                <span class="text-xs text-gray-400">{{ $finding->published_at?->diffForHumans() ?? 'Unknown date' }}</span>
                            </span>
                        </li>
                    @empty
                        <li class="text-sm text-gray-500">No findings recorded yet.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>
</div>
