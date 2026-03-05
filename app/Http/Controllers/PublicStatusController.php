<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Monitor;
use Illuminate\View\View;

class PublicStatusController extends Controller
{
    public function show(string $token): View
    {
        $monitor = Monitor::where('public_token', $token)->firstOrFail();
        $logs = $monitor->logs()->latest()->limit(20)->get();

        return view('public-status', compact('monitor', 'logs'));
    }
}
