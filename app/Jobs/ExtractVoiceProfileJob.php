<?php

namespace App\Jobs;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AnthropicException;
use App\Models\Profile;
use App\Services\Ai\AnthropicClientFactory;
use App\Services\Ai\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * On-demand voice-profile extraction (T-14): runs Claude over the user's resume
 * text and an optional pasted writing sample, then stores the distilled style in
 * profiles.voice_profile. Queued because it makes an external, spend-capped AI
 * call — nothing AI-related happens in a web request (PLAN.md §7).
 */
class ExtractVoiceProfileJob implements ShouldQueue
{
    use Queueable;

    private const PROMPT_VERSION = 'extract_voice_profile.v1';

    public function __construct(
        public readonly int $profileId,
        public readonly ?string $writingSample = null,
    ) {}

    public function handle(AnthropicClientFactory $factory): void
    {
        // Worker context is unauthenticated; bypass the BelongsToUser scope and
        // resolve the owning user explicitly.
        $profile = Profile::withoutGlobalScopes()->with('user')->find($this->profileId);

        if ($profile === null || blank($profile->resume_text) || $profile->user === null) {
            return;
        }

        $content = Prompt::load(self::PROMPT_VERSION)
            ."\n\n<resume>\n".$profile->resume_text."\n</resume>";

        if (filled($this->writingSample)) {
            $content .= "\n\n<writing_sample>\n".$this->writingSample."\n</writing_sample>";
        }

        try {
            $response = $factory->forUser($profile->user)->messages(
                [
                    'max_tokens' => 1024,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ],
                purpose: 'voice_profile',
                referenceType: Profile::class,
                referenceId: $profile->id,
            );
        } catch (AiBudgetExceededException|AnthropicException $e) {
            // Ledger already records the failure; surface breakage in logs and stop.
            Log::warning('Voice profile extraction failed', [
                'profile_id' => $profile->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $voice = $this->parseVoiceProfile($response->text);

        if ($voice === null) {
            Log::warning('Voice profile response was not valid JSON', ['profile_id' => $profile->id]);

            return;
        }

        $voice['prompt_version'] = self::PROMPT_VERSION;
        $voice['generated_at'] = now()->toIso8601String();

        $profile->forceFill(['voice_profile' => $voice])->save();
    }

    /**
     * Parse the model's JSON, tolerating an accidental prose preamble or code fence
     * by extracting the first balanced object.
     */
    private function parseVoiceProfile(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
