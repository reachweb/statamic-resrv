<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Livewire\Form;
use Reach\StatamicResrv\Rules\ResrvMinimumDate;

class AvailabilityData extends Form
{
    public array $dates = [];

    public int $quantity = 1;

    public string $property;

    public function rules()
    {
        return [
            'dates' => ['required', 'array'],
            'dates.date_start' => [
                'required',
                'date',
                'before:dates.date_end',
                'after_or_equal:today',
                new ResrvMinimumDate,
            ],
            'dates.date_end' => [
                'required',
                'date',
                'after:dates.date_start',
                'after:today',
            ],
            'quantity' => ['sometimes', 'integer'],
            'property' => ['nullable', 'string'],
        ];
    }
}