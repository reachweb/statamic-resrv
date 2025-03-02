<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Facades\Price;

trait HandlesParentReservations
{
    protected function getParentReservation()
    {
        $id = session('resrv_reservation');
        
        if (!$id) {
            throw new \Reach\StatamicResrv\Exceptions\ReservationException('No reservation found in the session.');
        }

        $reservation = Reservation::where('id', $id)
            ->where('type', 'parent')
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere('status', 'payment_processing');
            })
            ->first();

        if (!$reservation) {
            throw new \Reach\StatamicResrv\Exceptions\ReservationException('Reservation not found or not in pending state.');
        }

        return $reservation;
    }
    
    protected function getChildReservations()
    {
        $parent = $this->getParentReservation();
        
        return $parent->childs()
            ->join('resrv_reservations', 'resrv_child_reservations.child_reservation_id', '=', 'resrv_reservations.id')
            ->where('resrv_reservations.status', 'pending')
            ->get();
    }
    
    protected function calculateParentTotals()
    {
        $parent = $this->getParentReservation();
        $childReservations = Reservation::whereIn('id', $parent->childs->pluck('child_reservation_id'))
            ->where('status', 'pending')
            ->get();
            
        $totalPrice = Price::create(0);
        $totalPayment = Price::create(0);
        
        foreach ($childReservations as $reservation) {
            $totalPrice->add($reservation->price);
            $totalPayment->add($reservation->payment);
        }
        
        return [
            'price' => $totalPrice->format(),
            'payment' => $totalPayment->format(),
            'total' => $totalPrice->format(),
        ];
    }
}
