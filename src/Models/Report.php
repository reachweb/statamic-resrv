<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Facades\Price;

class Report
{
    protected $date_start;
    protected $date_end;
    protected $reservations;

    public function __construct($date_start, $date_end)
    {   
        $this->date_start = $date_start;
        $this->date_end = $date_end;
        $this->reservations = Reservation::whereDate('date_start', '>=', $this->date_start)
            ->whereDate('date_start', '<=', $this->date_end)
            ->where('status', 'confirmed')
            ->get();
    }

    public function countConfirmedReservations()
    {
        return $this->reservations->count();
    }
    
    public function sumConfirmedReservations()
    {
        return $this->reservations->sum(function ($reservation) {
            return $reservation->price->format();
        });
    }
    
    public function avgConfirmedReservations()
    {
        return $this->reservations->avg(function ($reservation) {
            return $reservation->price->format();
        });
    }

    public function topSellerItems()
    {
        $items = $this->getTopItems();
        $items->transform(function ($item) {
            return [
                'title' => $item->entry['title'],
                'api_url' => $item->entry['api_url'],
                'reservations' => (int)$item->occurrences,
                'total_revenue' => round($this->reservations->where('item_id', $item->item_id)->sum(function ($reservation) {
                    return $reservation->price->format();
                }), 2),
                'avg_revenue' => round($this->reservations->where('item_id', $item->item_id)->avg(function ($reservation) {
                    return $reservation->price->format();
                }), 2),
                'percentage' => round($item->occurrences / $this->countConfirmedReservations(), 2)
            ];            
        });

        return $items;
    }
    
    public function topSellerExtras()
    {
        $extras = $this->getTopExtras();
        $extras->transform(function ($item) {
            $extra = Extra::withTrashed()->find($item->extra_id);
            return [
                'title' => $extra->name,
                'reservations' => (int)$item->occurrences,
                'percentage' => round($item->occurrences / $this->countConfirmedReservations(), 2)          
            ];      
        });
        return $extras;
    }

    public function topStartLocations()
    {
        if (config('resrv-config.enable_locations') == false) {
            return null;
        }
        $locations = $this->getTopLocations();
        $locations->transform(function ($item) {
            return [
                'title' => $item->location_start_data->name,
                'reservations' => (int)$item->occurrences,
                'percentage' => round($item->occurrences / $this->countConfirmedReservations(), 2)          
            ];      
        });
        return $locations;
    }

    protected function getTopItems()
    {
        return Reservation::select('item_id')
            ->addSelect(DB::raw('COUNT(item_id) AS occurrences'))
            ->whereDate('date_start', '>=', $this->date_start)
            ->whereDate('date_start', '<=', $this->date_end)
            ->where('status', 'confirmed')
            ->groupBy('item_id')
            ->orderBy('occurrences', 'DESC')
            ->limit(10)
            ->get('occurrences');
    }
    
    protected function getTopExtras()
    {
        return DB::table('resrv_reservation_extra')->select('extra_id', 'quantity')
            ->addSelect(DB::raw('COUNT(reservation_id) AS occurrences'))
            ->whereIn('reservation_id', $this->reservations->pluck('id'))           
            ->groupBy('extra_id')
            ->orderBy('occurrences', 'DESC')
            ->limit(10)
            ->get('occurrences');
    }

    protected function getTopLocations()
    {
        return Reservation::select('location_start')
            ->addSelect(DB::raw('COUNT(location_start) AS occurrences'))
            ->whereDate('date_start', '>=', $this->date_start)
            ->whereDate('date_start', '<=', $this->date_end)
            ->where('status', 'confirmed')
            ->groupBy('location_start')
            ->orderBy('occurrences', 'DESC')
            ->limit(10)
            ->get('occurrences');
    }

}
