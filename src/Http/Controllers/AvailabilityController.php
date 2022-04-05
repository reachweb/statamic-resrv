<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Exceptions\AvailabilityException;

class AvailabilityController extends Controller
{
    public $availability;

    public function __construct(AvailabilityContract $availability)
    {
        $this->availability = $availability;
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'quantity' => 'sometimes|integer',
        ]);

        try {
            $availabilityData = $this->availability->getAvailableItems($data);
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        return response()->json($availabilityData);
    }

    public function show(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'quantity' => 'sometimes|integer',
        ]);

        try {
            $availabilityData = $this->availability->getAvailabilityForItem($data, $statamic_id);
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        return response()->json($availabilityData);
    }

    public function multiIndex(Request $request)
    {
        $data = $request->validate([
            'dates' => 'required|array',
            'dates.*.date_start' => 'required|date',
            'dates.*.date_end' => 'required|date',
            'dates.*.quantity' => 'sometimes|integer',
        ]);

        try {
            $availabilityData = $this->availability->getMultipleAvailableItems($data);
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        return response()->json($availabilityData);
    }

    public function multiShow(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'dates' => 'required|array',
            'dates.*.date_start' => 'required|date',
            'dates.*.date_end' => 'required|date',
            'dates.*.quantity' => 'sometimes|integer',
        ]);

        try {
            $availabilityData = $this->availability->getMultipleAvailabilityForItem($data, $statamic_id);
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        return response()->json($availabilityData);
    }
}
