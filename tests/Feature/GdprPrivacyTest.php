<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\Passport;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GdprPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_page_requires_auth_but_not_org_state(): void
    {
        $this->get(route('privacy.show'))->assertRedirect(route('login'));

        // A suspended user (normally walled off to /app/support) keeps their data rights.
        $user = $this->userInOrg();
        $user->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($user)->get(route('privacy.show'))->assertOk()->assertSee('Export your data');
        $this->get(route('privacy.export'))->assertOk();
    }

    public function test_export_contains_the_users_data_and_is_audited(): void
    {
        $user = $this->userInOrg('Acme SIA', role: 'editor');
        LegalAcceptance::create([
            'organization_id' => $user->organizations->first()->id,
            'user_id' => $user->id,
            'document_key' => 'registration_policy', 'document_version' => 3,
        ]);
        DB::table('invitations')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $user->organizations->first()->id,
            'email' => 'colleague@example.com', 'role' => 'viewer', 'token' => Str::random(40),
            'invited_by' => $user->id, 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::record('passport.correction.published', 'p-1', [], $user->id);

        $response = $this->actingAs($user)->get(route('privacy.export'))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="dpp-personal-data-export.json"');

        $json = $response->json();
        $this->assertSame($user->email, $json['user']['email']);
        $this->assertSame('Acme SIA', $json['organizations'][0]['name']);
        $this->assertSame('editor', $json['organizations'][0]['role']);
        $this->assertSame('registration_policy', $json['legal_acceptances'][0]['document_key']);
        $this->assertSame('colleague@example.com', $json['invitations_sent'][0]['email']);
        $this->assertSame('passport.correction.published', $json['audit_entries_as_actor'][0]['action']);

        $this->assertSame(1, AuditLog::where('action', 'gdpr.export')->where('actor_id', $user->id)->count());
    }

    public function test_export_includes_entries_about_the_user_without_exposing_third_parties(): void
    {
        $user = $this->userInOrg();
        $admin = User::create(['name' => 'Admin', 'email' => 'the.admin@example.com', 'email_verified_at' => now()]);

        // The user as SUBJECT: an admin impersonated them (actor = admin, target = user).
        AuditLog::record('impersonation.started', $user->id,
            ['target_email' => $user->email, 'admin_email' => $admin->email], $admin->id);
        // Matched via meta email only (target holds something else).
        AuditLog::record('user.unsuspended', 'case-42', ['target_email' => $user->email], $admin->id);
        // Someone ELSE's row: must not leak into this user's export.
        AuditLog::record('impersonation.started', $admin->id, ['target_email' => 'someone.else@example.com'], $admin->id);

        $json = $this->actingAs($user)->get(route('privacy.export'))->json();

        $about = collect($json['audit_entries_about_you']);
        $this->assertSame(['impersonation.started', 'user.unsuspended'], $about->pluck('action')->sort()->values()->all());
        // Only action + ts are disclosed: no meta, no actor -- the acting admin's identity
        // is third-party data (Art. 15(4) balance).
        $this->assertStringNotContainsString('the.admin@example.com', json_encode($json['audit_entries_about_you']));
        $this->assertStringNotContainsString('someone.else@example.com', json_encode($json));
    }

    public function test_self_erasure_deletes_the_account_and_scrubs_personal_data_everywhere(): void
    {
        $user = $this->userInOrg('Solo SIA'); // sole member, nothing published
        $email = $user->email;

        DB::table('login_codes')->insert([
            'id' => (string) Str::uuid(), 'email' => $email, 'code_hash' => 'x',
            'expires_at' => now()->addMinutes(10), 'created_at' => now(),
        ]);
        DB::table('invitations')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $user->organizations->first()->id,
            'email' => $email, 'role' => 'viewer', 'token' => Str::random(40),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The email denormalized into audit meta (as impersonation rows do).
        AuditLog::record('impersonation.started', $user->id, ['target_email' => $email, 'admin_email' => 'admin@example.com']);
        // And hidden in nested/array/substring shapes a future action might write.
        AuditLog::record('nested.shape', $user->id, [
            'details' => ['emails' => [$email], 'other' => 'kept'],
            'note' => "sent to {$email} yesterday",
        ]);

        $this->actingAs($user)
            ->delete(route('privacy.erase'), ['confirm_email' => strtoupper(" {$email} ")])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(User::where('email', $email)->first());
        $this->assertSame(0, Organization::where('name', 'Solo SIA')->count());
        $this->assertSame(0, DB::table('login_codes')->where('email', $email)->count());
        $this->assertSame(0, DB::table('invitations')->where('email', $email)->count());

        // The audit EVENT survives; the identifier inside it does not. Untouched values stay.
        $scrubbed = AuditLog::where('action', 'impersonation.started')->first();
        $this->assertSame('[erased]', $scrubbed->meta['target_email']);
        $this->assertSame('admin@example.com', $scrubbed->meta['admin_email']);

        // Scrub reaches ANY meta shape (review P2): nested objects, arrays, and the email
        // embedded inside a longer string -- not just top-level exact values.
        $nested = AuditLog::where('action', 'nested.shape')->first();
        $this->assertStringNotContainsString($email, json_encode($nested->meta));
        $this->assertSame('[erased]', $nested->meta['details']['emails'][0]);
        $this->assertSame('sent to [erased] yesterday', $nested->meta['note']);
        $this->assertSame('kept', $nested->meta['details']['other']);

        $erasure = AuditLog::where('action', 'gdpr.erasure')->first();
        $this->assertNotNull($erasure);
        $this->assertSame('self', $erasure->meta['initiated_by']);
        $this->assertStringNotContainsString($email, json_encode($erasure->meta));
    }

    public function test_erasure_keeps_acceptance_evidence_where_the_org_survives(): void
    {
        $user = $this->userInOrg('Shared SIA');
        $org = $user->organizations->first();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'email_verified_at' => now()]);
        $org->members()->attach($other->id, ['role' => 'owner']); // two owners: no sole-owner blocker

        LegalAcceptance::create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'document_key' => 'registration_policy', 'document_version' => 1,
        ]);

        $this->actingAs($user)->delete(route('privacy.erase'), ['confirm_email' => $user->email]);

        $this->assertNull(User::find($user->id));
        // Org lives on -> its acceptance evidence lives on (10-year duty), account detached.
        $this->assertSame(1, LegalAcceptance::where('organization_id', $org->id)->count());
    }

    public function test_erasure_requires_exact_email_confirmation(): void
    {
        $user = $this->userInOrg();

        $this->actingAs($user)
            ->from(route('privacy.show'))
            ->delete(route('privacy.erase'), ['confirm_email' => 'wrong@example.com'])
            ->assertSessionHasErrors('confirm_email');

        $this->assertNotNull(User::find($user->id));
    }

    public function test_erasure_is_blocked_for_sole_owner_with_members_and_for_published_orgs(): void
    {
        // Sole owner of an org with other members.
        $owner = $this->userInOrg('Team SIA');
        $member = User::create(['name' => 'M', 'email' => 'm@example.com', 'email_verified_at' => now()]);
        $owner->organizations->first()->members()->attach($member->id, ['role' => 'viewer']);

        $this->actingAs($owner)->delete(route('privacy.erase'), ['confirm_email' => $owner->email])
            ->assertSessionHas('error');
        $this->assertNotNull(User::find($owner->id));

        // Sole member of an org with a published passport: manual/support path only.
        $publisher = $this->userInOrg('Published SIA');
        $this->publishPassportFor($publisher->organizations->first());

        $this->actingAs($publisher)->delete(route('privacy.erase'), ['confirm_email' => $publisher->email])
            ->assertSessionHas('error');
        $this->assertNotNull(User::find($publisher->id));
        $this->actingAs($publisher)->get(route('privacy.show'))->assertSee('contact support');
    }

    public function test_an_impersonation_session_cannot_erase_the_account(): void
    {
        $user = $this->userInOrg();

        $this->actingAs($user)
            ->withSession(['impersonate.original_admin_id' => (string) Str::uuid()])
            ->delete(route('privacy.erase'), ['confirm_email' => $user->email])
            ->assertSessionHas('error');

        $this->assertNotNull(User::find($user->id));
    }

    public function test_admin_delete_user_now_scrubs_personal_data_too(): void
    {
        $user = $this->userInOrg('Erased SIA');
        $email = $user->email;
        DB::table('login_codes')->insert([
            'id' => (string) Str::uuid(), 'email' => $email, 'code_hash' => 'x',
            'expires_at' => now()->addMinutes(10), 'created_at' => now(),
        ]);
        AuditLog::record('impersonation.started', $user->id, ['target_email' => $email]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'email_verified_at' => now()]);
        $this->actingAsAdmin($admin)
            ->delete(route('admin.users.delete', $user))
            ->assertRedirect(route('admin.organizations'));

        // The success flash must not re-collect the just-erased email into the admin's
        // session row (review P2).
        $this->assertStringNotContainsString($email, (string) session('status'));

        $this->assertNull(User::where('email', $email)->first());
        $this->assertSame(0, DB::table('login_codes')->where('email', $email)->count());
        $this->assertSame('[erased]', AuditLog::where('action', 'impersonation.started')->first()->meta['target_email']);

        $erasure = AuditLog::where('action', 'gdpr.erasure')->first();
        $this->assertSame('admin', $erasure->meta['initiated_by']);
        $this->assertSame($admin->id, $erasure->actor_id);
    }

    private function userInOrg(string $orgName = 'Org', string $role = 'owner'): User
    {
        $org = Organization::create([
            'name' => $orgName.' '.Str::random(4),
            'slug' => Str::slug($orgName).'-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
        // Tests that assert on the org name look it up by prefix; store the exact name.
        $org->update(['name' => $orgName]);

        $user = User::create([
            'name' => 'User', 'email' => Str::lower(Str::random(8)).'@example.com',
            'email_verified_at' => now(),
        ]);
        $org->members()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return $user->fresh();
    }

    private function publishPassportFor(Organization $org): void
    {
        $this->seed(TemplateSeeder::class); // idempotent updateOrCreate
        $template = Template::where('key', 'generic')->first();

        $product = Product::create([
            'organization_id' => $org->id, 'template_id' => $template->id,
            'name' => 'P', 'category' => 'generic',
        ]);
        $passport = Passport::create([
            'organization_id' => $org->id, 'product_id' => $product->id,
            'public_id' => (string) Str::uuid(), 'identifier_scheme' => 'self',
            'status' => 'published', 'default_locale' => 'lv', 'published_at' => now(),
        ]);
        $passport->versions()->create([
            'version_no' => 1, 'data' => ['product_name' => 'P', 'manufacturer' => 'A'],
            'content_hash' => 'x', 'locked' => true,
        ]);
    }
}
