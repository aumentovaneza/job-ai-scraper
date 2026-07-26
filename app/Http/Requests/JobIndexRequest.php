<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may browse the shared job catalog.
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:200'],
            'source_id' => ['sometimes', 'integer'],
            'remote_type' => ['sometimes', 'string', 'in:remote,hybrid,onsite'],
            'salary_min' => ['sometimes', 'integer', 'min:0'],
            'posted_after' => ['sometimes', 'date'],
            'score_status' => ['sometimes', 'string', 'in:scored,unscored'],
            'score_min' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'score_max' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
