<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'target_roles' => ['nullable', 'array', 'max:20'],
            'target_roles.*' => ['string', 'max:120'],
            'target_locations' => ['nullable', 'array', 'max:20'],
            'target_locations.*' => ['string', 'max:120'],
            'target_comp_min_cents' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
        ];
    }
}
