<?php

namespace Reach\StatamicResrv\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Report;

class ReportResource extends ResourceCollection
{
    public function __construct(Report $report)
    {
        $this->report = $report;
    }

    public function toArray($request)
    {
        $data = [
            'total_confirmed_reservations' => $this->report->countConfirmedReservations(),
            'total_revenue' => Price::create($this->report->sumConfirmedReservations())->format(),
            'avg_revenue' => Price::create($this->report->avgConfirmedReservations())->format(),
            'top_seller_items' => $this->report->topSellerItems(),
            'top_seller_extras' => $this->report->topSellerExtras(),
        ];

        return $data;
    }
}
