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
