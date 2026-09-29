<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the authenticated user's dashboard for the selected period.
     */
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $period = DashboardPeriod::fromRequest($request);

        return view('dashboard', ['period' => $period] + $dashboard->summary($request->user(), $period));
    }
}
