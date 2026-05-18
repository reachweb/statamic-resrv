<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Reach\StatamicResrv\Http\Requests\AffiliateCpRequest;
use Reach\StatamicResrv\Models\Affiliate;

class AffiliateCpController extends Controller
{
    protected $affiliate;

    public function __construct(Affiliate $affiliate)
    {
        $this->affiliate = $affiliate;
    }

    public function indexCp(): InertiaResponse
    {
        return Inertia::render('resrv::Affiliates/Index', [
            'affiliates' => $this->allAffiliates(),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->allAffiliates());
    }

    protected function allAffiliates(): EloquentCollection
    {
        $affiliates = $this->affiliate->with('coupons')->get();

        foreach ($affiliates as $affiliate) {
            $affiliate->coupons_ids = $affiliate->coupons->pluck('id')->values()->toArray();
        }

        return $affiliates;
    }

    public function create(AffiliateCpRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $coupons = $data['coupons'] ?? [];
        unset($data['coupons']);

        $affiliate = $this->affiliate->create($data);

        if (! empty($coupons)) {
            $affiliate->coupons()->sync($coupons);
        }

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $affiliate->id]);
    }

    public function update(AffiliateCpRequest $request, Affiliate $affiliate): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $coupons = $data['coupons'] ?? [];
        unset($data['coupons']);

        $affiliate->update($data);

        $affiliate->coupons()->sync($coupons);

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $affiliate->id]);
    }

    public function delete(Request $request, Affiliate $affiliate)
    {
        $affiliate->delete();

        DB::table('resrv_reservation_affiliate')
            ->where('affiliate_id', $affiliate->id)
            ->delete();

        return response(200);
    }
}
