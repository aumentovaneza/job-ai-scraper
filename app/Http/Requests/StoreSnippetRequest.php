<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSnippetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'content_md' => ['required', 'string'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
