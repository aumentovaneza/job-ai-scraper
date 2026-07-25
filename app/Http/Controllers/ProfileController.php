<?php

namespace App\Http\Controllers;

use App\Exceptions\ResumeParseException;
use App\Http\Requests\Profile\ExtractVoiceRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UploadResumeRequest;
use App\Jobs\ExtractVoiceProfileJob;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AnthropicKeyService;
use App\Services\ResumeParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated user's matching profile + onboarding state (T-12/T-13/T-14).
 * Every action resolves the profile via $request->user(), so the BelongsToUser
 * scope and this access path together guarantee a user only ever touches their
 * own profile.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AnthropicKeyService $keys) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->profileFor($request->user());
        $profile->fill($request->validated())->save();

        return response()->json($this->payload($request->user()->refresh()));
    }

    /**
     * Accept a PDF/DOCX resume, extract its text synchronously (parsing is local
     * and fast), and store it on the profile (T-13).
     */
    public function uploadResume(UploadResumeRequest $request, ResumeParser $parser): JsonResponse
    {
        $file = $request->file('resume');
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $text = $parser->extractText($file->getRealPath(), $extension);
        } catch (ResumeParseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $profile = $this->profileFor($request->user());
        $profile->forceFill(['resume_text' => $text])->save();

        return response()->json($this->payload($request->user()->refresh()));
    }

    /**
     * Queue voice-profile extraction over the stored resume + optional writing
     * sample (T-14). Requires a resume and a verified key up front so we fail fast
     * instead of enqueueing a job that can't run.
     */
    public function extractVoice(ExtractVoiceRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->profileFor($user);

        if (blank($profile->resume_text)) {
            return response()->json(['message' => 'Upload a resume before generating a voice profile.'], 422);
        }

        if ($user->anthropic_key_verified_at === null) {
            return response()->json(['message' => 'Add and verify your Anthropic key first.'], 422);
        }

        ExtractVoiceProfileJob::dispatch($profile->id, $request->validated('writing_sample'));

        return response()->json(['message' => 'Voice profile generation started.'], 202);
    }

    private function profileFor(User $user): Profile
    {
        return $user->profile()->firstOrCreate([]);
    }

    /**
     * The full profile + BYOK + settings + onboarding snapshot the SPA renders.
     * Never includes resume_text verbatim (it can be large) — only its size.
     */
    private function payload(User $user): array
    {
        $profile = $this->profileFor($user);

        $keyVerified = $user->anthropic_key_verified_at !== null;
        $hasResume = filled($profile->resume_text);
        $hasTargets = ! empty($profile->target_roles);

        return [
            'profile' => [
                'headline' => $profile->headline,
                'summary' => $profile->summary,
                'target_roles' => $profile->target_roles ?? [],
                'target_locations' => $profile->target_locations ?? [],
                'target_comp_min_cents' => $profile->target_comp_min_cents,
                'resume_chars' => $hasResume ? mb_strlen($profile->resume_text) : 0,
                'has_voice_profile' => ! empty($profile->voice_profile),
                'updated_at' => optional($profile->updated_at)?->toIso8601String(),
            ],
            'key' => [
                'has_key' => $this->keys->hasKey($user),
                'masked' => $this->keys->masked($user),
                'verified_at' => optional($user->anthropic_key_verified_at)?->toIso8601String(),
            ],
            'settings' => [
                'timezone' => $user->timezone,
                'daily_ai_spend_cap_cents' => $user->daily_ai_spend_cap_cents,
                'weekly_ai_spend_cap_cents' => $user->weekly_ai_spend_cap_cents,
            ],
            'onboarding' => [
                'key_verified' => $keyVerified,
                'resume' => $hasResume,
                'targets' => $hasTargets,
                'complete' => $keyVerified && $hasResume && $hasTargets,
            ],
        ];
    }
}
