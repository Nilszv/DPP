<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Seeds the global "Generic" template. Templates are data, so adding a category later
 * (textiles, electronics, ...) is just another row. Field examples can be expanded once
 * the product owner provides per-category fields.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        Template::updateOrCreate(
            ['key' => 'generic'],
            [
                'organization_id' => null,
                'name' => 'Generic product',
                'category' => 'generic',
                // 'label' is the manufacturer-facing form label (and the public fallback);
                // 'labels' localizes the PUBLIC passport page per buyer Member-State language.
                'field_schema' => [
                    ['key' => 'product_name', 'label' => 'Product name', 'labels' => ['en' => 'Product name', 'lv' => 'Produkta nosaukums'], 'type' => 'text', 'required' => true],
                    ['key' => 'manufacturer', 'label' => 'Manufacturer', 'labels' => ['en' => 'Manufacturer', 'lv' => 'Ražotājs'], 'type' => 'text', 'required' => true],
                    ['key' => 'description', 'label' => 'Description', 'labels' => ['en' => 'Description', 'lv' => 'Apraksts'], 'type' => 'textarea', 'required' => false],
                    ['key' => 'material_composition', 'label' => 'Material composition', 'labels' => ['en' => 'Material composition', 'lv' => 'Materiālu sastāvs'], 'type' => 'text', 'required' => false],
                    ['key' => 'country_of_manufacture', 'label' => 'Country of manufacture', 'labels' => ['en' => 'Country of manufacture', 'lv' => 'Ražošanas valsts'], 'type' => 'text', 'required' => false],
                    ['key' => 'care_instructions', 'label' => 'Care instructions', 'labels' => ['en' => 'Care instructions', 'lv' => 'Kopšanas norādījumi'], 'type' => 'textarea', 'required' => false],
                    ['key' => 'recyclability', 'label' => 'Recyclability / end-of-life', 'labels' => ['en' => 'Recyclability / end-of-life', 'lv' => 'Pārstrādājamība / aprites cikla beigas'], 'type' => 'textarea', 'required' => false],
                ],
                // Which audience tier sees each field in the public viewer. Slice 1 renders
                // only the consumer audience; the rest are wired for later tiered views.
                'access_map' => [
                    'product_name' => ['consumer', 'repairer', 'recycler', 'authority'],
                    'manufacturer' => ['consumer', 'repairer', 'recycler', 'authority'],
                    'description' => ['consumer', 'repairer', 'authority'],
                    'material_composition' => ['consumer', 'repairer', 'recycler', 'authority'],
                    'country_of_manufacture' => ['consumer', 'authority'],
                    'care_instructions' => ['consumer', 'repairer'],
                    'recyclability' => ['consumer', 'recycler', 'authority'],
                ],
                'active' => true,
            ]
        );
    }
}
