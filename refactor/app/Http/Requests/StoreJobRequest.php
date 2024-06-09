<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'from_language_id' => 'required|integer',
            'immediate' => 'required',
            'due_date' => 'required_if:immediate,no',
            'due_time' => 'required_if:immediate,no',
            'customer_phone_type' => 'required_if:immediate,no',
            'customer_physical_type' => 'required_if:immediate,no',
            'duration' => 'required',
        ];
    }
}