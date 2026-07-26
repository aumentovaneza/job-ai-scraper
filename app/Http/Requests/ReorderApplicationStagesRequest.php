<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderApplicationStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The full ordered list of the caller's stage ids. The raw `exists`
            // query bypasses the Eloquent global scope, so it is constrained to
            // the caller's own rows explicitly to preserve tenant isolation.
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => [
                'integer',
                Rule::exists('application_stages', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
