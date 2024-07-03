<?php

namespace Reach\StatamicResrv\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AffiliateCpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $affiliateId = $this->route('affiliate');

        return [
            'name' => ['required', 'string'],
            'code' => ['required', 'string', Rule::unique('resrv_affiliates')->ignore($affiliateId)],
            'email' => ['required', 'email', Rule::unique('resrv_affiliates')->ignore($affiliateId)],
            'cookie_duration' => ['required', 'integer'],
            'fee' => ['required', 'numeric'],
            'published' => ['boolean'],
            'allow_skipping_payment' => ['boolean'],
            'options' => ['nullable', 'json'],
        ];
    }
}
