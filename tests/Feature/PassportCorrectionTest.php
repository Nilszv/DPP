<?php

namespace Tests\Feature;

use App\Exceptions\PublishException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Passport;
use App\Models\PassportVersion;
use App\Models\Product;
use App\Models\Template;
use App\Models\User;
use App\Services\PassportPublisher;
use Database\Seeders\TemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post-publish corrections: a published passport's data is locked, but an editor can open a
 * correction draft (new unlocked version), edit it while the public page keeps serving the
 * live version, and publish it through the same regulated gate to swap what the public sees.
 */
class PassportCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TemplateSeeder::class);

        $this->org = Organization::create([
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::lower(Str::random(8)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'email_verified_at' => now()]);
        $this->org->members()->attach($this->user->id, ['role' => 'owner']);
        $this->user->forceFill(['current_organization_id' => $this->org->id])->save();
    }

    public function test_start_correction_copies_the_live_version_and_public_page_is_unchanged(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)
            ->post(route('passports.corrections.start', $passport))
            ->assertRedirect(route('passports.edit', $passport));

        $passport->refresh();
        $correction = $passport->openCorrection();
        $this->assertNotNull($correction);
        $this->assertSame(2, $correction->version_no);
        $this->assertFalse($correction->locked);
        $this->assertSame('Acme', $correction->data['manufacturer']);
        // The live pointer and the public page still serve v1.
        $this->assertSame(1, $passport->currentVersion->version_no);
        $this->get('/p/'.$passport->public_id)->assertOk()->assertSee('Acme');
    }

    public function test_published_passport_without_a_correction_cannot_be_edited(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)
            ->get(route('passports.edit', $passport))
            ->assertRedirect(route('passports.show', $passport));
        $this->assertNotNull(session('error'));

        $this->put(route('passports.update', $passport), ['fields' => ['manufacturer' => 'Hacked']])
            ->assertRedirect(route('passports.show', $passport));
        $this->assertSame('Acme', $passport->fresh()->currentVersion->data['manufacturer']);
    }

    public function test_publishing_a_correction_swaps_the_public_data_and_audits_the_swap(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);
        $originalPublishedAt = $passport->published_at;
        $originalTokens = $passport->accessTokens()->pluck('token', 'audience');
        $v1Hash = $passport->currentVersion->content_hash;

        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme Industries GmbH'],
        ]);

        // Snapshots still serve v1 until the correction is published.
        $this->get('/p/'.$passport->public_id)->assertSee('Acme')->assertDontSee('Acme Industries GmbH');

        $this->post(route('passports.corrections.publish', $passport))
            ->assertRedirect(route('passports.show', $passport))
            ->assertSessionHas('status');

        $passport->refresh();
        $this->assertNull($passport->openCorrection());
        $this->assertSame(2, $passport->currentVersion->version_no);
        $this->assertTrue($passport->currentVersion->locked);
        $this->assertSame(64, strlen($passport->currentVersion->content_hash));

        // The public identity does not rotate: same URL, same tier tokens, same market dates.
        $this->assertTrue($originalPublishedAt->equalTo($passport->published_at));
        $this->assertEquals($originalTokens, $passport->accessTokens()->pluck('token', 'audience'));
        $this->get('/p/'.$passport->public_id)->assertOk()->assertSee('Acme Industries GmbH');

        // Every audience snapshot was rebuilt from v2.
        foreach (config('dpp.audiences') as $audience) {
            $snapshot = $passport->snapshots()->where('audience', $audience)->first();
            $this->assertSame(
                $passport->currentVersion->content_hash,
                $snapshot->rendered['content_hash'],
                "Snapshot for {$audience} still serves the old version."
            );
        }

        $audit = AuditLog::where('action', 'passport.correction.published')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->user->id, $audit->actor_id);
        $this->assertSame($passport->id, $audit->target);
        $this->assertSame(1, $audit->meta['from_version_no']);
        $this->assertSame(2, $audit->meta['to_version_no']);
        $this->assertSame($v1Hash, $audit->meta['from_content_hash']);
        $this->assertSame($passport->currentVersion->content_hash, $audit->meta['to_content_hash']);
    }

    public function test_correction_missing_a_required_field_cannot_be_published(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Cotton Tee', 'manufacturer' => ''],
        ]);

        $this->post(route('passports.corrections.publish', $passport))->assertSessionHas('error');

        $passport->refresh();
        $this->assertSame(1, $passport->currentVersion->version_no);
        $this->assertNotNull($passport->openCorrection());
        $this->get('/p/'.$passport->public_id)->assertSee('Acme');
    }

    public function test_discarding_a_correction_keeps_the_published_version(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Wrong Change'],
        ]);

        $this->delete(route('passports.corrections.discard', $passport))
            ->assertRedirect(route('passports.show', $passport));

        $passport->refresh();
        $this->assertNull($passport->openCorrection());
        $this->assertSame(1, $passport->versions()->count());
        $this->assertSame('Acme', $passport->currentVersion->data['manufacturer']);
        $this->get('/p/'.$passport->public_id)->assertSee('Acme');
    }

    public function test_starting_twice_does_not_create_a_second_draft(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));
        $this->post(route('passports.corrections.start', $passport))
            ->assertRedirect(route('passports.edit', $passport));

        $this->assertSame(2, $passport->versions()->count());
    }

    public function test_corrections_do_not_exist_for_draft_passports(): void
    {
        $passport = $this->draftPassport($this->org, ['product_name' => 'Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)
            ->post(route('passports.corrections.start', $passport))
            ->assertNotFound();
        $this->post(route('passports.corrections.publish', $passport))->assertSessionHas('error');
        $this->delete(route('passports.corrections.discard', $passport))->assertNotFound();
    }

    public function test_publishing_with_no_open_correction_errors_cleanly(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->actingAs($this->user)
            ->post(route('passports.corrections.publish', $passport))
            ->assertSessionHas('error');
        $this->assertSame(1, $passport->fresh()->currentVersion->version_no);
    }

    public function test_a_viewer_cannot_start_or_publish_corrections(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'email_verified_at' => now()]);
        $this->org->members()->attach($viewer->id, ['role' => 'viewer']);
        $viewer->forceFill(['current_organization_id' => $this->org->id])->save();

        $this->actingAs($viewer)->post(route('passports.corrections.start', $passport))->assertForbidden();
        $this->post(route('passports.corrections.publish', $passport))->assertForbidden();
        $this->delete(route('passports.corrections.discard', $passport))->assertForbidden();
    }

    public function test_an_org_at_its_published_quota_can_still_publish_a_correction(): void
    {
        // Free plan: quota is 1 published passport, and this org is already at it.
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);
        $this->assertSame(1, $this->org->passports()->where('status', 'published')->count());

        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Corrected Acme'],
        ]);
        $this->post(route('passports.corrections.publish', $passport))->assertSessionHas('status');

        $this->assertSame('Corrected Acme', $passport->fresh()->currentVersion->data['manufacturer']);
    }

    public function test_a_suspended_org_cannot_publish_a_correction(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);
        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));

        $this->org->update(['status' => 'suspended']);

        // The org.active middleware already walls off /app for suspended orgs; the publisher
        // guard is the server-side backstop this asserts (bypassing the wall must not work).
        try {
            app(PassportPublisher::class)->publishCorrection($passport->fresh());
            $this->fail('Expected PublishException for a suspended organization.');
        } catch (PublishException $e) {
            $this->assertStringContainsString('suspended', $e->getMessage());
        }

        $this->assertSame(1, $passport->fresh()->currentVersion->version_no);
    }

    public function test_discard_after_the_correction_was_published_is_refused(): void
    {
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);
        $this->actingAs($this->user)->post(route('passports.corrections.start', $passport));
        $this->put(route('passports.update', $passport), [
            'fields' => ['product_name' => 'Cotton Tee', 'manufacturer' => 'Corrected'],
        ]);
        $this->post(route('passports.corrections.publish', $passport));

        // The draft this discard targets is now the LIVE version; deleting it must not happen.
        $this->delete(route('passports.corrections.discard', $passport))->assertNotFound();

        $passport->refresh();
        $this->assertSame(2, $passport->versions()->count());
        $this->assertSame('Corrected', $passport->currentVersion->data['manufacturer']);
    }

    public function test_the_live_version_cannot_be_deleted_at_the_database_level(): void
    {
        // Backstop for the publish/discard race: even if every application-level guard is
        // bypassed, the FK on current_version_id refuses to orphan a published passport.
        $passport = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);

        $this->expectException(QueryException::class);
        $passport->currentVersion->delete();
    }

    public function test_deleting_a_whole_passport_still_cascades_its_versions(): void
    {
        // The restrict FK must not break head-row deletion (e.g. the admin delete-user tool
        // removing a sole-member org's draft passports): the passport row goes first, so the
        // passport_id cascade reaches versions that nothing references anymore.
        $published = $this->publishedPassport(['product_name' => 'Cotton Tee', 'manufacturer' => 'Acme']);
        $draft = $this->draftPassport($this->org, ['product_name' => 'Draft', 'manufacturer' => 'Acme']);

        $draft->delete();
        $published->delete();

        $this->assertSame(0, Passport::count());
        $this->assertSame(0, PassportVersion::count());
    }

    private function publishedPassport(array $data): Passport
    {
        $passport = $this->draftPassport($this->org, $data);
        $this->actingAs($this->user)->post(route('passports.publish', $passport));
        $passport->refresh();
        $this->assertTrue($passport->isPublished());

        return $passport;
    }

    private function draftPassport(Organization $org, array $data): Passport
    {
        $template = Template::where('key', 'generic')->first();

        $product = Product::create([
            'organization_id' => $org->id,
            'template_id' => $template->id,
            'name' => $data['product_name'] ?? 'Product',
            'category' => 'generic',
        ]);

        $passport = Passport::create([
            'organization_id' => $org->id,
            'product_id' => $product->id,
            'public_id' => (string) Str::uuid(),
            'identifier_scheme' => 'self',
            'status' => 'draft',
            'default_locale' => 'lv',
        ]);

        $passport->versions()->create([
            'version_no' => 1,
            'data' => $data,
            'content_hash' => 'pending',
            'locked' => false,
        ]);

        return $passport;
    }
}
