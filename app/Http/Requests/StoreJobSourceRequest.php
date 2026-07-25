<?php

namespace App\Http\Requests;

use App\Models\JobSource;
use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', JobSource::class);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['ats_feed', 'career_page', 'rss'])],

            // ATS feeds are addressed by provider + board token; the human-facing
            // board URL is optional. Career pages / RSS need a real URL to fetch.
            'url' => ['nullable', 'required_unless:type,ats_feed', 'url', 'max:2048'],

            'config' => ['nullable', 'array'],
            'config.provider' => [
                'nullable',
                'required_if:type,ats_feed',
                Rule::in(['greenhouse', 'lever', 'workable', 'ashby']),
            ],
            'config.board_token' => ['nullable', 'required_if:type,ats_feed', 'string', 'max:255'],
            'config.company' => ['nullable', 'string', 'max:255'],

            'cron_schedule' => ['nullable', 'string', 'max:255', $this->cronRule()],
            'active' => ['boolean'],
        ];
    }

    /**
     * Validate a standard 5-field cron expression.
     */
    protected function cronRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (filled($value) && ! CronExpression::isValidExpression($value)) {
                $fail('The :attribute must be a valid cron expression.');
            }
        };
    }
}
