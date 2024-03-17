<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Reach\StatamicResrv\Exceptions\BlueprintNotFoundException;
use Reach\StatamicResrv\Exceptions\FieldNotFoundException;
use Reach\StatamicResrv\Exceptions\NoAdvancedAvailabilitySet;
use Statamic\Facades\Blueprint;

trait QueriesStatamic
{
    public function getStatamicBlueprint()
    {
        if ($blueprint = Blueprint::find('collections.'.$this->advanced)) {
            return $blueprint;
        }
        throw new BlueprintNotFoundException($this->advanced);
    }

    public function getStatamicField($blueprint)
    {
        if ($field = $blueprint->field('resrv_availability')) {
            return $field;
        }
        throw new FieldNotFoundException('resrv_availability', $this->advanced);
    }

    public function getPropertiesFromBlueprint()
    {
        $blueprint = $this->getStatamicBlueprint();
        $field = $this->getStatamicField($blueprint);

        $config = $field->config();

        if (isset($config['advanced_availability'])) {
            return $config['advanced_availability'];
        }
        throw new NoAdvancedAvailabilitySet($this->advanced);
    }
}
