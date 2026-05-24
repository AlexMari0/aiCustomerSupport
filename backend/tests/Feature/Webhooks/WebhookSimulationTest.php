<?php

namespace Tests\Feature\Webhooks;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\OrganizationRoles;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_webhook_creates_pending_event_and_dispatches_job(): void
    {
        Queue::fake();

        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $payload = [
            'channel' => 'whatsapp',
            'customer_name' => 'Budi WhatsApp',
            'customer_phone' => '08123456789',
            'message' => 'I want to request a refund for my order.',
        ];

        // Send to public webhook endpoint with valid organization webhook token
        $response = $this->postJson("/api/webhooks/inbound-message?token={$organization->webhook_token}", $payload);

        $response->assertStatus(202); // Accepted
        $response->assertJsonPath('success', true);

        // Verify log is created in pending status
        $this->assertDatabaseHas('webhook_events', [
            'organization_id' => $organization->id,
            'channel' => 'whatsapp',
            'status' => 'pending',
        ]);

        $webhookEventId = $response->json('data.id');

        // Verify ProcessWebhookJob is dispatched
        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_public_webhook_fails_with_invalid_token(): void
    {
        $payload = [
            'channel' => 'whatsapp',
            'customer_name' => 'Budi WhatsApp',
            'customer_phone' => '08123456789',
            'message' => 'I want to request a refund.',
        ];

        $response = $this->postJson('/api/webhooks/inbound-message?token=invalid_token', $payload);
        $response->assertStatus(401);
    }

    public function test_process_webhook_job_handles_whatsapp_inbound_successfully(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $payload = [
            'channel' => 'whatsapp',
            'customer_name' => 'Budi WhatsApp',
            'customer_phone' => '08123456789',
            'message' => 'I want to request a refund for my order.',
        ];

        $webhookEvent = WebhookEvent::query()->create([
            'organization_id' => $organization->id,
            'channel' => 'whatsapp',
            'payload' => $payload,
            'status' => 'pending',
        ]);

        // Process job synchronously
        $job = new ProcessWebhookJob($webhookEvent);
        $job->handle();

        $webhookEvent->refresh();

        // 1. Assert status is processed and linked to a ticket
        $this->assertSame('processed', $webhookEvent->status);
        $this->assertNotNull($webhookEvent->ticket_id);
        $this->assertNull($webhookEvent->error_message);

        // 2. Assert customer was created with correct phone
        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id,
            'name' => 'Budi WhatsApp',
            'phone' => '08123456789',
            'source_channel' => 'whatsapp',
        ]);

        $customer = Customer::query()->where('phone', '08123456789')->first();

        // 3. Assert ticket was created
        $this->assertDatabaseHas('tickets', [
            'id' => $webhookEvent->ticket_id,
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'I want to request a refund for my order.',
            'source_channel' => 'whatsapp',
            'status' => 'open',
        ]);

        // 4. Assert ticket message was created from customer
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $webhookEvent->ticket_id,
            'sender_type' => 'customer',
            'body' => 'I want to request a refund for my order.',
        ]);
    }

    public function test_process_webhook_job_handles_email_inbound_successfully(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $payload = [
            'channel' => 'email',
            'customer_name' => 'Jane Email',
            'customer_email' => 'jane@example.com',
            'subject' => 'URGENT: Broken link',
            'message' => 'Your site is throwing 500 server error.',
        ];

        $webhookEvent = WebhookEvent::query()->create([
            'organization_id' => $organization->id,
            'channel' => 'email',
            'payload' => $payload,
            'status' => 'pending',
        ]);

        $job = new ProcessWebhookJob($webhookEvent);
        $job->handle();

        $webhookEvent->refresh();

        $this->assertSame('processed', $webhookEvent->status);

        // Assert customer created with email
        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id,
            'name' => 'Jane Email',
            'email' => 'jane@example.com',
            'source_channel' => 'email',
        ]);

        // Assert ticket matches subject
        $this->assertDatabaseHas('tickets', [
            'id' => $webhookEvent->ticket_id,
            'subject' => 'URGENT: Broken link',
            'source_channel' => 'email',
        ]);
    }

    public function test_protected_webhook_endpoints_enforce_rbac_and_isolation(): void
    {
        [$orgA, $ownerA, $adminA, $agentA] = $this->makeOrganizationWithTeam();
        [$orgB, $ownerB] = $this->makeOrganizationWithTeam();

        $webhookEventA = WebhookEvent::query()->create([
            'organization_id' => $orgA->id,
            'channel' => 'whatsapp',
            'payload' => ['customer_name' => 'A', 'message' => 'M'],
            'status' => 'failed',
            'error_message' => 'Broken connection',
        ]);

        // 1. Agent A tries to fetch logs -> Forbidden
        $this->actingAs($agentA, 'sanctum')
            ->getJson("/api/v1/organizations/{$orgA->id}/webhooks/logs")
            ->assertForbidden();

        // 2. Admin A fetches logs -> Success
        $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/v1/organizations/{$orgA->id}/webhooks/logs")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 3. Owner A retries the failed event -> Success
        $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/v1/organizations/{$orgA->id}/webhooks/logs/{$webhookEventA->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        // 4. Owner B tries to retry Org A's webhook event -> Not Found
        $this->actingAs($ownerB, 'sanctum')
            ->postJson("/api/v1/organizations/{$orgB->id}/webhooks/logs/{$webhookEventA->id}/retry")
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
            'webhook_token' => Str::random(32),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
