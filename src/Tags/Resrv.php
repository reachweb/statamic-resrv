<?php

namespace Reach\StatamicResrv\Tags;

use Illuminate\Support\Facades\Validator;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Tags\Tags;

class Resrv extends Tags
{
    public function locations()
    {
        return htmlspecialchars(Location::where('published', true)->get()->toJson(), ENT_QUOTES, 'UTF-8');
    }

    public function searchJson()
    {
        if (session()->missing('resrv_search')) {
            return json_encode([]);
        }

        return json_encode(session()->get('resrv_search'));
    }

    public function reservationFromUri()
    {
        $validator = Validator::make(request()->all(), [
            'res_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            abort(400, 'Invalid reservation ID.');
        }

        return Reservation::where('id', request()->get('res_id'))->where('status', ReservationStatus::CONFIRMED)->firstOrFail();
    }
}
