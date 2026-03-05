@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
    <h1 class="text-2xl font-semibold mb-6">Crear Nuevo Monitor</h1>

    <form action="{{ route('monitors.store') }}" method="POST">
        @csrf

        <div class="grid grid-cols-1 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nombre</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror" placeholder="Mi Sitio Web">
                @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="url" class="block text-sm font-medium text-gray-700">URL a monitorizar</label>
                <input type="url" name="url" id="url" value="{{ old('url') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('url') border-red-500 @enderror" placeholder="https://ejemplo.com">
                @error('url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="interval" class="block text-sm font-medium text-gray-700">Intervalo (segundos)</label>
                    <input type="number" name="interval" id="interval" value="{{ old('interval', 60) }}" min="60" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('interval') border-red-500 @enderror">
                    @error('interval') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="timeout" class="block text-sm font-medium text-gray-700">Timeout (segundos)</label>
                    <input type="number" name="timeout" id="timeout" value="{{ old('timeout', 30) }}" min="1" max="60" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('timeout') border-red-500 @enderror">
                    @error('timeout') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="expected_status_code" class="block text-sm font-medium text-gray-700">Código de estado esperado</label>
                <input type="number" name="expected_status_code" id="expected_status_code" value="{{ old('expected_status_code', 200) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('expected_status_code') border-red-500 @enderror">
                @error('expected_status_code') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="keyword" class="block text-sm font-medium text-gray-700">Palabra clave (opcional)</label>
                <input type="text" name="keyword" id="keyword" value="{{ old('keyword') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Ej: Bienvenido">
            </div>

            <div>
                <label for="webhook_url" class="block text-sm font-medium text-gray-700">Webhook URL (opcional)</label>
                <input type="url" name="webhook_url" id="webhook_url" value="{{ old('webhook_url') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="https://api.tu-app.com/webhook">
            </div>
        </div>

        <div class="mt-8 flex justify-end">
            <a href="{{ route('monitors.index') }}" class="text-gray-600 hover:text-gray-900 font-medium py-2 px-4 mr-4">Cancelar</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow-lg transition duration-200">
                Guardar Monitor
            </button>
        </div>
    </form>
</div>
@endsection
