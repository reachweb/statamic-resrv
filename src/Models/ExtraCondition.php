<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Reach\StatamicResrv\Database\Factories\ExtraConditionFactory;
use Reach\StatamicResrv\Traits\HandlesComparisons;

class ExtraCondition extends Model
{
    use HandlesComparisons, HasFactory;

    protected $table = 'resrv_extra_conditions';

    protected $primaryKey = 'extra_id';

    protected $guarded = [];

    protected $casts = [
        'conditions' => AsCollection::class,
    ];

    protected static function newFactory()
    {
        return ExtraConditionFactory::new();
    }

    public function parent()
    {
        return $this->belongsTo(Extra::class, 'extra_id');
    }

    public function requiredExtrasForEntry($statamic_id)
    {
        $extras = Extra::entriesWithConditions($statamic_id)
            ->get()
            ->transform(function ($extra) {
                $extra->conditions = collect(json_decode($extra->conditions));

                return $extra;
            });

        if ($extras->count() == 0) {
            return false;
        }

        $requiredConditions = $extras->mapWithKeys(function ($extra) {
            $required = $extra->conditions->filter(function ($condition) {
                return $condition->operation == 'required';
            });

            return [$extra->id => $required];
        });

        if ($requiredConditions->count() == 0) {
            return false;
        }

        return $requiredConditions;
    }

    public function hasRequiredExtrasSelected($statamic_id, $data)
    {
        $required = $this->requiredExtrasThatApply($statamic_id, $data);

        if (! $required || $required->count() == 0) {
            return true;
        }

        if (! Arr::exists($data, 'extras')) {
            return $required;
        }

        $check = $required->filter(function ($messages, $extra) use ($data) {
            if (! $data['extras']->contains('id', $extra)) {
                return true;
            }
        });

        if ($check->count() == 0) {
            return true;
        }

        return $check;
    }

    public function requiredExtrasThatApply($statamic_id, $data)
    {
        $required = $this->requiredExtrasForEntry($statamic_id);

        if (! $required) {
            return false;
        }

        $extrasThatApply = $required->mapWithKeys(function ($conditions, $extra_id) use ($data) {
            $neededExtras = collect();
            foreach ($conditions as $condition) {
                $checkedParameters = $this->checkAllParameters($condition, $extra_id, $data);
                if ($checkedParameters) {
                    $neededExtras->push($checkedParameters);
                }
            }

            return [$extra_id => $neededExtras];
        })->filter(fn ($item) => $item->count() !== 0);

        return $extrasThatApply;
    }

    protected function checkAllParameters($condition, $extra_id, $data)
    {
        switch ($condition->type) {
            case 'always':
                return 'Extra always required';
                break;
            case 'pickup_time':
                if ($this->checkTime($condition, $data['date_start'])) {
                    return 'Extra required because pick up time is in condition range';
                }
                break;
            case 'dropoff_time':
                if ($this->checkTime($condition, $data['date_end'])) {
                    return 'Extra required because drop off up time is in condition range';
                }
                break;
            case 'reservation_dates':
                if ($this->checkDates($condition, $data)) {
                    return 'Extra required because reservation dates are in condition range';
                }
                break;
            case 'reservation_duration':
                if ($this->checkDuration($condition, $data)) {
                    return 'Extra required because reservation duration are in condition range';
                }
                break;
            case 'extra_selected':
                if ($this->checkSelected($condition, $data)) {
                    return 'Extra required because extra with ID '.$condition->value.' is selected';
                }
                break;
        }
    }

    protected function checkTime($condition, $date)
    {
        $time_start = Carbon::createFromTimeString($condition->time_start);
        $time_end = Carbon::createFromTimeString($condition->time_end)->addDay();
        $payload = new Carbon($date);

        return $payload->setDateFrom(today())->between($time_start, $time_end);
    }

    protected function checkDates($condition, $data)
    {
        $condition_start = new Carbon($condition->date_start);
        $condition_end = new Carbon($condition->date_end);
        $date_start = new Carbon($data['date_start']);
        $date_end = new Carbon($data['date_end']);
        if ($condition_start->lessThanOrEqualTo($date_start) && $condition_end->greaterThanOrEqualTo($date_end)) {
            return true;
        }

        return false;
    }

    protected function checkDuration($condition, $data)
    {
        $date_start = new Carbon($data['date_start']);
        $date_end = new Carbon($data['date_end']);
        $duration = $date_start->startOfDay()->diffInDays($date_end->startOfDay());

        return $this->compare($duration, $condition->comparison, $condition->value);
    }

    protected function checkSelected($condition, $data)
    {
        if (! Arr::exists($data, 'extras')) {
            return false;
        }
        if ($data['extras']->contains('id', $condition->value)) {
            return true;
        }

        return false;
    }
}
