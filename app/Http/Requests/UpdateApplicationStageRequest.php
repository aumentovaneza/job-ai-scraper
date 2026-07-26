<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationStageRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller's policy check; the stage is
     * resolved through the BelongsToUser scope so route binding already 404s on
     * a foreign id.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_terminal' => ['sometimes', 'boolean'],
            'is_success' => ['sometimes', 'boolean'],
        ];
    }
}
