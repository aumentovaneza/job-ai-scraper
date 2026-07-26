<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The user reviews a draft, then marks it sent or dismisses it.
            'status' => ['required', Rule::in(['pending', 'drafted', 'sent', 'dismissed'])],
        ];
    }
}
