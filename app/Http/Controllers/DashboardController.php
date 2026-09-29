<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the authenticated user's dashboard for the selected period.
     */
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $period = ReportingPeriod::fromRequest($request, ReportingPeriod::DASHBOARD_PRESETS, ReportingPeriod::THIS_MONTH);

        return view('dashboard', ['period' => $period] + $dashboard->summary($request->user(), $period));
    }
}
