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
        $affiliates = $this->affiliate->all();

        return response()->json($affiliates);
    }

    public function create(AffiliateCpRequest $request)
    {
        $affiliate = $this->affiliate->create($request->validated());

        return response()->json(['id' => $affiliate->id]);
    }

    public function update(AffiliateCpRequest $request, Affiliate $affiliate)
    {
        $affiliate->update($request->validated());

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
