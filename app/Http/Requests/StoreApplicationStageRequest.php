<?php

namespace App\Http\Requests;

use App\Models\ApplicationStage;
use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ApplicationStage::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_terminal' => ['boolean'],
            'is_success' => ['boolean'],
        ];
    }
}
