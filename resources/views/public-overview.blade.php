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

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h1 class="text-2xl font-bold text-gray-800">Status Overview</h1>
                <p class="text-sm text-gray-500 mt-1">Monitors currently down and recent technology alerts.</p>
            </div>

            <div class="p-6">
                <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Down Monitors</h2>

                @if ($downMonitors->isEmpty())
                    <div class="rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm font-medium">
                        All monitors are currently up. Nothing to report.
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($downMonitors as $monitor)
                            <div class="flex items-center justify-between p-3 rounded-lg bg-red-50 border border-red-100">
                                <div>
                                    <p class="font-semibold text-gray-800">{{ $monitor->name }}</p>
                                    <a
                                        href="{{ $monitor->url }}"
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        class="text-xs text-gray-500 hover:text-gray-700 break-all"
                                    >{{ $monitor->url }}</a>
                                </div>
                                <span class="shrink-0 ml-4 px-3 py-1 rounded-full bg-red-500 text-white text-xs font-bold">
                                    DOWN{{ $monitor->outageDuration() ? ' · '.$monitor->outageDuration() : '' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @include('public-overview-alerts', ['sources' => $sources, 'findingsPerSource' => $findingsPerSource])

        <p class="text-center text-xs text-gray-400">Powered by WebMonitor</p>
    </div>
</body>
</html>
