<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSeoCheckRequest;
use App\Jobs\CheckSeoJob;
use App\Models\SeoCheck;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeoCheckController extends Controller
{
    use AuthorizesRequests;

    /**
     * List the current user's URLs with their paired SEO check state.
     */
    public function index(Request $request): View
    {
        $monitors = $request->user()->monitors()->with('seoCheck')->latest()->get();
        return view('seo.index', compact('monitors'));
    }

    public function create(): View
    {
        return view('seo.create');
    }

    /**
     * Create the shared monitor (with pinned Monitor defaults); the paired
     * seo_checks row is auto-created by the Monitor::created hook.
     */
    public function store(StoreSeoCheckRequest $request): RedirectResponse
    {
        $request->user()->monitors()->create($request->validated() + [
            'interval' => 60,
            'timeout' => 30,
            'expected_status_code' => 200,
        ]);

        return redirect()->route('seo.index')->with('success', 'URL añadida correctamente.');
    }

    /**
     * Run the SEO check synchronously so fresh results show immediately.
     */
    public function recheck(SeoCheck $seoCheck): RedirectResponse
    {
        $this->authorize('update', $seoCheck);
        CheckSeoJob::dispatchSync($seoCheck);

        return redirect()->route('seo.index')->with('success', 'Verificación completada.');
    }
}
