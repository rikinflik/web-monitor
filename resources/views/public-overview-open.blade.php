<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Status Overview</title>
    {{--
        No token lives in this URL — unlike /overview/{token}, there is
        nothing here for a third-party script to exfiltrate via
        window.location.href. noindex and Referrer-Policy: no-referrer
        (set in PublicOverviewController::open()) stay anyway: this page
        links out to third-party advisory sites and has no reason to be
        indexed. Compiled locally via Vite for the same resilience reason
        as the token page: this is a glance-at-a-glance status page, and a
        blocked/down JIT CDN would leave it unstyled right when it matters.
    --}}
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 min-h-screen py-4 px-4">
    <div class="max-w-7xl mx-auto space-y-4">

        {{--
            T8: this card is deliberately the ONLY place $downMonitorCount is
            used, and it is an int handed in by the controller — never a
            Monitor collection. There is structurally nothing here (no
            ->name, no ->url, no ->outageDuration()) for a future edit to
            quietly start rendering, the way there is on the token page's
            equivalent block above.
        --}}
        <div class="bg-white shadow-lg rounded-lg px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <h1 class="text-lg font-bold text-gray-800 mr-auto">Status Overview</h1>

            @if ($downMonitorCount === 0)
                <span class="rounded-full bg-green-100 text-green-800 px-3 py-1 text-sm font-semibold">
                    Totes les webs actives
                </span>
            @else
                <span class="rounded-full bg-red-100 text-red-800 px-3 py-1 text-sm font-semibold">
                    {{ $downMonitorCount }} web{{ $downMonitorCount === 1 ? '' : 's' }} caigud{{ $downMonitorCount === 1 ? 'a' : 'es' }}
                </span>
            @endif

            <a
                href="{{ route('login') }}"
                class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700"
            >Log in</a>
        </div>

        @include('public-overview-alerts', ['sources' => $sources, 'findingsPerSource' => $findingsPerSource])

        <p class="text-center text-xs text-gray-400">Powered by WebMonitor</p>
    </div>
</body>
</html>
