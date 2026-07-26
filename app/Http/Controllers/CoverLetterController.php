<?php

namespace App\Http\Controllers;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiException;
use App\Http\Requests\GenerateLetterRequest;
use App\Http\Requests\RegenerateParagraphRequest;
use App\Http\Requests\SetActiveVersionRequest;
use App\Http\Requests\UpdateCoverLetterVersionRequest;
use App\Jobs\GenerateLetterJob;
use App\Models\Application;
use App\Models\CoverLetter;
use App\Models\CoverLetterVersion;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\LetterQuota;
use App\Services\Ai\Prompt;
use App\Services\CompanyContextService;
use Illuminate\Http\JsonResponse;

/**
 * Cover-letter API (T-52). Full generation runs off the request path in
 * GenerateLetterJob; only the small single-paragraph rewrite is a synchronous
 * Claude call here. Every action is user-scoped via the BelongsToUser scope +
 * ApplicationPolicy / CoverLetterPolicy / CoverLetterVersionPolicy, and the T-56
 * soft cap surfaces a nudge (never blocks) via LetterQuota.
 */
class CoverLetterController extends Controller
{
    private const PARAGRAPH_PROMPT = 'regenerate_paragraph.v1';

    public function __construct(private readonly LetterQuota $quota) {}

    /**
     * The application's cover letter with its versions (newest first), or
     * {data: null} when the user hasn't drafted one yet.
     */
    public function show(Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        $coverLetter = CoverLetter::query()
            ->where('application_id', $application->id)
            ->with(['versions' => fn ($q) => $q->latest('id')])
            ->first();

        return response()->json(['data' => $coverLetter]);
    }

    /**
     * Draft (or redraft) the letter: ensure the one-per-application CoverLetter
     * row exists, then fan out variant generation on the ai queue.
     */
    public function generate(GenerateLetterRequest $request, Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        $coverLetter = CoverLetter::firstOrCreate(
            ['application_id' => $application->id],
            ['user_id' => $application->user_id],
        );

        GenerateLetterJob::dispatch($coverLetter->id, $request->jobParams());

        return response()->json(array_filter([
            'data' => $coverLetter->load(['versions' => fn ($q) => $q->latest('id')]),
            'nudge' => $this->quota->nudgeFor($request->user()),
        ], fn ($value) => $value !== null), 202);
    }

    /**
     * Choose which version is the active/default one for this letter.
     */
    public function setActiveVersion(SetActiveVersionRequest $request, CoverLetter $coverLetter): JsonResponse
    {
        $this->authorize('update', $coverLetter);

        $coverLetter->update(['active_version_id' => $request->validated('active_version_id')]);

        return response()->json(['data' => $coverLetter]);
    }

    /**
     * Save the user's edits to one version's Markdown body (the editor autosave).
     */
    public function updateVersion(UpdateCoverLetterVersionRequest $request, CoverLetterVersion $version): JsonResponse
    {
        $this->authorize('update', $version);

        $version->update($request->validated());

        return response()->json(['data' => $version]);
    }

    /**
     * Rewrite a single paragraph in the same voice/context (a small, synchronous
     * Claude call). Returns just the replacement text for the UI to splice in.
     */
    public function regenerateParagraph(
        RegenerateParagraphRequest $request,
        CoverLetter $coverLetter,
        AiClientFactory $factory,
        CompanyContextService $companyContext,
    ): JsonResponse {
        $this->authorize('update', $coverLetter);

        $application = Application::withoutGlobalScopes()->find($coverLetter->application_id);
        $jobPosting = $application?->jobPosting()->first();

        $version = CoverLetterVersion::findOrFail($request->validated('version_id'));
        $params = $version->generation_params ?? [];

        $facts = $companyContext->factsFor($jobPosting?->company ?? '', $jobPosting?->apply_url);

        $content = Prompt::render(self::PARAGRAPH_PROMPT, [
            'job_title' => $jobPosting?->title ?? 'the role',
            'company' => $jobPosting?->company ?? 'the company',
            'tone' => (string) ($params['tone'] ?? 'professional'),
            'company_context' => ($facts !== null && trim($facts) !== '') ? $facts : 'not provided',
            'paragraph' => $request->validated('paragraph'),
            'instructions' => trim((string) $request->validated('instructions')) ?: 'none',
        ]);

        try {
            $response = $factory->forUser($request->user())->messages(
                [
                    'max_tokens' => 400,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ],
                purpose: 'letter_paragraph',
                referenceType: CoverLetter::class,
                referenceId: $coverLetter->id,
                promptVersion: self::PARAGRAPH_PROMPT,
            );
        } catch (AiBudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_filter([
            'data' => ['text' => trim($response->text)],
            'nudge' => $this->quota->nudgeFor($request->user()),
        ], fn ($value) => $value !== null));
    }
}
