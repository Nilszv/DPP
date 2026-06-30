<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram GIN indexes so the admin search (leading-wildcard ILIKE) stays fast as volume
 * grows. Covers org/company name + emails, product names, and passport identifiers. The
 * predicates in the admin controllers are written to match these (incl. ::text casts on
 * the uuid/citext columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $indexes = [
            'org_name_trgm' => 'organizations USING gin (name gin_trgm_ops)',
            'org_legal_name_trgm' => 'organizations USING gin (legal_name gin_trgm_ops)',
            'org_contact_email_trgm' => 'organizations USING gin (contact_email gin_trgm_ops)',
            'product_name_trgm' => 'products USING gin (name gin_trgm_ops)',
            'passport_gtin_trgm' => 'passports USING gin (gtin gin_trgm_ops)',
            'passport_serial_trgm' => 'passports USING gin (serial gin_trgm_ops)',
            'passport_public_id_trgm' => 'passports USING gin ((public_id::text) gin_trgm_ops)',
            'user_email_trgm' => 'users USING gin ((email::text) gin_trgm_ops)',
        ];

        foreach ($indexes as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach ([
            'org_name_trgm', 'org_legal_name_trgm', 'org_contact_email_trgm',
            'product_name_trgm', 'passport_gtin_trgm', 'passport_serial_trgm',
            'passport_public_id_trgm', 'user_email_trgm',
        ] as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
