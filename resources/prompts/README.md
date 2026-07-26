# Prompt library

Every Claude prompt lives here as a versioned Markdown file — prompts are never
inlined in PHP (PLAN.md §7). Load them through `App\Services\Ai\Prompt`.

## Naming

```
<name>.v<n>.md        e.g. enrich_job.v1.md, match_score.v1.md
```

- `name` is lowercase `snake_case`; the trailing `.v<n>` is the version.
- **Never edit a shipped prompt in place — bump the version** (`enrich_job.v2.md`).
  Old `ai_calls` rows point at the version that produced them, so history stays
  reproducible.

## Available prompts

| Prompt | Purpose | Key variables |
| --- | --- | --- |
| `enrich_job.v1` | Extract structured summary from a raw JD | `jd_text` |
| `match_score.v1` | Score candidate↔job fit with reasoning | `headline`, `summary`, `resume_text`, `enrichment`, `jd_text`, … |
| `extract_voice_profile.v1` | Distill a candidate's writing style | (see file) |
| `follow_up.v1` | Draft a polite follow-up nudge | `job_title`, `company`, `current_stage`, `days_stale`, `contact`, `candidate_headline` |
| `letter_story_led.v1` | Cover letter, narrative/personal-motivation angle | `job_title`, `company`, `job_description`, `resume_text`, `voice_profile`, `company_context`, `length_hint`, `tone`, `custom_instructions` |
| `letter_results_led.v1` | Cover letter, quantified-achievements angle | same 9 as `letter_story_led.v1` |
| `letter_culture_led.v1` | Cover letter, company values/culture-fit angle | same 9 as `letter_story_led.v1` |

The three `letter_*` variants take an identical variable set and differ only in the
angle they lead with, so a caller can fan out to all three from one payload (T-52).

## Usage

```php
use App\Services\Ai\Prompt;

// Raw body:
$system = Prompt::load('enrich_job.v1');

// With {{ placeholders }} filled in (every placeholder must be supplied):
$content = Prompt::render('match_score.v1', [
    'jd_text' => $posting->jd_text,
    'profile' => $profileSummary,
]);
```

Wrap untrusted text (scraped JDs, resumes) in data tags **inside the template**
around the placeholder, e.g. `<jd>{{ jd_text }}</jd>`, and instruct the model to
treat tagged content as data, not instructions.

## Recording the version

Pass the versioned name as `promptVersion` so it lands on the `ai_calls` ledger:

```php
$client->messages(
    ['messages' => [['role' => 'user', 'content' => $content]]],
    purpose: 'jd_enrichment',
    promptVersion: 'enrich_job.v1',
);
```

For scored outputs, also store it in `match_scores.prompt_version` (T-32).
