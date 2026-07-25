<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * The versioned prompt library (T-30, PLAN.md §7: prompts are versioned files,
 * never inlined in code). Prompt templates live in resources/prompts as Markdown
 * whose filename embeds the version, e.g. resources/prompts/enrich_job.v1.md,
 * loaded by its versioned name: Prompt::load('enrich_job.v1').
 *
 * The versioned name is the canonical identifier: pass it to
 * AnthropicClient::messages(promptVersion: ...) so every ai_calls row records
 * exactly which prompt produced the output, keeping results reproducible.
 *
 * Templates may contain {{ placeholders }} filled in via render().
 */
class Prompt
{
    /** A valid versioned prompt name, e.g. `enrich_job.v1`. */
    private const NAME_PATTERN = '/^[a-z0-9_]+\.v\d+$/';

    /** @var array<string, string> Loaded template bodies, keyed by name. */
    private static array $cache = [];

    /**
     * Return the raw template body for a versioned prompt name.
     *
     * @throws InvalidArgumentException on a malformed name or a missing file.
     */
    public static function load(string $name): string
    {
        self::assertValidName($name);

        return self::$cache[$name] ??= self::read($name);
    }

    /**
     * Load a prompt and substitute {{ placeholders }} with the given values.
     *
     * Every placeholder in the template must be supplied; a leftover placeholder
     * means the caller forgot a variable, so we fail loudly rather than ship a
     * half-rendered prompt to the model. Values are substituted verbatim — callers
     * are responsible for wrapping untrusted text (scraped JDs, resumes) in data
     * tags inside the template to guard against prompt injection (PLAN.md §7).
     *
     * @param  array<string, string|int|float|\Stringable>  $variables
     *
     * @throws InvalidArgumentException if a placeholder is left unfilled.
     */
    public static function render(string $name, array $variables = []): string
    {
        $template = self::load($name);

        $rendered = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            function (array $match) use ($variables, $name): string {
                $key = $match[1];

                if (! array_key_exists($key, $variables)) {
                    throw new InvalidArgumentException(
                        "Missing variable [{$key}] for prompt [{$name}]."
                    );
                }

                return (string) $variables[$key];
            },
            $template,
        );

        return $rendered;
    }

    /**
     * Whether a versioned prompt file exists on disk.
     */
    public static function exists(string $name): bool
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            return false;
        }

        return is_file(self::path($name));
    }

    /**
     * List every available versioned prompt name (without the .md extension),
     * sorted. Useful for validating a configured prompt version at boot.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = collect(glob(resource_path('prompts/*.md')) ?: [])
            ->map(fn (string $path): string => basename($path, '.md'))
            ->filter(fn (string $name): bool => (bool) preg_match(self::NAME_PATTERN, $name))
            ->sort()
            ->values()
            ->all();

        return $names;
    }

    private static function assertValidName(string $name): void
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid prompt name: {$name}");
        }
    }

    private static function read(string $name): string
    {
        $path = self::path($name);

        if (! is_file($path)) {
            throw new InvalidArgumentException("Prompt not found: {$name}");
        }

        return trim((string) file_get_contents($path));
    }

    private static function path(string $name): string
    {
        return resource_path("prompts/{$name}.md");
    }
}
