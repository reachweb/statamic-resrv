<?php

namespace Reach\StatamicResrv\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

class AvailabilityItemResource extends ResourceCollection
{
    protected $availability;

    protected $userRequest;

    public function __construct(Collection $availability, Collection $userRequest)
    {
        $this->availability = $availability;
        $this->userRequest = $userRequest;
    }

    public function toArray($request): array
    {
        return [
            'data' => $this->availability->count() === 1 ? $this->availability->first()->toArray() : $this->availability->toArray(),
            'request' => [
                'days' => $this->userRequest->get('duration'),
                'date_start' => $this->userRequest->get('date_start'),
                'date_end' => $this->userRequest->get('date_end'),
                'quantity' => $this->userRequest->get('quantity'),
                'property' => implode(',', $this->userRequest->get('property')),
            ],
            'message' => [
                'status' => $this->availability->isNotEmpty() ? true : false,
            ],
        ];
    }
}
