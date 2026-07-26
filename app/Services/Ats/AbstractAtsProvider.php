<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizesJobFields;
use Illuminate\Support\Str;

/**
 * Shared normalization helpers for ATS providers: HTML→text, remote-type
 * inference, timestamp coercion (via NormalizesJobFields), and deriving a
 * display company name from the source config/board token when the feed
 * doesn't carry one.
 */
abstract class AbstractAtsProvider implements AtsProvider
{
    use NormalizesJobFields;

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
}
