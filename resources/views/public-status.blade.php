<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de {{ $monitor->name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="max-w-xl w-full bg-white shadow-lg rounded-lg overflow-hidden">
        <div class="p-8 text-center border-b border-gray-100">
            <h1 class="text-3xl font-bold text-gray-800">{{ $monitor->name }}</h1>
            <p class="text-gray-500 mt-1">{{ $monitor->url }}</p>

            <div class="mt-6">
                @if($monitor->status === 'up')
                    <div class="inline-block px-6 py-3 rounded-full bg-green-500 text-white font-bold text-2xl shadow-lg">
                        ONLINE
                    </div>
                @else
                    <div class="inline-block px-6 py-3 rounded-full bg-red-500 text-white font-bold text-2xl shadow-lg">
                        OFFLINE
                    </div>
                @endif
            </div>
            <p class="text-xs text-gray-400 mt-6 uppercase tracking-widest">Última comprobación: {{ $monitor->last_checked_at?->diffForHumans() ?? 'N/A' }}</p>
        </div>

        <div class="p-6">
            <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4">Últimas Comprobaciones</h2>
            <div class="space-y-3">
                @foreach($logs as $log)
                    <div class="flex items-center justify-between p-3 rounded-lg {{ $log->status === 'up' ? 'bg-green-50' : 'bg-red-50' }}">
                        <span class="text-sm font-medium text-gray-600">{{ $log->checked_at->format('H:i:s d/m/Y') }}</span>
                        <div class="flex items-center">
                            <span class="text-xs font-bold mr-3 {{ $log->status === 'up' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $log->response_time }}ms
                            </span>
                            <span class="px-2 py-1 rounded text-[10px] font-bold {{ $log->status === 'up' ? 'bg-green-200 text-green-800' : 'bg-red-200 text-red-800' }}">
                                {{ $log->status_code ?? 'ERR' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="bg-gray-50 p-4 text-center">
            <p class="text-xs text-gray-400">Powered by WebMonitor</p>
        </div>
    </div>
</body>
</html>
