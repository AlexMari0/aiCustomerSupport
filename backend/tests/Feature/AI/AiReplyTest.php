<?php

namespace Tests\Feature\AI;

use App\Jobs\GenerateKnowledgeEmbeddingJob;
use App\Models\AiSuggestion;
use App\Models\Customer;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeEmbedding;
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

class AiReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_article_dispatches_embedding_job(): void
    {
        Queue::fake();

        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles", [
                'title' => 'Embedding Test',
                'slug' => 'embedding-test',
                'content' => 'This content needs embedding.',
                'status' => 'published',
            ])
            ->assertCreated();

        Queue::assertPushed(GenerateKnowledgeEmbeddingJob::class);
    }

    public function test_embedding_job_generates_and_stores_vector(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $article = KnowledgeBaseArticle::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Refund Guide',
            'slug' => 'refund-guide',
            'content' => 'Refunds within 30 days are fully supported.',
            'status' => 'published',
            'created_by' => $owner->id,
        ]);

        // Run embedding job synchronously
        $job = new GenerateKnowledgeEmbeddingJob($article);
        $this->app->call([$job, 'handle']);

        $this->assertDatabaseHas('knowledge_embeddings', [
            'organization_id' => $organization->id,
            'article_id' => $article->id,
        ]);

        $embed = KnowledgeEmbedding::query()->where('article_id', $article->id)->first();
        $this->assertIsArray($embed->embedding);
        $this->assertCount(1536, $embed->embedding);
    }

    public function test_suggest_api_generates_context_grounded_reply_and_logs_history(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        // 1. Create a published article and run embedding job
        $article = KnowledgeBaseArticle::query()->create([
            'organization_id' => $organization->id,
            'title' => 'Official Refund Policy',
            'slug' => 'official-refund-policy',
            'content' => 'Refunds are given within 30 days of purchase for unused original packages.',
            'status' => 'published',
            'created_by' => $owner->id,
        ]);

        $job = new GenerateKnowledgeEmbeddingJob($article);
        $this->app->call([$job, 'handle']);

        // 2. Create customer and ticket with refund query
        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'John Customer',
            'email' => 'john@example.com',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'subject' => 'Requesting a refund',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
        ]);

        TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $ticket->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'I would like a refund for my order. It has been 10 days since purchase.',
        ]);

        // 3. Query suggest API
        $response = $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/ai-suggest");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'suggested_reply',
                    'referenced_articles',
                    'history',
                ],
            ]);

        // Verify referenced article contains Refund Policy
        $this->assertCount(1, $response->json('data.referenced_articles'));
        $this->assertSame($article->id, $response->json('data.referenced_articles.0.id'));

        // Verify history is stored in the database
        $this->assertDatabaseHas('ai_suggestions', [
            'organization_id' => $organization->id,
            'ticket_id' => $ticket->id,
        ]);

        $suggestion = AiSuggestion::query()->where('ticket_id', $ticket->id)->first();
        $this->assertContains($article->id, $suggestion->metadata['referenced_article_ids']);
    }

    public function test_ai_suggest_enforces_tenant_scoping(): void
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

        TicketMessage::query()->create([
            'organization_id' => $orgB->id,
            'ticket_id' => $ticketB->id,
            'sender_type' => TicketMessageSenderTypes::CUSTOMER,
            'body' => 'Need help in B.',
        ]);

        // Owner A tries to request suggestion for Ticket B under Org A -> should be Not Found or Forbidden
        $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/v1/organizations/{$orgA->id}/tickets/{$ticketB->id}/ai-suggest")
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
