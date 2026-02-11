<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Livewire\Form;
use Reach\StatamicResrv\Rules\ResrvAfterToday;
use Reach\StatamicResrv\Rules\ResrvMaxQuantity;
use Reach\StatamicResrv\Rules\ResrvMinimumDate;
use Reach\StatamicResrv\Rules\ResrvMinimumDuration;

class AvailabilityData extends Form
{
    public array $dates = [];

    public int $quantity = 1;

    public ?string $rate = null;

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
            ],
            'dates.date_end' => [
                'required',
                'date',
                'after:dates.date_start',
                new ResrvAfterToday,
            ],
            'quantity' => ['sometimes', 'integer', new ResrvMaxQuantity],
            'rate' => ['nullable', 'string'],
            'customer' => ['sometimes', 'array'],
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'dates.date_start' => 'starting date',
            'dates.date_end' => 'ending date',
        ];
    }

    public function toResrvArray(): array
    {
        $rateValue = ($this->rate && $this->rate !== 'any') ? $this->rate : null;

        return [
            'date_start' => $this->dates['date_start'] ?? null,
            'date_end' => $this->dates['date_end'] ?? null,
            'quantity' => $this->quantity,
            'rate_id' => $rateValue,
            'advanced' => $this->rate ?? '',
        ];
    }

    public function hasDates(): bool
    {
        return isset($this->dates['date_start']) && isset($this->dates['date_end']);
    }
}
