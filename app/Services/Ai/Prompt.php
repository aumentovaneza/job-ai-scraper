<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * Loads versioned prompt templates from resources/prompts (PLAN.md §7: prompts
 * are versioned files, never inlined in code). Names embed the version, e.g.
 * Prompt::load('extract_voice_profile.v1').
 *
 * This is the minimal loader Phase 1 needs; T-30 formalises the full library.
 */
class Prompt
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function load(string $name): string
    {
        if (! preg_match('/^[a-z0-9_]+\.v\d+$/', $name)) {
            throw new InvalidArgumentException("Invalid prompt name: {$name}");
        }

        return self::$cache[$name] ??= self::read($name);
    }

    private static function read(string $name): string
    {
        $path = resource_path("prompts/{$name}.md");

        if (! is_file($path)) {
            throw new InvalidArgumentException("Prompt not found: {$name}");
        }

        return trim((string) file_get_contents($path));
    }
}
