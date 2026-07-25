<?php

namespace App\Http\Requests;

use App\Services\Ai\AiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may set their own key; the route is auth-gated
        // and the controller always operates on $request->user().
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(AiProvider::class)],
            // Provider keys look like sk-ant-… / sk-…; keep the prefix check soft
            // (a custom base_url/proxy may differ) but require a plausible length.
            'key' => ['required', 'string', 'min:20', 'max:255'],
        ];
    }
}
