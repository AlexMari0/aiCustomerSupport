<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Support\OrganizationRoles;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_retrieval_is_restricted_by_roles(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        // 1. Agent tries to access audit logs -> Forbidden
        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/audit-logs")
            ->assertForbidden();

        // 2. Admin accesses audit logs -> OK
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/audit-logs")
            ->assertOk();

        // 3. Owner accesses audit logs -> OK
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/audit-logs")
            ->assertOk();
    }

    public function test_audit_logs_enforce_strict_tenant_isolation(): void
    {
        [$orgA, $ownerA] = $this->makeOrganizationWithTeam();
        [$orgB, $ownerB] = $this->makeOrganizationWithTeam();

        // Owner A tries to query Org B audit logs -> Forbidden
        $this->actingAs($ownerA, 'sanctum')
            ->getJson("/api/v1/organizations/{$orgB->id}/audit-logs")
            ->assertForbidden();
    }

    public function test_ticket_creation_generates_audit_log(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'John Client',
            'email' => 'john@client.com',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets", [
                'customer_id' => $customer->id,
                'subject' => 'Help with account checkout',
                'message' => 'I cannot verify my billing address.',
                'priority' => TicketPriorities::HIGH,
                'category' => 'billing',
                'source_channel' => 'web',
            ]);

        $response->assertCreated();

        $ticketId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'event' => 'ticket_created',
            'target_type' => 'Ticket',
            'target_id' => $ticketId,
        ]);

        $log = AuditLog::query()->where('event', 'ticket_created')->first();
        $this->assertNotNull($log);
        $this->assertSame('Help with account checkout', $log->metadata['subject']);
        $this->assertSame(TicketPriorities::HIGH, $log->metadata['priority']);
    }

    public function test_ticket_status_change_generates_audit_log(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'John Client',
            'email' => 'john@client.com',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Existing Issue',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/status", [
                'status' => TicketStatuses::RESOLVED,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'event' => 'status_changed',
            'target_type' => 'Ticket',
            'target_id' => $ticket->id,
        ]);

        $log = AuditLog::query()->where('event', 'status_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame(TicketStatuses::OPEN, $log->metadata['previous_status']);
        $this->assertSame(TicketStatuses::RESOLVED, $log->metadata['new_status']);
    }

    public function test_ticket_agent_assignment_generates_audit_log(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'John Client',
            'email' => 'john@client.com',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Need assignment',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/assign", [
                'assignee_id' => $agent->id,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'event' => 'assigned_agent_changed',
            'target_type' => 'Ticket',
            'target_id' => $ticket->id,
        ]);

        $log = AuditLog::query()->where('event', 'assigned_agent_changed')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->metadata['previous_assignee_id']);
        $this->assertSame($agent->id, $log->metadata['new_assignee_id']);
        $this->assertSame($agent->name, $log->metadata['new_assignee_name']);
    }

    /**
     * @return array{Organization, User, User, User}
     */
    private function makeOrganizationWithTeam(): array
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $agent = User::factory()->create();

        $organization = Organization::query()->create([
            'name' => 'Audit Workspace ' . Str::random(5),
            'slug' => 'audit-workspace-' . Str::random(5),
            'join_code' => Str::upper(Str::random(10)),
            'webhook_token' => Str::random(32),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
