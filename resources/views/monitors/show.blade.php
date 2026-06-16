@extends('layouts.app')

@section('content')
<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">{{ $monitor->name }}</h1>
            <p class="text-gray-500">{{ $monitor->url }}</p>
        </div>
        <div class="flex items-center">
            @if($monitor->status === 'up')
                <span class="px-4 py-2 rounded-full bg-green-100 text-green-800 font-bold text-lg mr-4">ESTADO: UP</span>
            @else
                <span class="px-4 py-2 rounded-full bg-red-100 text-red-800 font-bold text-lg mr-4">ESTADO: DOWN</span>
            @endif
            <a href="{{ route('monitors.edit', $monitor) }}" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded">
                Editar
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <h3 class="text-blue-700 font-semibold mb-1">Última Revisión</h3>
            <p class="text-2xl font-bold">{{ $monitor->last_checked_at?->diffForHumans() ?? 'Nunca' }}</p>
        </div>
        <div class="p-4 bg-indigo-50 border border-indigo-200 rounded-lg">
            <h3 class="text-indigo-700 font-semibold mb-1">Intervalo</h3>
            <p class="text-2xl font-bold">{{ $monitor->interval }} segundos</p>
        </div>
        <div class="p-4 bg-purple-50 border border-purple-200 rounded-lg">
            <h3 class="text-purple-700 font-semibold mb-1">Página Pública</h3>
            <p class="text-sm truncate">
                <a href="{{ url('/status/' . $monitor->public_token) }}" class="text-blue-600 underline" target="_blank">
                    {{ url('/status/' . $monitor->public_token) }}
                </a>
            </p>
        </div>
    </div>

    <h2 class="text-xl font-semibold mb-4">Historial de Revisiones (Últimas 50)</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Código</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tiempo Resp.</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Error</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($logs as $log)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $log->checked_at->format('d/m/Y H:i:s') }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $log->status === 'up' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                            {{ strtoupper($log->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $log->status_code ?? 'N/A' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $log->response_time }}ms</td>
                    <td class="px-6 py-4 text-sm text-red-600">{{ $log->error_message ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
