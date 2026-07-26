<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The editor save target (T-52/T-53): persists a user's edits to one version's
 * Markdown body.
 */
class UpdateCoverLetterVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_md' => ['required', 'string'],
        ];
    }
}
