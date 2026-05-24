<?php

namespace Tests\Feature\KnowledgeBase;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_perform_kb_category_and_article_crud(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        // 1. Owner creates a category
        $catResponse = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/knowledge-base/categories", [
                'name' => 'General Policies',
                'slug' => 'general-policies',
                'description' => 'Standard business rules and operations.',
            ]);

        $catResponse->assertCreated()->assertJsonPath('success', true);
        $categoryId = (int) $catResponse->json('data.id');

        $this->assertDatabaseHas('knowledge_base_categories', [
            'id' => $categoryId,
            'organization_id' => $organization->id,
            'name' => 'General Policies',
            'slug' => 'general-policies',
        ]);

        // 2. Admin updates the category
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/knowledge-base/categories/{$categoryId}", [
                'name' => 'General Policies & Guides',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'General Policies & Guides');

        // 3. Agent cannot create a category
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/knowledge-base/categories", [
                'name' => 'Agent Sandbox',
                'slug' => 'agent-sandbox',
            ])
            ->assertForbidden();

        // 4. Owner creates an article
        $artResponse = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles", [
                'category_id' => $categoryId,
                'title' => 'Standard Refund Rules',
                'slug' => 'standard-refund-rules',
                'content' => 'Refunds are given within 30 days.',
                'status' => 'draft',
            ]);

        $artResponse->assertCreated()->assertJsonPath('success', true);
        $articleId = (int) $artResponse->json('data.id');

        $this->assertDatabaseHas('knowledge_base_articles', [
            'id' => $articleId,
            'organization_id' => $organization->id,
            'title' => 'Standard Refund Rules',
            'status' => 'draft',
        ]);

        // 5. Admin updates and publishes the article
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles/{$articleId}", [
                'status' => 'published',
                'content' => 'Refunds are given within 30 days of purchase only.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // 6. Agent cannot create or update an article
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles", [
                'title' => 'Agent Article',
                'slug' => 'agent-article',
                'content' => 'Some content.',
                'status' => 'draft',
            ])
            ->assertForbidden();

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles/{$articleId}", [
                'title' => 'Hijacked Title',
            ])
            ->assertForbidden();

        // 7. Agent CAN view the article
        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles/{$articleId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Standard Refund Rules');

        // 8. Admin deletes the article and Owner deletes the category
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles/{$articleId}")
            ->assertOk();

        $this->assertDatabaseMissing('knowledge_base_articles', ['id' => $articleId]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/organizations/{$organization->id}/knowledge-base/categories/{$categoryId}")
            ->assertOk();

        $this->assertDatabaseMissing('knowledge_base_categories', ['id' => $categoryId]);
    }

    public function test_kb_tenant_isolation_is_enforced(): void
    {
        // Setup two different organizations
        [$orgA, $ownerA] = $this->makeOrganizationWithTeam();
        [$orgB, $ownerB] = $this->makeOrganizationWithTeam();

        // Org A owner creates a category and an article
        $catA = KnowledgeBaseCategory::query()->create([
            'organization_id' => $orgA->id,
            'name' => 'Category A',
            'slug' => 'cat-a',
            'created_by' => $ownerA->id,
        ]);

        $artA = KnowledgeBaseArticle::query()->create([
            'organization_id' => $orgA->id,
            'category_id' => $catA->id,
            'title' => 'Article A',
            'slug' => 'article-a',
            'content' => 'Content of A',
            'status' => 'published',
            'created_by' => $ownerA->id,
        ]);

        // Owner B tries to read category or article of A -> Should be Forbidden or Not Found
        $this->actingAs($ownerB, 'sanctum')
            ->getJson("/api/v1/organizations/{$orgB->id}/knowledge-base/articles/{$artA->id}")
            ->assertNotFound();

        // Owner B tries to update/delete category or article of A -> Should fail
        $this->actingAs($ownerB, 'sanctum')
            ->patchJson("/api/v1/organizations/{$orgB->id}/knowledge-base/categories/{$catA->id}", [
                'name' => 'Hacked Name',
            ])
            ->assertNotFound();

        // List articles of Org B -> Should not return Org A's articles
        $listResponse = $this->actingAs($ownerB, 'sanctum')
            ->getJson("/api/v1/organizations/{$orgB->id}/knowledge-base/articles");

        $listResponse->assertOk();
        $this->assertCount(0, $listResponse->json('data'));
    }

    public function test_kb_articles_can_be_searched_and_filtered(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $catBilling = KnowledgeBaseCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Billing Help',
            'slug' => 'billing-help',
            'created_by' => $owner->id,
        ]);

        $catShipping = KnowledgeBaseCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Shipping Rules',
            'slug' => 'shipping-rules',
            'created_by' => $owner->id,
        ]);

        $artRefund = KnowledgeBaseArticle::query()->create([
            'organization_id' => $organization->id,
            'category_id' => $catBilling->id,
            'title' => 'Refund Guide',
            'slug' => 'refund-guide',
            'content' => 'How to ask for money back.',
            'status' => 'published',
            'created_by' => $owner->id,
        ]);

        $artAddress = KnowledgeBaseArticle::query()->create([
            'organization_id' => $organization->id,
            'category_id' => $catShipping->id,
            'title' => 'Change Shipping Address',
            'slug' => 'change-shipping-address',
            'content' => 'Steps to update delivery coordinates.',
            'status' => 'published',
            'created_by' => $owner->id,
        ]);

        // Search by keyword "coordinates" -> should match article 2
        $searchResponse = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles?search=coordinates");

        $searchResponse->assertOk();
        $this->assertCount(1, $searchResponse->json('data'));
        $this->assertSame($artAddress->id, $searchResponse->json('data.0.id'));

        // Filter by category_id of Billing -> should match article 1
        $filterResponse = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/knowledge-base/articles?category_id={$catBilling->id}");

        $filterResponse->assertOk();
        $this->assertCount(1, $filterResponse->json('data'));
        $this->assertSame($artRefund->id, $filterResponse->json('data.0.id'));
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
            'name' => 'Ops Workspace ' . Str::random(5),
            'slug' => 'ops-workspace-' . Str::random(5),
            'join_code' => Str::upper(Str::random(10)),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
