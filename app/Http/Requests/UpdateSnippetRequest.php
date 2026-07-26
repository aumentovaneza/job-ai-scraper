<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSnippetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:120'],
            'content_md' => ['sometimes', 'string'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
