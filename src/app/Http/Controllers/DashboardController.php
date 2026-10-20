<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard)
    {
        if (! $request->user()->onboarding_completed) {
            return redirect()->route('onboarding.create');
        }

        return view('dashboard', ['d' => $dashboard->resumen($request->user())]);
    }
}
