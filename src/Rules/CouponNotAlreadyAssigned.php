<?php

namespace Reach\StatamicResrv\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\DynamicPricing;

class CouponNotAlreadyAssigned implements ValidationRule
{
    protected $affiliateId;

    public function __construct($affiliateId = null)
    {
        $this->affiliateId = $affiliateId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $query = DB::table('resrv_affiliate_dynamic_pricing')
            ->whereIn('dynamic_pricing_id', $value);

        // If updating an affiliate, exclude its own associations
        if ($this->affiliateId) {
            $query->where('affiliate_id', '!=', $this->affiliateId);
        }

        $assignedCouponIds = $query->pluck('dynamic_pricing_id')->toArray();

        if (! empty($assignedCouponIds)) {
            // Get the coupon codes/titles for the error message
            $assignedCoupons = DynamicPricing::whereIn('id', $assignedCouponIds)
                ->get()
                ->map(function ($coupon) {
                    return $coupon->coupon ? "{$coupon->title} ({$coupon->coupon})" : $coupon->title;
                })
                ->join(', ');

            $fail("The following coupons are already assigned to another affiliate: {$assignedCoupons}");
        }
    }
}
