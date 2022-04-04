<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Reach\StatamicResrv\Models\Reservation;

class CheckoutFormRequest extends FormRequest
{
    protected $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return $this->createValidationRules();
    }

    public function createValidationRules()
    {
        $rules = [];
        $form = $this->reservation->checkoutForm();
        foreach ($form as $field) {
            if (isset($field->config()['validate'])) {
                $rules[$field->handle()] = implode('|', $field->config()['validate']);
            } else {
                $rules[$field->handle()] = 'nullable';
            }
        }

        return $rules;
    }
}
