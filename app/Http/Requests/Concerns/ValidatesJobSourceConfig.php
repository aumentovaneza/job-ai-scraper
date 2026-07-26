<?php

namespace App\Http\Requests\Concerns;

use App\Services\JsonApi\JsonApiScraper;
use App\Support\UrlGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared validation for JobSource config across Store/Update requests: the
 * json_api field-map rules and the source-level acceptability flags. Keeps the
 * two FormRequests from drifting (mirrors the existing shared cronRule pattern).
 */
trait ValidatesJobSourceConfig
{
    /**
     * Rules common to both requests. The caller supplies type/url rules (they
     * differ: required on store, sometimes on update).
     *
     * @return array<string, mixed>
     */
    protected function sharedConfigRules(): array
    {
        return [
            // json_api: url is already forced by the caller's required_unless /
            // sometimes rule; here we shape the config-driven field map.
            'config.items_path' => ['nullable', 'string', 'max:255', 'regex:/^[\w]+(\.[\w*]+)*$/'],
            'config.headers' => ['nullable', 'array', 'max:20'],
            'config.headers.*' => ['string', 'max:1024'],
            'config.field_map' => ['nullable', 'required_if:type,json_api', 'array'],
            'config.field_map.title' => ['required_if:type,json_api'],
            'config.field_map.company' => ['required_if:type,json_api'],

            // Source-level acceptability flags (Part B).
            'hires_internationally' => ['sometimes', 'boolean'],
            'timezone_overlap' => ['nullable', Rule::in(['any', 'partial', 'strict'])],
        ];
    }

    /**
     * Cross-field checks for json_api sources: only known targets may be mapped,
     * each to a non-empty path (or list of paths); and the url must be a public
     * http(s) target (a syntactic SSRF pre-check — the scraper re-checks at
     * fetch time). Guarded on `type` being json_api so a partial PATCH that
     * omits type (e.g. toggling `active`) doesn't trip these rules.
     */
    protected function validateJsonApiConfig(Validator $validator): void
    {
        if ($this->input('type') !== 'json_api') {
            return;
        }

        $url = $this->input('url');
        if (is_string($url) && $url !== '' && ! UrlGuard::isPublicHttpUrl($url)) {
            $validator->errors()->add('url', 'The url must be a public http(s) address.');
        }

        $fieldMap = $this->input('config.field_map');
        if (! is_array($fieldMap)) {
            return; // the array rule already reported it
        }

        foreach ($fieldMap as $target => $spec) {
            if (! in_array($target, JsonApiScraper::ALLOWED_TARGETS, true)) {
                $validator->errors()->add(
                    "config.field_map.{$target}",
                    "'{$target}' is not a mappable field. Allowed: ".implode(', ', JsonApiScraper::ALLOWED_TARGETS).'.'
                );

                continue;
            }

            if (! $this->isValidPathSpec($spec)) {
                $validator->errors()->add(
                    "config.field_map.{$target}",
                    'Each field map value must be a non-empty path or a list of non-empty paths.'
                );
            }
        }
    }

    /** A path spec is a non-empty string, or a non-empty list of non-empty strings. */
    protected function isValidPathSpec(mixed $spec): bool
    {
        if (is_string($spec)) {
            return trim($spec) !== '';
        }

        if (is_array($spec) && $spec !== []) {
            foreach ($spec as $path) {
                if (! is_string($path) || trim($path) === '') {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
