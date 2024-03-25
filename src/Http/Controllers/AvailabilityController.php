<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Events\AvailabilitySearch;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Http\Requests\AvailabilityRequest;
use Reach\StatamicResrv\Models\Availability;

class AvailabilityController extends Controller
{
    public $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }

    public function index(AvailabilityRequest $request)
    {
        try {
            $availabilityData = $request->missing('dates')
                ? $this->availability->getAvailableItems($request->validated())
                : $this->availability->getMultipleAvailableItems($request->validated());
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        AvailabilitySearch::dispatchUnless($request->get('forget'), $request->validated());

        return response()->json($availabilityData);
    }

    public function show(AvailabilityRequest $request, $statamic_id)
    {
        try {
            $availabilityData = $request->missing('dates')
                ? $this->availability->getAvailabilityForItem($request->validated(), $statamic_id)
                : $this->availability->getMultipleAvailabilityForItem($request->validated(), $statamic_id);
        } catch (AvailabilityException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        AvailabilitySearch::dispatchUnless($request->get('forget'), $request->validated());

        return response()->json($availabilityData);
    }
}
