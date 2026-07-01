<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPassportBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlanSeeder::class, TemplateSeeder::class]);
    }

    public function test_non_admin_is_blocked(): void
    {
        $user = User::create(['name' => 'R', 'email' => 'r@example.com', 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('admin.passports.index'))->assertForbidden();
    }

    public function test_admin_sees_passports_across_all_orgs(): void
    {
        $a = $this->org();
        $b = $this->org();
        $this->makePassport($a, 'AlphaProduct');
        $this->makePassport($b, 'BetaProduct');

        $this->actingAsAdmin()
            ->get(route('admin.passports.index'))
            ->assertOk()
            ->assertSee('AlphaProduct')
            ->assertSee('BetaProduct');
    }

    public function test_filter_by_organization(): void
    {
        $a = $this->org();
        $b = $this->org();
        $this->makePassport($a, 'AlphaProduct');
        $this->makePassport($b, 'BetaProduct');

        $this->actingAsAdmin()
            ->get(route('admin.passports.index', ['org' => $a->id]))
            ->assertSee('AlphaProduct')
            ->assertDontSee('BetaProduct');
    }

    public function test_search_by_public_id(): void
    {
        $a = $this->org();
        $alpha = $this->makePassport($a, 'AlphaProduct');
        $this->makePassport($a, 'BetaProduct');

        $needle = substr($alpha->public_id, 0, 8);

        $this->actingAsAdmin()
            ->get(route('admin.passports.index', ['q' => $needle]))
            ->assertSee('AlphaProduct')
            ->assertDontSee('BetaProduct');
    }

    public function test_results_are_paginated(): void
    {
        $org = $this->org();
        for ($i = 0; $i < 21; $i++) {
            $this->makePassport($org, "P{$i}");
        }

        $this->actingAsAdmin();

        $this->get(route('admin.passports.index'))
            ->assertSee('21 total')
            ->assertSee('Showing 1-20');

        $this->get(route('admin.passports.index', ['page' => 2]))
            ->assertSee('Showing 21-21');
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => 'free', 'status' => 'active',
        ]);
    }

    private function makePassport(Organization $org, string $name): Passport
    {
        $product = Product::create([
            'organization_id' => $org->id,
            'template_id' => Template::where('key', 'generic')->value('id'),
            'name' => $name, 'category' => 'generic',
        ]);

        return Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'draft', 'default_locale' => 'lv',
        ]);
    }
}
