<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveApplicationRequest extends FormRequest
{
    /**
     * The application is resolved through the BelongsToUser scope and gated by
     * ApplicationPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Target stage must belong to the caller. The raw exists query
            // bypasses the Eloquent scope, so it is constrained explicitly.
            'target_stage_id' => [
                'required',
                'integer',
                Rule::exists('application_stages', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
