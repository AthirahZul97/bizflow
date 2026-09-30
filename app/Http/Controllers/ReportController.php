<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Support\CurrentBusiness;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only reports for the current business. Only period, from, to, view,
 * status and page are read from the query string; ownership always comes from
 * CurrentBusiness, never from a parameter.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public function summary(Request $request): View
    {
        $period = $this->period($request);

        return view('reports.summary', ['period' => $period] + $this->reports->summary($this->currentBusiness->get(), $period));
    }

    public function customers(Request $request): View
    {
        $period = $this->period($request);

        return view('reports.customers', ['period' => $period] + $this->reports->customers($this->currentBusiness->get(), $period));
    }

    public function invoices(Request $request): View
    {
        $period = $this->period($request);

        $view = $request->query('view');
        $view = in_array($view, ReportService::INVOICE_VIEWS, true) ? $view : 'invoiced';

        $status = $request->query('status');
        $status = $view === 'invoiced' && in_array($status, ReportService::INVOICE_STATUS_FILTERS, true) ? $status : null;

        return view('reports.invoices', [
            'period' => $period,
            'view' => $view,
            'status' => $status,
            'invoiceQuery' => array_filter(['view' => $view, 'status' => $status]),
        ] + $this->reports->invoices($this->currentBusiness->get(), $period, $view, $status));
    }

    public function expenses(Request $request): View
    {
        $period = $this->period($request);

        return view('reports.expenses', ['period' => $period] + $this->reports->expenses($this->currentBusiness->get(), $period));
    }

    private function period(Request $request): ReportingPeriod
    {
        return ReportingPeriod::fromRequest($request, ReportingPeriod::REPORT_PRESETS, ReportingPeriod::THIS_YEAR);
    }
}
