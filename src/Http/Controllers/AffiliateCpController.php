<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Http\Requests\AffiliateCpRequest;
use Reach\StatamicResrv\Models\Affiliate;

class AffiliateCpController extends Controller
{
    protected $affiliate;

    public function __construct(Affiliate $affiliate)
    {
        $this->affiliate = $affiliate;
    }

    public function indexCp()
    {
        return view('statamic-resrv::cp.affiliates.index');
    }

    public function index()
    {
        $affiliates = $this->affiliate->with('coupons')->get();

        foreach ($affiliates as $affiliate) {
            $affiliate->coupons_ids = $affiliate->coupons->pluck('id')->values()->toArray();
        }

        return response()->json($affiliates);
    }

    public function create(AffiliateCpRequest $request)
    {
        $data = $request->validated();
        $coupons = $data['coupons'] ?? [];
        unset($data['coupons']);

        $affiliate = $this->affiliate->create($data);

        if (! empty($coupons)) {
            $affiliate->coupons()->sync($coupons);
        }

        return response()->json(['id' => $affiliate->id]);
    }

    public function update(AffiliateCpRequest $request, Affiliate $affiliate)
    {
        $data = $request->validated();
        $coupons = $data['coupons'] ?? [];
        unset($data['coupons']);

        $affiliate->update($data);

        $affiliate->coupons()->sync($coupons);

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
