@extends('layouts.app')

@section('content')
<div class="p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold">Tech Update Alerts</h1>
        <div class="flex space-x-2">
            <a href="{{ route('alerts.index') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded">
                Refresh
            </a>
            @if ($isAdmin)
            <form action="{{ route('alerts.check-now') }}" method="POST" class="inline-block">
                @csrf
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    Check now
                </button>
            </form>
            @endif
        </div>
    </div>

    @if (session('success'))
    <div class="mb-4 text-sm text-green-700 bg-green-100 border border-green-200 rounded px-4 py-2">
        {{ session('success') }}
    </div>
    @endif

    <div class="space-y-6">
        @foreach ($sources as $source)
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-lg font-semibold text-gray-900">{{ $source->name }}</h2>
                <div class="flex items-center space-x-2">
                    @if ($source->last_error)
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Check failing</span>
                    @elseif ($source->last_checked_at)
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Healthy</span>
                    @else
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Never checked</span>
                    @endif
                    <span class="text-xs text-gray-500">
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

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Published</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($source->findings as $finding)
                        <tr>
                            <td class="px-3 py-2 text-sm text-gray-900">
                                <a href="{{ $finding->url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-900">
                                    {{ $finding->title }}
                                </a>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                @if ($finding->is_security)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Security</span>
                                @endif
                                @if ($finding->severity === \App\Models\AlertFinding::SEVERITY_CRITICAL)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Critical</span>
                                @elseif ($finding->severity === \App\Models\AlertFinding::SEVERITY_HIGH)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">High</span>
                                @elseif ($finding->severity === \App\Models\AlertFinding::SEVERITY_MODERATE)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Moderate</span>
                                @elseif ($finding->severity === \App\Models\AlertFinding::SEVERITY_LOW)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Low</span>
                                @else
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Unrated</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">
                                {{ $finding->published_at?->diffForHumans() ?? 'Unknown date' }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="px-3 py-4 text-sm text-gray-500">No findings recorded yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endsection
