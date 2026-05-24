<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_another_organization(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $organization = Organization::query()->create([
            'name' => 'Acme Workspace',
            'slug' => 'acme-workspace',
            'join_code' => Str::upper(Str::random(10)),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($member->id, ['role' => OrganizationRoles::AGENT]);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}")
            ->assertOk();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_join_organization_and_rbac_permissions(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $agent = User::factory()->create();
        $newUser = User::factory()->create();

        $organization = Organization::query()->create([
            'name' => 'Bravo Workspace',
            'slug' => 'bravo-workspace',
            'join_code' => 'JOINCODE01',
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        $this->actingAs($newUser, 'sanctum')
            ->postJson('/api/v1/organizations/join', ['join_code' => 'JOINCODE01'])
            ->assertOk()
            ->assertJsonPath('data.role', OrganizationRoles::AGENT);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $newUser->id,
            'role' => OrganizationRoles::AGENT,
        ]);

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/members")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/members")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/members/{$agent->id}", [
                'role' => OrganizationRoles::ADMIN,
            ])
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/members/{$agent->id}", [
                'role' => OrganizationRoles::ADMIN,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', OrganizationRoles::ADMIN);
    }
}
