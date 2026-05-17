<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Reach\StatamicResrv\Models\Report;
use Reach\StatamicResrv\Resources\ReportResource;

class ReportsCpController extends Controller
{
    public function indexCp()
    {
        return Inertia::render('resrv::Reports/Index', [
            'reportsUrl' => cp_route('resrv.report.index'),
            'currency' => config('resrv-config.currency_symbol'),
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'start' => 'required',
            'end' => 'required',
            'date_field' => 'sometimes|in:date_start,created_at',
        ]);
        $report = new Report(
            $data['start'],
            $data['end'],
            $data['date_field'] ?? 'date_start',
        );

        return response()->json(new ReportResource($report));
    }
}
