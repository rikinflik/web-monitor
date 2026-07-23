@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
    <h1 class="text-2xl font-semibold mb-6">Añadir URL</h1>

    <form action="{{ route('seo.store') }}" method="POST">
        @csrf

        <div class="grid grid-cols-1 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nombre</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror" placeholder="Mi Sitio Web">
                @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="url" class="block text-sm font-medium text-gray-700">URL a revisar</label>
                <input type="url" name="url" id="url" value="{{ old('url') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 @error('url') border-red-500 @enderror" placeholder="https://ejemplo.com">
                @error('url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-8 flex justify-end">
            <a href="{{ route('seo.index') }}" class="text-gray-600 hover:text-gray-900 font-medium py-2 px-4 mr-4">Cancelar</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow-lg transition duration-200">
                Guardar URL
            </button>
        </div>
    </form>
</div>
@endsection
