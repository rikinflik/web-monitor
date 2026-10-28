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
<body class="bg-gray-100 min-h-screen py-10 px-4">
    <div class="max-w-3xl mx-auto space-y-6">

        {{--
            T8: this card is deliberately the ONLY place $downMonitorCount is
            used, and it is an int handed in by the controller — never a
            Monitor collection. There is structurally nothing here (no
            ->name, no ->url, no ->outageDuration()) for a future edit to
            quietly start rendering, the way there is on the token page's
            equivalent block above.
        --}}
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h1 class="text-2xl font-bold text-gray-800">Status Overview</h1>
                <p class="text-sm text-gray-500 mt-1">A summary of current status and recent technology alerts.</p>
            </div>

            <div class="p-6 space-y-4">
                @if ($downMonitorCount === 0)
                    <div class="rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm font-medium">
                        Totes les webs estan actives. Res a reportar.
                    </div>
                @else
                    <div class="rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm font-medium">
                        {{ $downMonitorCount }} web{{ $downMonitorCount === 1 ? '' : 's' }} caigud{{ $downMonitorCount === 1 ? 'a' : 'es' }}.
                    </div>
                @endif

                <a
                    href="{{ route('login') }}"
                    class="inline-block px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700"
                >Log in</a>
            </div>
        </div>

        @include('public-overview-alerts', ['sources' => $sources, 'findingsPerSource' => $findingsPerSource])

        <p class="text-center text-xs text-gray-400">Powered by WebMonitor</p>
    </div>
</body>
</html>
