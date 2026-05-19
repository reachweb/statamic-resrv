<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Reach\StatamicResrv\Models\Report;
use Reach\StatamicResrv\Resources\ReportResource;

class ReportsCpController extends Controller
{
    public function indexCp(Request $request): InertiaResponse
    {
        $start = $request->query('start', now()->subDays(7)->format('Y-m-d'));
        $end = $request->query('end', now()->format('Y-m-d'));
        $dateField = $request->query('date_field', 'date_start');

        if (! in_array($dateField, ['date_start', 'created_at'], true)) {
            $dateField = 'date_start';
        }

        $report = new Report($start, $end, $dateField);

        return Inertia::render('resrv::Reports/Index', [
            'currency' => config('resrv-config.currency_symbol'),
            'filters' => [
                'start' => $start,
                'end' => $end,
                'dateField' => $dateField,
            ],
            'report' => (new ReportResource($report))->resolve($request),
        ]);
    }

    public function index(Request $request): JsonResponse
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
