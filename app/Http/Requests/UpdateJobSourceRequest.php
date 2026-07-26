<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesJobSourceConfig;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobSourceRequest extends FormRequest
{
    use ValidatesJobSourceConfig;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('jobSource'));
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(['ats_feed', 'career_page', 'rss', 'json_api'])],
            'url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'config' => ['sometimes', 'nullable', 'array'],
            'config.provider' => ['sometimes', 'nullable', Rule::in(['greenhouse', 'lever', 'workable', 'ashby'])],
            'config.board_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cron_schedule' => ['sometimes', 'nullable', 'string', 'max:255', $this->cronRule()],
            'active' => ['sometimes', 'boolean'],

            ...$this->sharedConfigRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateJsonApiConfig($v));
    }

    protected function cronRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (filled($value) && ! CronExpression::isValidExpression($value)) {
                $fail('The :attribute must be a valid cron expression.');
            }
        };
    }
}
