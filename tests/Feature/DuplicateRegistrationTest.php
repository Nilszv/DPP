<?php

namespace Tests\Feature;

use App\Mail\DuplicateRegistrationAlert;
use App\Mail\SupportRequestMail;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class DuplicateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
    }

    public function test_duplicate_name_and_country_blocks_onboarding(): void
    {
        $this->existingOrg();
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload())
            ->assertSessionHasErrors('legal_name')
            ->assertSessionHas('duplicate_notice', true);

        $this->assertFalse($org->fresh()->isOnboarded());
        $this->assertSame(1, $user->fresh()->duplicate_onboarding_attempts);
        $this->assertFalse($user->fresh()->isSuspended());
    }

    public function test_duplicate_notice_explains_the_block_and_offers_login(): void
    {
        $this->existingOrg();
        [$user] = $this->makeUserOrg();

        $this->actingAs($user)->post(route('onboarding.store'), $this->validPayload());

        $this->actingAs($user)->get(route('onboarding.show'))
            ->assertSee('We found an existing organization matching these details')
            ->assertSee(route('login'), false)
            ->assertSee(route('support.show'), false);
    }

    public function test_same_name_and_country_blocks_even_with_different_registration_and_vat(): void
    {
        // Same company name + country, but DIFFERENT registration number and VAT number.
        // Registration/VAT are no longer part of the match -- a company name is unique per
        // country regardless of a typo'd or reformatted registration/VAT number.
        $this->existingOrg(['registration_number' => '99999999999', 'vat_id' => 'LV99999999999']);
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload())
            ->assertSessionHasErrors('legal_name');

        $this->assertFalse($org->fresh()->isOnboarded());
    }

    public function test_same_name_in_a_different_country_does_not_block(): void
    {
        // Same company name, but a DIFFERENT country, registration number and VAT -- two
        // unrelated companies with a common name in different countries must both be allowed
        // to register (none of the three independent checks should fire here).
        $this->existingOrg(['country' => 'EE', 'registration_number' => '55555555555', 'vat_id' => 'EE555555555']);
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload(['country' => 'LV']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($org->fresh()->isOnboarded());
    }

    public function test_registration_number_match_within_the_same_country_blocks_despite_different_name(): void
    {
        // Same country + registration number, but a DIFFERENT company name -- the
        // registration-number check is independent of name, but still country-scoped.
        $this->existingOrg(['legal_name' => 'Beta Corp', 'registration_number' => '77777777777', 'vat_id' => 'LV77777777777']);
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload([
                'legal_name' => 'Totally Different Ltd',
                'registration_number' => '77777777777',
            ]))
            ->assertSessionHasErrors('registration_number');

        $this->assertFalse($org->fresh()->isOnboarded());
    }

    public function test_registration_number_match_in_a_different_country_does_not_block(): void
    {
        // Same registration number, but a DIFFERENT country and name -- registration numbers
        // are issued by national registries, so the same digits can coincidentally exist in two
        // countries; that alone must not block a legitimate, unrelated company.
        $this->existingOrg(['legal_name' => 'Delta LLC', 'country' => 'DE', 'registration_number' => '66666666666', 'vat_id' => 'DE66666666666']);
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload([
                'legal_name' => 'Totally Different Ltd',
                'registration_number' => '66666666666',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($org->fresh()->isOnboarded());
    }

    public function test_vat_number_match_alone_blocks_despite_different_name_and_country(): void
    {
        // Different company name, country and registration number, but the SAME VAT number --
        // the VAT check is independent and must fire on its own.
        $this->existingOrg(['legal_name' => 'Gamma Inc', 'country' => 'DE', 'registration_number' => '88888888888', 'vat_id' => 'LV40009999999']);
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload([
                'legal_name' => 'Totally Different Ltd',
                'registration_number' => '11122233344',
                'vat_id' => 'LV40009999999',
            ]))
            ->assertSessionHasErrors('vat_id');

        $this->assertFalse($org->fresh()->isOnboarded());
    }

    public function test_name_matching_is_case_and_whitespace_insensitive(): void
    {
        $this->existingOrg();
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload(['legal_name' => '  acme   LTD  ']))
            ->assertSessionHasErrors('legal_name');

        $this->assertFalse($org->fresh()->isOnboarded());
    }

    public function test_vatless_country_duplicate_is_caught_on_name_and_country(): void
    {
        // Existing US org (no VAT). US onboarding allows a blank VAT, so the guard must still
        // catch the duplicate on company name + country alone.
        $this->existingOrg(['country' => 'US', 'vat_id' => null]);
        [$user, $org] = $this->makeUserOrg();

        $payload = $this->validPayload(['country' => 'US']);
        unset($payload['vat_id']);

        $this->actingAs($user)
            ->post(route('onboarding.store'), $payload)
            ->assertSessionHasErrors('legal_name');

        $this->assertFalse($org->fresh()->isOnboarded());
    }

    public function test_invalid_vat_format_is_rejected_server_side(): void
    {
        [$user] = $this->makeUserOrg();

        // Direct POST bypassing the browser: an LV VAT that is too short must be rejected.
        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload(['vat_id' => 'LV123']))
            ->assertSessionHasErrors('vat_id');
    }

    public function test_completing_onboarding_stores_a_canonical_vat(): void
    {
        [$user, $org] = $this->makeUserOrg();

        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload(['vat_id' => 'lv 4000 3011 283']))
            ->assertRedirect(route('dashboard'));

        $this->assertSame('LV40003011283', $org->fresh()->vat_id);
    }

    public function test_exceeding_the_attempt_threshold_suspends_and_alerts_support(): void
    {
        Mail::fake();
        config(['dpp.onboarding_duplicate_max_attempts' => 3]);
        $this->existingOrg();
        [$user] = $this->makeUserOrg();

        // Attempts 1-3 are blocked with an error; the account is not yet suspended.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)
                ->post(route('onboarding.store'), $this->validPayload())
                ->assertSessionHasErrors('legal_name');
            $this->assertFalse($user->fresh()->isSuspended());
        }
        Mail::assertNothingSent();

        // The 4th attempt (more than 3) suspends the email and alerts support.
        $this->actingAs($user)
            ->post(route('onboarding.store'), $this->validPayload())
            ->assertRedirect(route('support.show'));

        $user->refresh();
        $this->assertTrue($user->isSuspended());
        $this->assertSame(4, $user->duplicate_onboarding_attempts);
        $this->assertNotNull($user->suspension_reason);

        Mail::assertSent(DuplicateRegistrationAlert::class, function ($mail) {
            return $mail->hasTo(config('dpp.support_email'));
        });
    }

    public function test_suspended_user_is_gated_to_the_support_page(): void
    {
        [$user] = $this->makeUserOrg();
        $user->forceFill(['suspended_at' => now(), 'suspension_reason' => 'test'])->save();

        $this->actingAs($user)->get('/app')->assertRedirect(route('support.show'));
        $this->actingAs($user)->get(route('onboarding.show'))->assertRedirect(route('support.show'));
        // The support page itself is reachable while suspended.
        $this->actingAs($user)->get(route('support.show'))->assertOk();
    }

    public function test_support_form_emails_the_support_inbox(): void
    {
        Mail::fake();
        [$user] = $this->makeUserOrg();

        $this->actingAs($user)->post(route('support.send'), [
            'company_name' => 'Acme Ltd',
            'email' => 'jane@acme.test',
            'phone' => '+371 12345678',
            'message' => 'Please help, this is my company.',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(SupportRequestMail::class, fn ($mail) => $mail->hasTo(config('dpp.support_email')));
    }

    public function test_admin_can_lift_a_suspension(): void
    {
        [$user] = $this->makeUserOrg();
        $user->forceFill([
            'suspended_at' => now(), 'suspension_reason' => 'dup', 'duplicate_onboarding_attempts' => 5,
        ])->save();

        $this->actingAsAdmin()
            ->post(route('admin.users.unsuspend', $user))
            ->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->isSuspended());
        $this->assertNull($user->suspension_reason);
        $this->assertSame(0, $user->duplicate_onboarding_attempts);
    }

    private function existingOrg(array $overrides = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Acme Ltd',
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'plan' => 'free',
            'status' => 'active',
            'legal_name' => 'Acme Ltd',
            'registration_number' => '40003011283',
            'vat_id' => 'LV40003011283',
            'country' => 'LV',
            'onboarding_completed_at' => now(),
        ], $overrides));
    }

    private function makeUserOrg(): array
    {
        $org = Organization::create([
            'name' => 'Org', 'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => null,
        ]);
        $user = User::create([
            'name' => 'U', 'email' => Str::lower(Str::random(6)).'@example.com', 'email_verified_at' => now(),
        ]);
        $org->members()->attach($user->id, ['role' => 'owner']);
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
            'registration_number' => '40003011283',
            'vat_id' => 'LV40003011283',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@acme.test',
            'contact_phone' => '+371 12345678',
            'accept' => ['registration_policy' => '1'],
        ], $extra);
    }
}
