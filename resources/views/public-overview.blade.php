<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Status Overview</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen py-10 px-4">
    <div class="max-w-3xl mx-auto space-y-6">

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="p-6 border-b border-gray-100">
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

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Technology Alerts</h2>
                <p class="text-xs text-gray-400 mt-1">Showing the 5 most recent findings per source, security-flagged findings first.</p>
            </div>

            <div class="p-6 space-y-6">
                @foreach ($sources as $source)
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-base font-semibold text-gray-900">{{ $source->name }}</h3>
                            <div class="flex items-center space-x-2">
                                @if ($source->last_error)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Check failing</span>
                                @elseif ($source->last_checked_at)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Healthy</span>
                                @else
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Never checked</span>
                                @endif
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
                                    <a
                                        href="{{ $finding->url }}"
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        class="text-indigo-600 hover:text-indigo-900 truncate mr-3"
                                    >{{ $finding->title }}</a>
                                    <span class="shrink-0 flex items-center space-x-1">
                                        @if ($finding->is_security)
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Security</span>
                                        @endif
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

        <p class="text-center text-xs text-gray-400">Powered by WebMonitor</p>
    </div>
</body>
</html>
