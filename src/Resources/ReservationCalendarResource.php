<?php

namespace Reach\StatamicResrv\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Reach\StatamicResrv\Resources\Concerns\ResolvesReservationEntries;

class ReservationCalendarResource extends ResourceCollection
{
    use ResolvesReservationEntries;

    protected Collection $entries;

    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray($request)
    {
        $onlyStart = $request->query('onlyStart') == 1;
        $childs = collect();
        $this->entries = $this->resolveReservationEntries($this->collection);
        $reservations = $this->collection->transform(function ($reservation) use ($onlyStart, &$childs) {
            if ($reservation->type === 'parent') {
                $childs->push($this->buildChildReservationArray($reservation, $onlyStart)->toArray());

                return false;
            }

            return $this->buildEventArray(
                reservation: $reservation,
                rateLabel: $reservation->rate_id ? $reservation->getRateLabel() : null,
                quantity: $reservation->quantity,
                dateStart: $reservation->date_start,
                dateEnd: $reservation->date_end,
                onlyStart: $onlyStart,
            );
        })->reject(fn ($item) => $item === false);

        return $reservations->concat($childs->flatten(1));
    }

    private function formatDate(?Carbon $date)
    {
        if (! $date) {
            return null;
        }

        if (config('resrv-config.enable_time') == false) {
            return $date->toDateString();
        }

        return $date->toIso8601String();
    }

    protected function buildChildReservationArray($reservation, bool $onlyStart)
    {
        return $reservation->childs->map(fn ($child) => $this->buildEventArray(
            reservation: $reservation,
            rateLabel: $child->rate_id ? $child->getRateLabel() : null,
            quantity: $child->quantity,
            dateStart: $child->date_start,
            dateEnd: $child->date_end,
            onlyStart: $onlyStart,
            isChild: true,
        ));
    }

    protected function buildEventArray(
        $reservation,
        ?string $rateLabel,
        $quantity,
        ?Carbon $dateStart,
        ?Carbon $dateEnd,
        bool $onlyStart,
        bool $isChild = false,
    ): array {
        $entryTitle = $reservation->entryToArray($this->entries->get($reservation->item_id))['title'];
        $showQuantity = config('resrv-config.maximum_quantity') > 1;
        $titleParts = ['#'.$reservation->id, $entryTitle];
        if ($rateLabel) {
            $titleParts[] = $rateLabel;
        }
        $title = implode(' - ', $titleParts).($showQuantity ? ' x '.$quantity : '');

        $classNames = ['resrv-event', 'resrv-event--'.$reservation->status];
        if ($isChild) {
            $classNames[] = 'resrv-event--child';
        }

        return [
            'id' => $reservation->id,
            'title' => $title,
            'start' => $this->formatDate($dateStart),
            'end' => $onlyStart ? null : $this->formatDate($dateEnd),
            'url' => cp_route('resrv.reservation.show', $reservation->id),
            'status' => $reservation->status,
            'classNames' => $classNames,
            'extendedProps' => [
                'reservationId' => $reservation->id,
                'entryTitle' => $entryTitle,
                'rateLabel' => $rateLabel,
                'quantity' => $showQuantity ? $quantity : null,
            ],
        ];
    }
}
