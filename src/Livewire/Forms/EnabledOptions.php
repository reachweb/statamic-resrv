<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Form;

class EnabledOptions extends Form
{
    #[Validate]
    public Collection $options;

    public function rules(): array
    {
        return [
            'options' => 'nullable|array',
            'options.*.id' => [
                'required',
                'integer',
            ],
            'options.*.price' => [
                'required',
                'numeric',
            ],
            'options.*.value' => [
                'required',
                'integer',
            ],
        ];
    }
}
