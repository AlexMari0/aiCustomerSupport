<?php

namespace Tests\Feature\AI;

use App\Jobs\ClassifyTicketJob;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_ticket_dispatches_classification_job(): void
    {
        Queue::fake();

        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets", [
                'customer_id' => $customer->id,
                'subject' => 'Need money back',
                'message' => 'I would like a refund for my payment.',
                'priority' => 'medium',
            ])
            ->assertCreated();

        Queue::assertPushed(ClassifyTicketJob::class);
    }

    public function test_classification_job_saves_ai_metrics_and_auto_populates(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        // Create a ticket without a category (null) and default medium priority
        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Need a refund for standard order #1234',
            'priority' => TicketPriorities::MEDIUM,
            'category' => null,
        ]);

        TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $ticket->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'I bought a subscription but I want to get my money back. I am very frustrated with the service.',
        ]);

        // Run job synchronously
        $job = new ClassifyTicketJob($ticket);
        $this->app->call([$job, 'handle']);

        $ticket->refresh();

        // Check fields are filled on ticket
        $this->assertSame('refund', $ticket->ai_category);
        $this->assertSame('frustrated', $ticket->ai_sentiment);
        $this->assertSame('high', $ticket->ai_priority);
        $this->assertSame(0.96, $ticket->ai_confidence);

        // Check automatic categorization and prioritization were applied
        $this->assertSame('refund', $ticket->category);
        $this->assertSame('high', $ticket->priority);
    }

    public function test_classify_endpoint_runs_synchronously(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Where is standard shipping tracking link?',
            'priority' => TicketPriorities::MEDIUM,
            'assigned_to' => $agent->id, // Assign to agent
        ]);

        TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $ticket->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'I ordered standard delivery but tracking arrived blank.',
        ]);

        // Post to classify route
        $response = $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/classify");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_category', 'shipping')
            ->assertJsonPath('data.ai_sentiment', 'neutral');

        $ticket->refresh();
        $this->assertSame('shipping', $ticket->category);
    }

    public function test_category_override_endpoint(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Hacked issue',
            'category' => 'technical_issue',
            'assigned_to' => $agent->id, // Assign to agent
        ]);

        // Override category to refund
        $response = $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/category", [
                'category' => 'refund',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.category', 'refund');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'category' => 'refund',
        ]);
    }

    public function test_classification_enforces_tenant_scoping(): void
    {
        [$orgA, $ownerA] = $this->makeOrganizationWithTeam();
        [$orgB, $ownerB] = $this->makeOrganizationWithTeam();

        $customerB = Customer::query()->create([
            'organization_id' => $orgB->id,
            'name' => 'Tenant B Customer',
            'email' => 'tb@example.com',
        ]);

        $ticketB = Ticket::query()->create([
            'organization_id' => $orgB->id,
            'customer_id' => $customerB->id,
            'subject' => 'Tenant B Issue',
        ]);

        // Owner A tries to classify Ticket B under Org A -> should be Not Found
        $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/v1/organizations/{$orgA->id}/tickets/{$ticketB->id}/classify")
            ->assertNotFound();

        // Owner A tries to update category of Ticket B under Org A -> should be Not Found
        $this->actingAs($ownerA, 'sanctum')
            ->patchJson("/api/v1/organizations/{$orgA->id}/tickets/{$ticketB->id}/category", [
                'category' => 'billing',
            ])
            ->assertNotFound();
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
            'name' => 'AI Ops Workspace ' . Str::random(5),
            'slug' => 'ai-ops-workspace-' . Str::random(5),
            'join_code' => Str::upper(Str::random(10)),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
