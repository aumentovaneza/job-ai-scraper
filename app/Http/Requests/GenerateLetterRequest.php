<?php

namespace App\Http\Requests;

use App\Jobs\GenerateLetterJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a cover-letter generation request (T-52). All fields are optional —
 * sensible defaults are applied so the UI can fire a bare "Draft letter" click.
 */
class GenerateLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'length_hint' => ['sometimes', Rule::in(['short', 'medium', 'long'])],
            'tone' => ['sometimes', 'string', 'max:60'],
            'custom_instructions' => ['nullable', 'string', 'max:2000'],
            'variants' => ['sometimes', 'array', 'min:1'],
            'variants.*' => [Rule::in(GenerateLetterJob::DEFAULT_VARIANTS)],
        ];
    }

    /**
     * The generation params passed to GenerateLetterJob, defaults applied.
     *
     * @return array{length_hint: string, tone: string, custom_instructions: ?string, variants: list<string>}
     */
    public function jobParams(): array
    {
        return [
            'length_hint' => $this->validated('length_hint', 'medium'),
            'tone' => $this->validated('tone', 'professional'),
            'custom_instructions' => $this->validated('custom_instructions'),
            'variants' => $this->validated('variants', GenerateLetterJob::DEFAULT_VARIANTS),
        ];
    }
}
