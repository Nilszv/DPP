<?php

namespace App\Services;

use App\Models\Passport;
use App\Models\PassportVersion;
use App\Models\PublishedSnapshot;
use App\Models\Template;
use App\Support\CanonicalJson;

/**
 * Builds the read-side delivery rows (published_snapshots) for a passport version.
 * Each row is a pre-filtered, per-audience view the resolver serves with a single key
 * lookup -- never a live join. The template access_map controls field visibility per audience.
 */
class SnapshotBuilder
{
    public function build(Passport $passport, PassportVersion $version, Template $template): void
    {
        // Field VALUES are served as the manufacturer entered them (regulated data is never
        // machine-translated); what varies per locale is the field labels. The passport's own
        // default_locale is always built even if the platform locale list changes later.
        $locales = array_values(array_unique([...config('dpp.locales'), $passport->default_locale]));

        foreach ($locales as $locale) {
            foreach (config('dpp.audiences') as $audience) {
                $rendered = $this->render($passport, $version, $template, $audience, $locale);

                PublishedSnapshot::updateOrCreate(
                    ['passport_id' => $passport->id, 'audience' => $audience, 'locale' => $locale],
                    ['rendered' => $rendered, 'etag' => CanonicalJson::hash($rendered)],
                );
            }
        }
    }

    private function render(Passport $passport, PassportVersion $version, Template $template, string $audience, string $locale): array
    {
        $data = $version->data ?? [];
        $accessMap = $template->access_map ?? [];

        $fields = [];
        foreach ($template->field_schema as $field) {
            $key = $field['key'];

            // A field exists on the page iff the BASE value is filled -- a translation of an
            // empty field is meaningless and must not conjure content in one locale only.
            if (($data[$key] ?? '') === '' || $data[$key] === null) {
                continue;
            }

            // 'full' sees everything; other audiences only fields their tier is mapped to.
            $allowed = $audience === 'full' || in_array($audience, $accessMap[$key] ?? [], true);
            if (! $allowed) {
                continue;
            }

            $fields[] = [
                'label' => Template::fieldLabel($field, $locale),
                'value' => $version->valueFor($key, $locale),
            ];
        }

        return [
            'title' => $version->valueFor('product_name', $locale) ?? $passport->product->name,
            'audience' => $audience,
            'locale' => $locale,
            'status' => $passport->status,
            'fields' => $fields,
            'identifier' => [
                'scheme' => $passport->identifier_scheme,
                'public_id' => $passport->public_id,
                'gtin' => $passport->gtin,
                'serial' => $passport->serial,
            ],
            'content_hash' => $version->content_hash,
            'published_at' => optional($passport->published_at)->toIso8601String(),
        ];
    }
}
