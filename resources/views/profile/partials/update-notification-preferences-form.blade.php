<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Notification Preferences') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Choose which websites send you an email when they go down or come back up. By default you receive every alert.') }}
        </p>
    </header>

    <form
        method="post"
        action="{{ route('profile.notifications.update') }}"
        class="mt-6 space-y-6"
        x-data="{ mode: '{{ old('notify_mode', $user->notify_mode) }}' }"
    >
        @csrf
        @method('patch')

        <div class="space-y-3">
            <label class="flex items-start gap-3">
                <input
                    type="radio"
                    name="notify_mode"
                    value="{{ \App\Models\User::NOTIFY_ALL }}"
                    x-model="mode"
                    class="mt-1 border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                >
                <span>
                    <span class="block text-sm font-medium text-gray-900">{{ __('Receive everything') }}</span>
                    <span class="block text-sm text-gray-600">{{ __('All websites, including ones added later.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input
                    type="radio"
                    name="notify_mode"
                    value="{{ \App\Models\User::NOTIFY_SELECTED }}"
                    x-model="mode"
                    class="mt-1 border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                >
                <span>
                    <span class="block text-sm font-medium text-gray-900">{{ __('Only the websites I select') }}</span>
                    <span class="block text-sm text-gray-600">{{ __('Websites added later will not be included automatically.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input
                    type="radio"
                    name="notify_mode"
                    value="{{ \App\Models\User::NOTIFY_NONE }}"
                    x-model="mode"
                    class="mt-1 border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                >
                <span>
                    <span class="block text-sm font-medium text-gray-900">{{ __('Do not send me anything') }}</span>
                    <span class="block text-sm text-gray-600">{{ __('You will still receive account emails such as password resets.') }}</span>
                </span>
            </label>

            <x-input-error :messages="$errors->updateNotificationPreferences->get('notify_mode')" class="mt-2" />
        </div>

        <div x-show="mode === '{{ \App\Models\User::NOTIFY_SELECTED }}'" x-cloak>
            <x-input-label :value="__('Websites')" />

            @if ($monitors->isEmpty())
                <p class="mt-2 text-sm text-gray-600">
                    {{ __('There are no websites to choose from yet.') }}
                </p>
            @else
                <div class="mt-2 max-h-72 space-y-2 overflow-y-auto rounded-md border border-gray-200 p-3">
                    @foreach ($monitors as $monitor)
                        <label class="flex items-start gap-3">
                            <input
                                type="checkbox"
                                name="monitors[]"
                                value="{{ $monitor->id }}"
                                @checked(in_array($monitor->id, old('monitors', $selectedMonitorIds), false))
                                class="mt-1 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                            >
                            <span>
                                <span class="block text-sm font-medium text-gray-900">{{ $monitor->name }}</span>
                                <span class="block text-sm text-gray-600 break-all">{{ $monitor->url }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif

            <x-input-error :messages="$errors->updateNotificationPreferences->get('monitors')" class="mt-2" />
            <x-input-error :messages="$errors->updateNotificationPreferences->get('monitors.*')" class="mt-2" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'notification-preferences-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
