@extends('layouts.app')

@section('content')
<div class="p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold">SEO / Redirects</h1>
        <div class="flex space-x-2">
            <a href="{{ route('seo.index') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded">
                Refresh
            </a>
            <a href="{{ route('seo.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                + Añadir URL
            </a>
        </div>
    </div>

    <div class="w-full">
        <table class="w-full table-fixed divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">www</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">HTTPS</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trailing</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">robots</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sitemap</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revisión</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($monitors as $monitor)
                @php($seoCheck = $monitor->seoCheck)
                <tr>
                    <td class="px-3 py-4 text-sm font-medium text-gray-900 break-words">{{ $monitor->name }}</td>
                    <td class="px-3 py-4 text-sm text-gray-500 break-all">{{ $monitor->url }}</td>

                    @foreach(['www_redirect', 'https_redirect', 'trailing_slash_redirect'] as $dimension)
                    <td class="px-3 py-4 whitespace-nowrap">
                        @if($seoCheck && $seoCheck->{$dimension} !== \App\Models\SeoCheck::NONE)
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">{{ $seoCheck->{$dimension} }}</span>
                        @else
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">—</span>
                        @endif
                    </td>
                    @endforeach

                    @foreach(['robots_ok', 'sitemap_ok'] as $flag)
                    <td class="px-3 py-4 whitespace-nowrap">
                        @if($seoCheck && $seoCheck->{$flag})
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">OK</span>
                        @else
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Falta</span>
                        @endif
                    </td>
                    @endforeach

                    <td class="px-3 py-4 text-sm text-gray-500">
                        {{ $seoCheck?->last_checked_at?->diffForHumans() ?? 'Nunca' }}
                    </td>
                    <td class="px-3 py-4 whitespace-nowrap text-sm font-medium">
                        @if($seoCheck)
                        <form action="{{ route('seo.recheck', $seoCheck) }}" method="POST" class="inline-block">
                            @csrf
                            <button type="submit" class="text-indigo-600 hover:text-indigo-900">Revisar</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
