<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\ExtraCondition;

class ExtraConditionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ExtraCondition::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'extra_id' => '',
        ];
    }

    public function showExtraSelected()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'show',
                    'type' => 'extra_selected',
                    'value' => 1,
                ]],
            ];
        });
    }

    public function hideExtraSelected()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'hidden',
                    'type' => 'extra_selected',
                    'value' => 2,
                ]],
            ];
        });
    }

    public function requiredExtraSelected()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'required',
                    'type' => 'extra_selected',
                    'value' => 1,
                ]],
            ];
        });
    }

    public function requiredExtraNotSelected()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'required',
                    'type' => 'extra_not_selected',
                    'value' => 1,
                ]],
            ];
        });
    }

    public function requiredReservationTime()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'required',
                    'type' => 'pickup_time',
                    'time_start' => '21:00',
                    'time_end' => '08:00',
                ]],
            ];
        });
    }

    public function requiredReservationTimeAndShow()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [
                    [
                        'operation' => 'required',
                        'type' => 'pickup_time',
                        'time_start' => '21:00',
                        'time_end' => '08:00',
                    ],
                    [
                        'operation' => 'show',
                        'type' => 'pickup_time',
                        'time_start' => '21:00',
                        'time_end' => '08:00',
                    ],
                ],
            ];
        });
    }

    public function hideReservationDates()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'hidden',
                    'type' => 'reservation_dates',
                    'date_start' => today()->toIso8601String(),
                    'date_end' => today()->add(10, 'day')->toIso8601String(),
                ]],
            ];
        });
    }

    public function requiredReservationDates()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'required',
                    'type' => 'reservation_dates',
                    'date_start' => today()->toIso8601String(),
                    'date_end' => today()->add(10, 'day')->toIso8601String(),
                ]],
            ];
        });
    }

    public function requiredAlways()
    {
        return $this->state(function (array $attributes) {
            return [
                'conditions' => [[
                    'operation' => 'required',
                    'type' => 'always',
                ]],
            ];
        });
    }
}
