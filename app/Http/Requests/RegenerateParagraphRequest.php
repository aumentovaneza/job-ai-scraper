<?php

namespace App\Http\Requests;

use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a "regenerate this paragraph" request (T-52). The version must belong
 * to the letter in the route; the paragraph is the untrusted text to rewrite.
 */
class RegenerateParagraphRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_id' => ['required', 'integer'],
            'paragraph' => ['required', 'string', 'max:4000'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var CoverLetter $coverLetter */
            $coverLetter = $this->route('coverLetter');

            $belongs = CoverLetterVersion::query()
                ->whereKey($this->input('version_id'))
                ->where('cover_letter_id', $coverLetter->id)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add(
                    'version_id',
                    'The selected version does not belong to this cover letter.',
                );
            }
        });
    }
}
