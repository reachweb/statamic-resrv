<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Reservation;

class CartReservations extends Form
{
    public Reservation $parent;

    public Collection $reservations;

    public Collection $enabledExtrasCollection;

    public Collection $enabledOptionsCollection;

    public Collection $extraConditionsCollection;

    public function __construct()
    {
        $this->reservations = collect();
        $this->enabledExtrasCollection = collect();
        $this->enabledOptionsCollection = collect();
        $this->extraConditionsCollection = collect();
    }

    public function setParent(Reservation $reservation): self
    {
        $this->parent = $reservation;
        
        return $this;
    }

    public function addReservations(ChildReservation $reservation, $component): self
    {
        $this->reservations->put($reservation->id, $reservation);
        $this->setEnabledExtras($reservation->id, new EnabledExtras($component, 'enabledExtras'));
        $this->enabledExtrasCollection[$reservation->id]->extras = collect();
        $this->setEnabledOptions($reservation->id, new EnabledOptions($component, 'enabledOptions'));
        $this->enabledOptionsCollection[$reservation->id]->options = collect();
        $this->extraConditionsCollection->put($reservation->id, collect());
        
        return $this;
    }

    public function getEnabledExtras(string $reservationId): ?EnabledExtras
    {
        return $this->enabledExtrasCollection->get($reservationId);
    }

    public function setEnabledExtras(string $reservationId, EnabledExtras $extras): self
    {
        $this->enabledExtrasCollection->put($reservationId, $extras);
        
        return $this;
    }

    public function getEnabledOptions(string $reservationId): ?EnabledOptions
    {
        return $this->enabledOptionsCollection->get($reservationId);
    }

    public function setEnabledOptions(string $reservationId, EnabledOptions $options): self
    {
        $this->enabledOptionsCollection->put($reservationId, $options);
        
        return $this;
    }

    public function getExtraConditions(string $reservationId): ?Collection
    {
        return $this->extraConditionsCollection->get($reservationId);
    }

    public function setExtraConditions(string $reservationId, Collection $conditions): self
    {
        $this->extraConditionsCollection->put($reservationId, $conditions);
        
        return $this;
    }
}
