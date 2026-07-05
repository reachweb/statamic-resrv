<?php

namespace Reach\StatamicResrv\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
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
            'total_reservations' => $this->report->countReservations(),
            'total_revenue' => $this->report->sumRevenue()->format(),
            'avg_revenue' => $this->report->avgRevenue()->format(),
            'top_seller_items' => $this->report->topSellerItems(),
            'top_seller_extras' => $this->report->topSellerExtras(),
            'dynamic_pricing_applications' => $this->report->dynamicPricingApplications(),
        ];

        // Gate the affiliate section on the feature flag so the Vue panel hides when it's off.
        if (config('resrv-config.enable_affiliates', true)) {
            $data['affiliate_sales'] = $this->report->affiliateSales();
        }

        return $data;
    }
}
