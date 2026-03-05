<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'WebMonitor') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">
    <nav class="bg-white border-b border-gray-200 fixed w-full z-30 top-0">
        <div class="px-3 py-3 lg:px-5 lg:pl-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center justify-start">
                    <a href="{{ route('monitors.index') }}" class="text-xl font-bold flex items-center lg:ml-2.5">
                        <span class="self-center whitespace-nowrap text-blue-600">WebMonitor</span>
                    </a>
                </div>
                <div class="flex items-center">
                    @auth
                        <span class="text-gray-600 mr-4">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-red-600 font-semibold hover:text-red-800">Cerrar Sesión</button>
                        </form>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <div class="flex overflow-hidden bg-gray-50 pt-16">
        <div id="main-content" class="h-full w-full bg-gray-50 relative overflow-y-auto lg:ml-64">
            <main>
                <div class="pt-6 px-4">
                    @if (session('success'))
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                            <span class="block sm:inline">{{ session('success') }}</span>
                        </div>
                    @endif
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
</body>
</html>
