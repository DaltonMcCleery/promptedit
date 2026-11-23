<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UsageReport;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Credit spend, provider costs and profit for the last 30 UTC days, compared with GET /v1/usage.
     */
    public function __invoke(UsageReport $report): Response
    {
        return Inertia::render('Admin/Report', $report->forLastThirtyDays());
    }
}
