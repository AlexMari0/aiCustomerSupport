<?php

namespace Tests\Feature\Customers;

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

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_data_is_saved_and_visible_inside_ticket_detail_with_history(): void
    {
        [$organization, $owner, $admin] = $this->seedOrganization();

        $createTicketResponse = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets", [
                'customer_name' => 'Ava Customer',
                'customer_email' => 'ava@example.com',
                'customer_phone' => '+62811111111',
                'customer_source_channel' => 'whatsapp',
                'customer_tags' => ['VIP', 'High Value Customer'],
                'subject' => 'First ticket',
                'category' => 'billing',
                'priority' => TicketPriorities::HIGH,
                'source_channel' => 'web',
                'message' => 'My billing amount seems incorrect.',
            ])
            ->assertCreated();

        $firstTicketId = (int) $createTicketResponse->json('data.id');

        $customer = Customer::query()->where('organization_id', $organization->id)->firstOrFail();

        $secondTicket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'assigned_to' => $admin->id,
            'subject' => 'Second ticket',
            'status' => TicketStatuses::PENDING,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'support',
            'source_channel' => 'email',
        ]);

        TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $secondTicket->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'Following up on another issue.',
            'is_ai_generated' => false,
        ]);

        $ticketDetailResponse = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/tickets/{$firstTicketId}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Ava Customer')
            ->assertJsonPath('data.customer.source_channel', 'whatsapp')
            ->assertJsonPath('data.customer.tags.0', 'VIP')
            ->assertJsonPath('data.customer.tags.1', 'High Value Customer');

        $history = $ticketDetailResponse->json('data.customer_ticket_history');
        $this->assertCount(1, $history);
        $this->assertSame($secondTicket->id, $history[0]['id']);
    }

    public function test_customer_list_detail_filters_and_update_tags_source_channel(): void
    {
        [$organization, $owner] = $this->seedOrganization();

        $customerOne = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Nora VIP',
            'email' => 'nora@example.com',
            'phone' => '+62822222222',
            'source_channel' => 'email',
            'tags' => ['VIP', 'Repeat Issue'],
            'last_contacted_at' => now(),
        ]);

        Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Leo New',
            'email' => 'leo@example.com',
            'phone' => '+62833333333',
            'source_channel' => 'web',
            'tags' => ['New Customer'],
            'last_contacted_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/customers?tag=VIP&source_channel=email&search=Nora")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customerOne->id);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/customers/{$customerOne->id}", [
                'tags' => ['Complaint'],
                'source_channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('data.source_channel', 'whatsapp')
            ->assertJsonPath('data.tags.0', 'Complaint');

        $this->assertDatabaseHas('customers', [
            'id' => $customerOne->id,
            'source_channel' => 'whatsapp',
        ]);
    }

    public function test_agent_can_only_access_customers_from_assigned_tickets(): void
    {
        [$organization, $owner, $admin, $agent] = $this->seedOrganization();

        $assignedCustomer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Assigned Customer',
            'email' => 'assigned@example.com',
            'source_channel' => 'web',
            'tags' => ['VIP'],
        ]);

        $blockedCustomer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Blocked Customer',
            'email' => 'blocked@example.com',
            'source_channel' => 'email',
            'tags' => ['Complaint'],
        ]);

        Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $assignedCustomer->id,
            'created_by' => $owner->id,
            'assigned_to' => $agent->id,
            'subject' => 'Assigned ticket',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'support',
            'source_channel' => 'web',
        ]);

        Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $blockedCustomer->id,
            'created_by' => $owner->id,
            'assigned_to' => $admin->id,
            'subject' => 'Admin ticket',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'support',
            'source_channel' => 'web',
        ]);

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/customers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedCustomer->id);

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/customers/{$blockedCustomer->id}")
            ->assertForbidden();
    }

    /**
     * @return array{Organization, User, User, User}
     */
    private function seedOrganization(): array
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $agent = User::factory()->create();

        $organization = Organization::query()->create([
            'name' => 'Customer Context Workspace',
            'slug' => 'customer-context-workspace',
            'join_code' => Str::upper(Str::random(10)),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
