<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Models\Monitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MonitorController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $monitors = $request->user()->monitors()->latest()->get();
        return view('monitors.index', compact('monitors'));
    }

    public function create(): View
    {
        return view('monitors.create');
    }

    public function store(StoreMonitorRequest $request): RedirectResponse
    {
        $request->user()->monitors()->create($request->validated());
        return redirect()->route('monitors.index')->with('success', 'Monitor created successfully.');
    }

    public function show(Monitor $monitor): View
    {
        $this->authorize('view', $monitor);
        $logs = $monitor->logs()->latest()->limit(50)->get();
        return view('monitors.show', compact('monitor', 'logs'));
    }

    public function edit(Monitor $monitor): View
    {
        $this->authorize('update', $monitor);
        return view('monitors.edit', compact('monitor'));
    }

    public function update(UpdateMonitorRequest $request, Monitor $monitor): RedirectResponse
    {
        $this->authorize('update', $monitor);
        $monitor->update($request->validated());
        return redirect()->route('monitors.index')->with('success', 'Monitor updated successfully.');
    }

    public function destroy(Monitor $monitor): RedirectResponse
    {
        $this->authorize('delete', $monitor);
        $monitor->delete();
        return redirect()->route('monitors.index')->with('success', 'Monitor deleted successfully.');
    }
}
