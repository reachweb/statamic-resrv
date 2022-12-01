<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Contracts\Models\AvailabilityContract;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Http\Requests\AdvancedAvailabilityRequest;
use Reach\StatamicResrv\Jobs\SaveSearchToSession;

class AdvancedAvailabilityController extends Controller
{
    public $availability;

    public function __construct(AvailabilityContract $availability)
    {
        $this->availability = $availability;
    }

    public function index(AdvancedAvailabilityRequest $request)
    {
        try {
            $availabilityData = $request->missing('dates')
                ? $this->availability->getAvailableItems($request->validated())
                : $this->availability->getMultipleAvailableItems($request->validated());
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        if (! $request->has('forget')) {
            SaveSearchToSession::dispatchSync($request->validated());
        }

        return response()->json($availabilityData);
    }

    public function show(AdvancedAvailabilityRequest $request, $statamic_id)
    {
        try {
            $availabilityData = $request->missing('dates')
                ? $this->availability->getAvailabilityForItem($request->validated(), $statamic_id)
                : $this->availability->getMultipleAvailabilityForItem($request->validated(), $statamic_id);
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        if (! $request->has('forget')) {
            SaveSearchToSession::dispatchSync($request->validated());
        }

        return response()->json($availabilityData);
    }
}
