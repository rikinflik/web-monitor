<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
            'monitors' => Monitor::query()->orderBy('name')->get(['id', 'name', 'url']),
            'selectedMonitorIds' => $user->notifiedMonitors()->pluck('monitors.id')->all(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Update which monitor status-change emails this user receives.
     *
     * The explicit selection is synced only in "selected" mode so that a user
     * switching to "all" or "none" and back keeps their previous list.
     */
    public function updateNotifications(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        $mode = $request->validated('notify_mode');

        $user->notify_mode = $mode;
        $user->save();

        if ($mode === User::NOTIFY_SELECTED) {
            $user->notifiedMonitors()->sync($request->validated('monitors'));
        }

        return Redirect::route('profile.edit')->with('status', 'notification-preferences-updated');
    }

    /**
     * Send a one-off test email to the signed-in user.
     *
     * The mail configuration is otherwise only exercised when a site actually
     * goes down, so a misconfigured SMTP host stays invisible until it matters.
     * The send is deliberately synchronous and the failure is surfaced verbatim,
     * because the whole point is to read the transport error.
     */
    public function sendTestNotification(Request $request): RedirectResponse
    {
        try {
            Notification::send($request->user(), new TestNotification());
        } catch (\Throwable $e) {
            Log::error('Test notification failed: ' . $e->getMessage());

            return Redirect::route('profile.edit')->withErrors(
                ['test' => $e->getMessage()],
                'sendTestNotification',
            );
        }

        return Redirect::route('profile.edit')->with('status', 'test-notification-sent');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
