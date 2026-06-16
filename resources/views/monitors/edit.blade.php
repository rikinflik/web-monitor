@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
    <h1 class="text-2xl font-semibold mb-6">Editar Monitor: {{ $monitor->name }}</h1>

    <form action="{{ route('monitors.update', $monitor) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nombre</label>
                <input type="text" name="name" id="name" value="{{ old('name', $monitor->name) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror">
                @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="url" class="block text-sm font-medium text-gray-700">URL a monitorizar</label>
                <input type="url" name="url" id="url" value="{{ old('url', $monitor->url) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('url') border-red-500 @enderror">
                @error('url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="interval" class="block text-sm font-medium text-gray-700">Intervalo (segundos)</label>
                    <input type="number" name="interval" id="interval" value="{{ old('interval', $monitor->interval) }}" min="60" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('interval') border-red-500 @enderror">
                    @error('interval') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="timeout" class="block text-sm font-medium text-gray-700">Timeout (segundos)</label>
                    <input type="number" name="timeout" id="timeout" value="{{ old('timeout', $monitor->timeout) }}" min="1" max="60" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('timeout') border-red-500 @enderror">
                    @error('timeout') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="expected_status_code" class="block text-sm font-medium text-gray-700">Código de estado esperado</label>
                <input type="number" name="expected_status_code" id="expected_status_code" value="{{ old('expected_status_code', $monitor->expected_status_code) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('expected_status_code') border-red-500 @enderror">
                @error('expected_status_code') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="keyword" class="block text-sm font-medium text-gray-700">Palabra clave (opcional)</label>
                <input type="text" name="keyword" id="keyword" value="{{ old('keyword', $monitor->keyword) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Ej: Bienvenido">
            </div>

            <div>
                <label for="webhook_url" class="block text-sm font-medium text-gray-700">Webhook URL (opcional)</label>
                <input type="url" name="webhook_url" id="webhook_url" value="{{ old('webhook_url', $monitor->webhook_url) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="https://api.tu-app.com/webhook">
            </div>

            <div class="border-t border-gray-200 pt-4">
                <p class="text-sm font-medium text-gray-700 mb-3">Autenticació Basic Auth (opcional)</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="basic_auth_user" class="block text-sm font-medium text-gray-700">Usuari</label>
                        <input type="text" name="basic_auth_user" id="basic_auth_user" value="{{ old('basic_auth_user', $monitor->basic_auth_user) }}" autocomplete="off" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('basic_auth_user') border-red-500 @enderror" placeholder="usuari">
                        @error('basic_auth_user') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="basic_auth_password" class="block text-sm font-medium text-gray-700">Contrasenya</label>
                        <input type="password" name="basic_auth_password" id="basic_auth_password" autocomplete="new-password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('basic_auth_password') border-red-500 @enderror" placeholder="{{ $monitor->basic_auth_password ? '(mantenir existent)' : '••••••••' }}">
                        @error('basic_auth_password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        @if($monitor->basic_auth_password)
                            <p class="mt-1 text-xs text-gray-400">Deixa en blanc per mantenir la contrasenya actual.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex justify-end">
            <a href="{{ route('monitors.index') }}" class="text-gray-600 hover:text-gray-900 font-medium py-2 px-4 mr-4">Cancelar</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow-lg transition duration-200">
                Actualizar Monitor
            </button>
        </div>
    </form>
</div>
@endsection
