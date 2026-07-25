<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared normalization helpers for ATS providers: HTML→text, remote-type
 * inference, timestamp coercion, and deriving a display company name from the
 * source config/board token when the feed doesn't carry one.
 */
abstract class AbstractAtsProvider implements AtsProvider
{
    /** The board token identifying the company on the provider. */
    protected function boardToken(JobSource $source): string
    {
        $token = $source->config['board_token'] ?? null;

        return trim((string) $token);
    }

    /**
     * Company name for the postings. Feeds rarely include it, so prefer an
     * explicit config value, else humanize the board token.
     */
    protected function companyName(JobSource $source): string
    {
        $explicit = $source->config['company'] ?? null;

        if (filled($explicit)) {
            return (string) $explicit;
        }

        return Str::of($this->boardToken($source))
            ->replace(['-', '_'], ' ')
            ->title()
            ->toString();
    }

    /** Strip tags/entities from an HTML fragment into readable plain text. */
    protected function htmlToText(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        // Many feeds HTML-encode the fragment (Greenhouse), so decode first,
        // then turn block boundaries into newlines before stripping tags.
        $decoded = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/<\s*(br|\/p|\/div|\/li|\/h[1-6])\s*>/i', "\n", $decoded) ?? $decoded;
        $text = trim(strip_tags($decoded));
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $text === '' ? null : $text;
    }

    /**
     * Infer remote | hybrid | onsite from free-text hints (location, workplace
     * type, title). Returns null when nothing matches.
     */
    protected function inferRemoteType(?string ...$hints): ?string
    {
        $haystack = mb_strtolower(implode(' ', array_filter($hints)));

        if ($haystack === '') {
            return null;
        }

        return match (true) {
            str_contains($haystack, 'hybrid') => 'hybrid',
            str_contains($haystack, 'remote') || str_contains($haystack, 'anywhere') => 'remote',
            str_contains($haystack, 'onsite') || str_contains($haystack, 'on-site') || str_contains($haystack, 'in office') || str_contains($haystack, 'in-office') => 'onsite',
            default => null,
        };
    }

    /** Coerce an ISO string or epoch (seconds/millis) into an ISO-8601 string. */
    protected function toIso(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $ts = (int) $value;
                // Heuristic: 13-digit values are milliseconds.
                if ($ts > 9_999_999_999) {
                    $ts = intdiv($ts, 1000);
                }

                return CarbonImmutable::createFromTimestamp($ts)->toIso8601String();
            }

            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /** First non-blank string among the arguments, or null. */
    protected function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
