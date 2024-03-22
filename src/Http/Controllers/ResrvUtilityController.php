<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Exceptions\CouponNotFoundException;
use Reach\StatamicResrv\Models\DynamicPricing;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class ResrvUtilityController extends Controller
{
    public function entries()
    {
        $collections = $this->collectionsWithAvailabityField();
        $entries = Entry::query()
            ->whereIn('collection', $collections)
            ->where('site', Site::default())
            ->orderBy('title', 'asc')
            ->get(['id', 'title'])
            ->toAugmentedArray();

        return response()->json($entries);
    }

    public function refreshSearchSession()
    {
        session()->forget('resrv_search');
    }

    public function getSavedSearch()
    {
        return response()->json(session()->get('resrv_search'));
    }

    public function token()
    {
        if (config('app.env') !== 'local' && config('app.env') !== 'testing') {
            $referer = request()->headers->get('referer');
            $contains = str_contains($referer, request()->getHttpHost());
            if (empty($referer) || ! $contains) {
                abort(404);
            }
        }

        return response()->json([
            'csrf_token' => csrf_token(),
        ]);
    }

    public function addCoupon(Request $request)
    {
        $data = $request->validate([
            'coupon' => 'required|alpha_dash',
            'reservation_id' => 'sometimes|integer',
        ]);

        try {
            DynamicPricing::searchForCoupon($data['coupon'], $data['reservation_id'] ?? null);
        } catch (CouponNotFoundException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        session(['resrv_coupon' => $data['coupon']]);

        return response()->json(['coupon' => $data['coupon']]);
    }

    public function getCoupon()
    {
        return response()->json(session()->get('resrv_coupon'));
    }

    public function removeCoupon(Request $request)
    {
        session()->forget('resrv_coupon');

        return response()->json(200);
    }

    protected function collectionsWithAvailabityField()
    {
        $collections = [];
        $allCollections = Collection::all();
        foreach ($allCollections as $collection) {
            foreach ($collection->entryBlueprints() as $blueprint) {
                foreach ($blueprint->fields()->all() as $field) {
                    if ($field->config()['type'] == 'resrv_availability') {
                        $collections[] = $collection->handle();
                    }
                }
            }
        }

        return $collections;
    }
}
