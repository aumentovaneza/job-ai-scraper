<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'required', 'string', Rule::in(timezone_identifiers_list())],
            // Spend caps in cents; null clears the cap (unlimited for that window).
            'daily_ai_spend_cap_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000000'],
            'weekly_ai_spend_cap_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000000'],
        ];
    }
}
