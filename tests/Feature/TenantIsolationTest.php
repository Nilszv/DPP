<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        // Own key: the real 'generic' template now always exists (guaranteed by the
        // multi-locale backfill migration, so fresh databases already contain it).
        $this->template = Template::create([
            'key' => 'tenant-isolation-test',
            'name' => 'Generic',
            'category' => 'generic',
            'field_schema' => [['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]],
            'access_map' => ['name' => ['consumer']],
        ]);
    }

    public function test_organization_scope_hides_other_tenants_rows(): void
    {
        $orgA = $this->makeOrg('A');
        $orgB = $this->makeOrg('B');

        $this->makeProduct($orgA, 'A product');
        $this->makeProduct($orgB, 'B product');

        // Bound to A: only A's product is visible.
        app()->instance('currentOrganizationId', $orgA->id);
        $this->assertSame(1, Product::count());
        $this->assertSame('A product', Product::first()->name);

        // Switch to B: only B's product is visible.
        app()->instance('currentOrganizationId', $orgB->id);
        $this->assertSame(1, Product::count());
        $this->assertSame('B product', Product::first()->name);

        app()->forgetInstance('currentOrganizationId');
    }

    public function test_scoped_model_cannot_fetch_another_tenants_record_by_id(): void
    {
        $orgA = $this->makeOrg('A');
        $orgB = $this->makeOrg('B');
        $bProduct = $this->makeProduct($orgB, 'B secret');

        // Acting as tenant A, B's product must be invisible even by direct id lookup.
        app()->instance('currentOrganizationId', $orgA->id);
        $this->assertNull(Product::find($bProduct->id));

        app()->forgetInstance('currentOrganizationId');
    }

    public function test_middleware_rejects_stale_current_org_user_is_not_member_of(): void
    {
        $orgA = $this->makeOrg('A');
        $orgB = $this->makeOrg('B');

        // User belongs to A, but their current_organization_id points at B (revoked/tampered).
        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'email_verified_at' => now()]);
        $orgA->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $orgB->id])->save();

        // Hitting an org-context route must not grant B; it falls back to A and repairs the column.
        $this->actingAs($user)->get('/app')->assertOk();

        $user->refresh();
        $this->assertSame($orgA->id, $user->current_organization_id);
    }

    public function test_route_binding_rejects_a_revoked_membership(): void
    {
        $org = $this->makeOrg('A');
        $product = $this->makeProduct($org, 'P');
        $passport = Passport::create([
            'organization_id' => $org->id,
            'product_id' => $product->id,
            'public_id' => (string) Str::uuid(),
            'identifier_scheme' => 'self',
            'status' => 'draft',
            'default_locale' => 'lv',
        ]);

        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'email_verified_at' => now()]);
        $org->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        // Revoke membership but leave the stale current_organization_id behind.
        $org->members()->detach($user->id);

        // Simulate route binding running BEFORE the org-context middleware (nothing bound).
        $this->actingAs($user);
        app()->forgetInstance('currentOrganizationId');

        $this->assertNull((new Passport)->resolveRouteBinding($passport->id));
    }

    private function makeOrg(string $label): Organization
    {
        return Organization::create([
            'name' => "Org {$label}",
            'slug' => 'org-'.Str::lower($label).'-'.Str::lower(Str::random(6)),
            'plan' => 'free',
            'status' => 'active',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function makeProduct(Organization $org, string $name): Product
    {
        return Product::create([
            'organization_id' => $org->id,
            'template_id' => $this->template->id,
            'name' => $name,
            'category' => 'generic',
        ]);
    }
}
