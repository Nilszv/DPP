<?php

namespace Tests\Feature;

use App\Models\LegalDocument;
use App\Models\User;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLegalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
    }

    public function test_non_admin_cannot_edit_legal_documents(): void
    {
        $user = User::create(['name' => 'R', 'email' => 'r@example.com', 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('admin.legal.index'))->assertForbidden();
    }

    public function test_editing_the_body_bumps_the_version(): void
    {
        $doc = LegalDocument::where('key', 'registration_policy')->first();
        $this->assertSame(1, $doc->version);

        $this->actingAsAdmin()
            ->put(route('admin.legal.update', $doc), [
                'title' => $doc->title,
                'body' => 'Brand new policy text.',
                'requires_acceptance' => '1',
            ])
            ->assertRedirect(route('admin.legal.index'));

        $this->assertSame(2, $doc->fresh()->version);
        $this->assertSame('Brand new policy text.', $doc->fresh()->body);
    }

    public function test_editing_without_changing_body_keeps_the_version(): void
    {
        $doc = LegalDocument::where('key', 'registration_policy')->first();

        $this->actingAsAdmin()
            ->put(route('admin.legal.update', $doc), [
                'title' => 'Renamed title',
                'body' => $doc->body,
                'requires_acceptance' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(1, $doc->fresh()->version);
        $this->assertSame('Renamed title', $doc->fresh()->title);
    }
}
