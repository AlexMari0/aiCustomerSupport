<?php

namespace Tests\Feature\Tickets;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\OrganizationRoles;
use App\Support\TicketMessageSenderTypes;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_assign_and_resolve_ticket_through_agent_flow(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $createResponse = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets", [
                'customer_name' => 'Maya Customer',
                'customer_email' => 'maya@example.com',
                'subject' => 'Where is my order?',
                'priority' => TicketPriorities::HIGH,
                'category' => 'shipping',
                'message' => 'Can you help check order #1001 status?',
            ]);

        $createResponse->assertCreated()->assertJsonPath('success', true);
        $ticketId = (int) $createResponse->json('data.id');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'organization_id' => $organization->id,
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::HIGH,
            'category' => 'shipping',
        ]);

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticketId,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticketId}/assign", [
                'assignee_id' => $agent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $agent->id);

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/tickets/{$ticketId}")
            ->assertOk()
            ->assertJsonPath('data.id', $ticketId);

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets/{$ticketId}/messages", [
                'sender_type' => TicketMessageSenderTypes::AGENT,
                'body' => 'Thanks for reaching out. I checked your order and it ships today.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', TicketMessageSenderTypes::AGENT);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticketId}/status", [
                'status' => TicketStatuses::RESOLVED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatuses::RESOLVED);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'status' => TicketStatuses::RESOLVED,
            'assigned_to' => $agent->id,
        ]);
    }

    public function test_agent_only_sees_assigned_tickets_and_cannot_access_unassigned_ticket(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Nadia Customer',
            'email' => 'nadia@example.com',
        ]);

        $agentTicket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'assigned_to' => $agent->id,
            'subject' => 'Assigned to agent',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'billing',
            'source_channel' => 'web',
        ]);

        $otherTicket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'assigned_to' => $admin->id,
            'subject' => 'Not assigned to agent',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'general',
            'source_channel' => 'web',
        ]);

        $listResponse = $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/tickets");

        $listResponse->assertOk();
        $this->assertCount(1, $listResponse->json('data'));
        $this->assertSame($agentTicket->id, $listResponse->json('data.0.id'));

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/tickets/{$otherTicket->id}")
            ->assertForbidden();
    }

    public function test_owner_can_search_and_filter_tickets(): void
    {
        [$organization, $owner, $admin] = $this->makeOrganizationWithTeam();

        $customerA = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Search Alice',
            'email' => 'alice.search@example.com',
        ]);
        $customerB = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Search Bob',
            'email' => 'bob.search@example.com',
        ]);

        $ticketOne = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customerA->id,
            'created_by' => $owner->id,
            'assigned_to' => $admin->id,
            'subject' => 'Refund request for order 77',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::URGENT,
            'category' => 'refund',
            'source_channel' => 'web',
        ]);

        $ticketTwo = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customerB->id,
            'created_by' => $owner->id,
            'assigned_to' => $admin->id,
            'subject' => 'Product setup help',
            'status' => TicketStatuses::PENDING,
            'priority' => TicketPriorities::LOW,
            'category' => 'onboarding',
            'source_channel' => 'email',
        ]);

        TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $ticketOne->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'Please issue a refund quickly.',
            'is_ai_generated' => false,
        ]);

        TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $ticketTwo->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'I need onboarding guidance.',
            'is_ai_generated' => false,
        ]);

        $filtered = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/tickets?status=open&priority=urgent&category=refund");

        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame($ticketOne->id, $filtered->json('data.0.id'));

        $search = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/tickets?search=onboarding");

        $search->assertOk();
        $this->assertCount(1, $search->json('data'));
        $this->assertSame($ticketTwo->id, $search->json('data.0.id'));
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
            'name' => 'Ticket Ops Workspace',
            'slug' => 'ticket-ops-workspace',
            'join_code' => Str::upper(Str::random(10)),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
