<?php

namespace App\Http\Requests;

use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Sets which version of a cover letter is the active/default one. The chosen
 * version must belong to the letter in the route.
 */
class SetActiveVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'active_version_id' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var CoverLetter $coverLetter */
            $coverLetter = $this->route('coverLetter');

            $belongs = CoverLetterVersion::query()
                ->whereKey($this->input('active_version_id'))
                ->where('cover_letter_id', $coverLetter->id)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add(
                    'active_version_id',
                    'The selected version does not belong to this cover letter.',
                );
            }
        });
    }
}
