<?php

namespace Tests\Feature;

use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
    }

    public function test_a_new_org_is_redirected_to_onboarding(): void
    {
        [$user] = $this->makeUserOrg();

        $this->actingAs($user)->get('/app')->assertRedirect(route('onboarding.show'));
        $this->actingAs($user)->get(route('passports.index'))->assertRedirect(route('onboarding.show'));
    }

    public function test_onboarding_requires_accepting_the_policies(): void
    {
        [$user, $org] = $this->makeUserOrg();

        $payload = $this->validPayload();
        unset($payload['accept']);

        $this->actingAs($user)
            ->post(route('onboarding.store'), $payload)
            ->assertSessionHasErrors('accept.registration_policy');

        $this->assertFalse($org->fresh()->isOnboarded());
    }

    public function test_invalid_country_is_rejected(): void
    {
        [$user] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload(['country' => 'ZZ']))
            ->assertSessionHasErrors('country');
    }

    public function test_completing_onboarding_saves_profile_and_records_acceptance(): void
    {
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $org->refresh();
        $this->assertTrue($org->isOnboarded());
        $this->assertSame('Acme Ltd', $org->legal_name);
        $this->assertSame('LV', $org->country);
        $this->assertSame(21.0, $org->taxRate());

        $this->assertSame(1, LegalAcceptance::where('organization_id', $org->id)
            ->where('document_key', 'registration_policy')->count());

        // After onboarding the app is reachable.
        $this->actingAs($user)->get('/app')->assertOk();
    }

    private function makeUserOrg(bool $onboarded = false, string $role = 'owner'): array
    {
        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => 'free', 'status' => 'active',
            'onboarding_completed_at' => $onboarded ? now() : null,
        ]);
        $user = User::create([
            'name' => 'U', 'email' => Str::lower(Str::random(6)).'@example.com', 'email_verified_at' => now(),
        ]);
        $org->members()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$user, $org];
    }

    private function validPayload(array $extra = []): array
    {
        return array_merge([
            'legal_name' => 'Acme Ltd',
            'address_line1' => '1 Main St',
            'city' => 'Riga',
            'postal_code' => 'LV-1001',
            'country' => 'LV',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@acme.test',
            'accept' => ['registration_policy' => '1'],
        ], $extra);
    }
}
