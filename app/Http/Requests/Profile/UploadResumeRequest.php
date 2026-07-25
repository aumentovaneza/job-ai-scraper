<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UploadResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // PDF/DOCX only (PLAN.md T-13). 8 MB ceiling.
            'resume' => ['required', 'file', 'mimes:pdf,docx', 'max:8192'],
        ];
    }
}
