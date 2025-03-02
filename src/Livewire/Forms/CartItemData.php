<?php

namespace Reach\StatamicResrv\Livewire\Forms;

use Illuminate\Support\Str;
use Livewire\Form;

class CartItemData extends Form
{
    public string $id;
    public string $entryId;
    public array $availabilityData;
    public array $results;
    public bool $valid = true;

    public function __construct()
    {
        $this->id = Str::uuid()->toString();
    }

    public function __toString()
    {
        return $this->id.'-'.$this->entryId.'-'.json_encode($this->availabilityData);
    }

}
