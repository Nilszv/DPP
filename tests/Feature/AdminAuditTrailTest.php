<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_open_the_audit_trail(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'plain@example.com', 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_lists_entries_newest_first_with_actor_and_organization(): void
    {
        $actor = $this->actor('admin.actor@example.com');
        $org = $this->org('Acme GmbH');

        AuditLog::record('impersonation.started', 'older-row-target', ['target_email' => 'x@example.com'], $actor->id, $org->id);
        AuditLog::record('passport.correction.published', 'newer-row-target', ['to_version_no' => 2], $actor->id, $org->id);

        $response = $this->actingAsAdmin()->get(route('admin.audit.index'))->assertOk();

        $response->assertSee('impersonation.started')
            ->assertSee('passport.correction.published')
            ->assertSee('admin.actor@example.com')
            ->assertSee('Acme GmbH');

        // Newest first. Positions compared on the target codes: actions also appear in the
        // filter dropdown, so their first occurrence says nothing about table order.
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'older-row-target'),
            strpos($html, 'newer-row-target'),
        );
    }

    public function test_filters_by_action_actor_organization_and_date(): void
    {
        $actorA = $this->actor('alice@example.com');
        $actorB = $this->actor('bob@example.com');
        $orgA = $this->org('Org A');
        $orgB = $this->org('Org B');

        // Assertions anchor on the row TARGETS: action names and org names also render in
        // the filter dropdowns, so they appear on every page regardless of the filter.
        AuditLog::record('impersonation.started', 'row-of-alice', [], $actorA->id, $orgA->id);
        AuditLog::record('impersonation.ended', 'row-of-bob', [], $actorB->id, $orgB->id);

        // action
        $this->actingAsAdmin()->get(route('admin.audit.index', ['action' => 'impersonation.started']))
            ->assertSee('row-of-alice')->assertDontSee('row-of-bob');

        // actor (matches email or name, partial)
        $this->get(route('admin.audit.index', ['actor' => 'alice']))
            ->assertSee('row-of-alice')->assertDontSee('row-of-bob');

        // organization
        $this->get(route('admin.audit.index', ['org' => $orgB->id]))
            ->assertSee('row-of-bob')->assertDontSee('row-of-alice');

        // date range: move one row to yesterday, then filter to today only.
        DB::table('audit_log')->where('action', 'impersonation.started')
            ->update(['ts' => now()->subDay()->startOfDay()]);
        $today = now()->toDateString();
        $this->get(route('admin.audit.index', ['from' => $today, 'to' => $today]))
            ->assertSee('row-of-bob')->assertDontSee('row-of-alice');

        // garbage dates are ignored, not a query error
        $this->get(route('admin.audit.index', ['from' => 'not-a-date', 'to' => "1'; DROP--"]))->assertOk();

        // correctly-shaped but impossible calendar dates too (P2 review finding): these pass
        // a bare format regex and would otherwise reach Postgres / Carbon::parse().
        $this->get(route('admin.audit.index', ['from' => '2026-99-99', 'to' => '2026-02-31']))
            ->assertOk()->assertSee('row-of-bob');
    }

    public function test_entries_are_paginated(): void
    {
        $actor = $this->actor('bulk@example.com');
        for ($i = 0; $i < 55; $i++) {
            AuditLog::record('test.bulk', "row-{$i}", [], $actor->id);
        }

        $response = $this->actingAsAdmin()->get(route('admin.audit.index'))->assertOk();
        $response->assertSee('55 total')->assertSee('Showing 1-50');

        $this->get(route('admin.audit.index', ['page' => 2]))->assertOk()->assertSee('Showing 51-55');
    }

    public function test_a_deleted_actor_does_not_break_the_page(): void
    {
        $actor = $this->actor('gone@example.com');
        AuditLog::record('impersonation.started', 't', [], $actor->id);
        $actor->delete();

        $this->actingAsAdmin()->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('system / deleted user');
    }

    private function actor(string $email): User
    {
        return User::create(['name' => Str::before($email, '@'), 'email' => $email, 'email_verified_at' => now()]);
    }

    private function org(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'plan' => 'free', 'status' => 'active', 'onboarding_completed_at' => now(),
        ]);
    }
}
