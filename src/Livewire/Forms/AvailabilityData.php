<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Livewire\Form;
use Reach\StatamicResrv\Rules\ResrvAfterToday;
use Reach\StatamicResrv\Rules\ResrvCutoffTime;
use Reach\StatamicResrv\Rules\ResrvMaxQuantity;
use Reach\StatamicResrv\Rules\ResrvMinimumDate;
use Reach\StatamicResrv\Rules\ResrvMinimumDuration;

class AvailabilityData extends Form
{
    public array $dates = [];

    public int $quantity = 1;

    public ?string $advanced = null;

    public ?array $customer = [];

    public function rules(): array
    {
        return [
            'dates' => ['required', 'array', new ResrvMinimumDuration],
            'dates.date_start' => [
                'required',
                'date',
                'before:dates.date_end',
                new ResrvAfterToday,
                new ResrvMinimumDate,
                new ResrvCutoffTime,
            ],
            'dates.date_end' => [
                'required',
                'date',
                'after:dates.date_start',
                new ResrvAfterToday,
            ],
            'quantity' => ['sometimes', 'integer', new ResrvMaxQuantity],
            'advanced' => ['nullable', 'string'],
            'customer' => ['sometimes', 'array'],
        ];
    }

    public function validationAttributes()
    {
        return [
            'dates.date_start' => 'starting date',
            'dates.date_end' => 'ending date',
        ];
    }

    public function toResrvArray()
    {
        return [
            'date_start' => $this->dates['date_start'],
            'date_end' => $this->dates['date_end'],
            'quantity' => $this->quantity,
            'advanced' => $this->advanced,
        ];
    }

    public function hasDates()
    {
        return isset($this->dates['date_start']) && isset($this->dates['date_end']);
    }
}
