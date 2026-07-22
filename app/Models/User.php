<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'email', 'password', 'is_admin', 'timezone',
    'daily_ai_spend_cap_cents', 'weekly_ai_spend_cap_cents',
])]
#[Hidden(['password', 'remember_token', 'encrypted_anthropic_key'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'anthropic_key_verified_at' => 'datetime',
            'daily_ai_spend_cap_cents' => 'integer',
            'weekly_ai_spend_cap_cents' => 'integer',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function jobSources(): HasMany
    {
        return $this->hasMany(JobSource::class);
    }

    public function applicationStages(): HasMany
    {
        return $this->hasMany(ApplicationStage::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function matchScores(): HasMany
    {
        return $this->hasMany(MatchScore::class);
    }

    public function aiCalls(): HasMany
    {
        return $this->hasMany(AiCall::class);
    }
}
