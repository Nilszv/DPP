<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A product-category template. field_schema drives the wizard + validation;
 * access_map decides which audience tier sees which field in the public viewer.
 */
class Template extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'key', 'name', 'category', 'field_schema', 'access_map', 'active',
    ];

    protected $casts = [
        'field_schema' => 'array',
        'access_map' => 'array',
        'active' => 'boolean',
    ];

    /** Field keys that must be filled before a passport can be published. */
    public function requiredFieldKeys(): array
    {
        return collect($this->field_schema)
            ->filter(fn ($f) => ($f['required'] ?? false) === true)
            ->pluck('key')
            ->all();
    }

    /**
     * A field's display label for a public-layer locale. Fields carry an optional per-locale
     * 'labels' map next to the plain 'label', which stays the manufacturer-facing form label
     * and the fallback for locales/templates without a translation.
     */
    public static function fieldLabel(array $field, string $locale): string
    {
        return $field['labels'][$locale] ?? $field['label'];
    }
}
