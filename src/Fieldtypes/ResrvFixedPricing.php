<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Reach\StatamicResrv\Models\FixedPricing as Fixed;

class ResrvFixedPricing extends Fieldtype
{
    protected $icon = '<svg viewBox="0 0 24 24" height="24" width="24" xmlns="http://www.w3.org/2000/svg"><g transform="matrix(1,0,0,1,0,0)"><path d="M2.311,2.256h8.728a0,0,0,0,1,0,0v5.5a.5.5,0,0,1-.5.5H2.311a.5.5,0,0,1-.5-.5v-5a.5.5,0,0,1,.5-.5Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" transform="translate(3.491 -2.509) rotate(30.012)"></path><path d="M11.921,14.493l-.971,3.122a.5.5,0,0,1-.626.329L5.282,16.376a.5.5,0,0,1-.329-.626l.822-2.644a.5.5,0,0,1,.625-.329Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M13.26,5.876h8.5a.5.5,0,0,1,.5.5v5.5a0,0,0,0,1,0,0h-9a.5.5,0,0,1-.5-.5v-5A.5.5,0,0,1,13.26,5.876Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round" transform="translate(36.764 5.053) rotate(141.884)"></path><path d="M11.921 20.466L11.921 1.466" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M5.106 5.101 A1.250 1.250 0 1 0 7.606 5.101 A1.250 1.250 0 1 0 5.106 5.101 Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M16.097 8.918 A1.250 1.250 0 1 0 18.597 8.918 A1.250 1.250 0 1 0 16.097 8.918 Z" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path><path d="M8.977,23.466,8.2,21.124a.5.5,0,0,1,.475-.658h6.612a.5.5,0,0,1,.475.658l-.781,2.342" fill="none" stroke="#000000" stroke-linecap="round" stroke-linejoin="round"></path></g></svg>';
 
    public function augment($value)
    {
        return Fixed::entry($this->field->parent()->id())            
            ->orderBy('days')
            ->get()            
            ->toArray();
    }

    public function preload()
    {
        if (class_basename($this->field->parent()) == 'Collection') {
            return ['parent' => 'Collection'];
        }
        if ($this->field->parent()->hasOrigin()) {
            return ['parent' => $this->field->parent()->origin()->id()];
        }
        return ['parent' => $this->field->parent()->id()];
    }
}