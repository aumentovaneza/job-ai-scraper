<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id', 'provider', 'model', 'endpoint', 'input_tokens', 'output_tokens',
    'cost_cents', 'purpose', 'reference_type', 'reference_id', 'status', 'error',
])]
class AiCall extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_cents' => 'integer',
        ];
    }
}
