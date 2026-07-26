<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Shared normalization/coercion helpers for scrapers that emit NormalizedJob.
 *
 * Originally lived on AbstractAtsProvider; extracted here so the generic
 * JsonApiScraper (T-30+) can reuse the exact same HTML→text, remote-type
 * inference and timestamp coercion, plus the extra path/salary/tag coercions a
 * config-driven field map needs. Methods are `protected` so the trait can be
 * mixed into both the ATS provider base class and standalone service classes.
 */
trait NormalizesJobFields
{
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

    /**
     * Coerce a mapped remote-type value. Booleans map true→remote / false→null
     * (a false "remote" flag is not a claim of onsite). Strings are accepted
     * only when they name a canonical type, else run through inference.
     */
    protected function coerceRemoteType(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'remote' : null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        if (in_array($normalized, ['remote', 'hybrid', 'onsite'], true)) {
            return $normalized;
        }

        return $this->inferRemoteType($value);
    }

    /**
     * Coerce a salary value to a positive integer. Accepts numerics and
     * human strings like "$120k" / "150,000"; treats <= 0 (a common "unknown"
     * sentinel on aggregator feeds) as null.
     */
    protected function coerceSalaryInt(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $amounts = $this->parseSalaryAmounts($value);

        return $amounts[0] ?? null;
    }

    /**
     * Parse a freeform salary string into [min, max, currency]. Handles ranges
     * ("$100k - $120k"), k/m suffixes, and the common $/€/£ currency glyphs.
     * Any component may be null.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    protected function parseSalary(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [null, null, null];
        }

        $currency = match (true) {
            str_contains($value, '$') => 'USD',
            str_contains($value, '€') => 'EUR',
            str_contains($value, '£') => 'GBP',
            default => null,
        };

        $amounts = $this->parseSalaryAmounts($value);

        return [$amounts[0] ?? null, $amounts[1] ?? null, $currency];
    }

    /**
     * Pull the numeric amounts out of a salary string, honouring k/m suffixes.
     *
     * @return array<int, int> positive amounts, in the order they appear
     */
    protected function parseSalaryAmounts(string $value): array
    {
        if (! preg_match_all('/([0-9][0-9,\.]*)\s*([kKmM])?/u', $value, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $amounts = [];

        foreach ($matches as $match) {
            $digits = str_replace([',', ' '], '', $match[1]);

            if ($digits === '' || ! is_numeric($digits)) {
                continue;
            }

            $amount = (float) $digits;
            $suffix = mb_strtolower($match[2] ?? '');

            $amount *= match ($suffix) {
                'k' => 1_000,
                'm' => 1_000_000,
                default => 1,
            };

            $amount = (int) round($amount);

            if ($amount > 0) {
                $amounts[] = $amount;
            }
        }

        return $amounts;
    }

    /** Uppercase 3-letter currency code, or null. */
    protected function coerceCurrency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $alpha = preg_replace('/[^a-zA-Z]/', '', $value) ?? '';

        if (mb_strlen($alpha) < 3) {
            return null;
        }

        return mb_strtoupper(mb_substr($alpha, 0, 3));
    }

    /** A valid http(s) URL, or null. */
    protected function coerceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /**
     * Normalize a mapped tags value into a clean, de-duplicated string list.
     * Scalars are wrapped; nested/object elements are ignored. Capped to keep
     * queue payloads and the jsonb column bounded.
     *
     * @return array<int, string>
     */
    protected function coerceTags(mixed $value, int $maxCount = 40, int $maxLength = 80): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $tags = [];

        foreach ($items as $item) {
            if (is_array($item) || is_object($item) || is_bool($item)) {
                continue;
            }

            $tag = trim((string) $item);

            if ($tag === '' || mb_strlen($tag) > $maxLength) {
                continue;
            }

            $tags[$tag] = true; // dedupe, preserve first-seen order
        }

        return array_slice(array_keys($tags), 0, $maxCount);
    }
}
