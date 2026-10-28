<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Status Overview</title>
    {{--
        Compiled locally via Vite, not loaded from cdn.tailwindcss.com. Two
        reasons, both load-bearing:
        - This page's URL is the only secret (/overview/{token}). A
          third-party script executing in-page can read window.location.href
          and exfiltrate the token — the one leak channel the deliberate
          Referrer-Policy: no-referrer + rel="noreferrer noopener" hardening
          in PublicOverviewController does not close.
        - This page is consulted precisely when things are broken, and
          cdn.tailwindcss.com is a JIT dev CDN: if it is blocked or down the
          page renders unstyled, losing the DOWN/Healthy colour coding that
          carries the entire message.
        Only the CSS bundle is pulled in: this page is static/non-interactive
        (no Alpine x-data, no DataTables usage here), so app.js would add
        jQuery/Alpine/DataTables weight for zero behavioural benefit.
    --}}
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 min-h-screen py-4 px-4">
    <div class="max-w-7xl mx-auto space-y-4">

        <div class="bg-white shadow-lg rounded-lg px-6 py-3 space-y-3">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <h1 class="text-lg font-bold text-gray-800 mr-auto">Status Overview</h1>
                @if ($downMonitors->isEmpty())
                    <span class="rounded-full bg-green-100 text-green-800 px-3 py-1 text-sm font-semibold">
                        Totes les webs actives
                    </span>
                @else
                    <span class="rounded-full bg-red-100 text-red-800 px-3 py-1 text-sm font-semibold">
                        {{ $downMonitors->count() }} web{{ $downMonitors->count() === 1 ? '' : 's' }} caigud{{ $downMonitors->count() === 1 ? 'a' : 'es' }}
                    </span>
                @endif
            </div>

            {{--
                Compact grid rather than one full-width box per monitor: the
                down list is the smaller half of this page and was eating the
                height the alert cards need. Names and URLs still render here
                — this is the token-gated page, unlike public-overview-open.
            --}}
            @unless ($downMonitors->isEmpty())
                <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($downMonitors as $monitor)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg bg-red-50 border border-red-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-sm text-gray-800 truncate" title="{{ $monitor->name }}">{{ $monitor->name }}</p>
                                <a
                                    href="{{ $monitor->url }}"
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    class="block text-xs text-gray-500 hover:text-gray-700 truncate"
                                    title="{{ $monitor->url }}"
                                >{{ $monitor->url }}</a>
                            </div>
                            <span class="shrink-0 px-2 py-0.5 rounded-full bg-red-500 text-white text-xs font-bold">
                                DOWN{{ $monitor->outageDuration() ? ' · '.$monitor->outageDuration() : '' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endunless
        </div>

        @include('public-overview-alerts', ['sources' => $sources, 'findingsPerSource' => $findingsPerSource])

        <p class="text-center text-xs text-gray-400">Powered by WebMonitor</p>
    </div>
</body>
</html>
