<?php

namespace App\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            // The shared job catalog is not user-scoped, so plain existence.
            // One application per (user, job): the unique guard is scoped to the
            // caller so two users can independently track the same posting.
            'job_posting_id' => [
                'required',
                'integer',
                Rule::exists('job_postings', 'id'),
                Rule::unique('applications', 'job_posting_id')->where('user_id', $userId),
            ],

            // Optional starting stage; must be one of the caller's own stages.
            // The raw exists query bypasses the Eloquent scope, so constrain it.
            'stage_id' => [
                'nullable',
                'integer',
                Rule::exists('application_stages', 'id')->where('user_id', $userId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'job_posting_id.unique' => 'You are already tracking this job.',
        ];
    }
}
