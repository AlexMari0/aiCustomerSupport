<?php

namespace Tests\Feature\Analytics;

use App\Models\AiSuggestion;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\OrganizationRoles;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_is_restricted_by_roles(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        // 1. Agent tries to access analytics -> Forbidden
        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/analytics")
            ->assertForbidden();

        // 2. Admin accesses analytics -> OK
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/analytics")
            ->assertOk();

        // 3. Owner accesses analytics -> OK
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/analytics")
            ->assertOk();
    }

    public function test_analytics_calculates_correct_aggregates(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        // Ticket 1: refund category, urgent priority, whatsapp source, status resolved
        $t1 = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Need money back',
            'status' => TicketStatuses::RESOLVED,
            'priority' => TicketPriorities::URGENT,
            'category' => 'refund',
            'source_channel' => 'whatsapp',
        ]);

        // Ticket 2: billing category, medium priority, email source, status open
        $t2 = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Monthly billing query',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'billing',
            'source_channel' => 'email',
        ]);

        // Create a dummy automation rule to satisfy foreign keys
        $rule = AutomationRule::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Dummy Rule',
            'trigger_type' => 'ticket_created',
        ]);

        // Create AI suggestions and automation runs
        AiSuggestion::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $t1->id,
            'suggested_reply' => 'Suggested...',
        ]);

        AutomationRun::query()->create([
            'organization_id' => $organization->id,
            'automation_rule_id' => $rule->id,
            'ticket_id' => $t1->id,
            'status' => 'success',
        ]);

        AutomationRun::query()->create([
            'organization_id' => $organization->id,
            'automation_rule_id' => $rule->id,
            'ticket_id' => $t2->id,
            'status' => 'failed',
        ]);

        // Query the analytics endpoint
        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/analytics");

        $response->assertOk();
        $response->assertJsonPath('data.total_tickets', 2);

        // Verify status distribution
        $response->assertJsonPath('data.tickets_by_status.open', 1);
        $response->assertJsonPath('data.tickets_by_status.resolved', 1);
        $response->assertJsonPath('data.tickets_by_status.pending', 0);

        // Verify AI and Automation runs
        $response->assertJsonPath('data.ai_usage_count', 1);
        $response->assertJsonPath('data.automation_runs.success_count', 1);
        $response->assertJsonPath('data.automation_runs.failed_count', 1);
    }

    public function test_analytics_calculates_average_first_response_time(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'John Smith',
            'email' => 'john@example.com',
        ]);

        // Ticket 1
        $t1 = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Slow inquiry',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
        ]);

        // First customer message
        $m1 = TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $t1->id,
            'sender_type' => 'customer',
            'body' => 'Hello',
        ]);

        // Agent replies
        $m2 = TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $t1->id,
            'sender_type' => 'agent',
            'body' => 'Hi customer',
        ]);

        // Ticket 2
        $t2 = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Super slow inquiry',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
        ]);

        // Agent replies
        $m3 = TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $t2->id,
            'sender_type' => 'agent',
            'body' => 'Sorry for delay',
        ]);

        // Manually overwrite timestamps via DB queries to bypass Eloquent automations
        $now = now();
        DB::table('tickets')->where('id', $t1->id)->update(['created_at' => $now->copy()->subHours(2)]);
        DB::table('ticket_messages')->where('id', $m1->id)->update(['created_at' => $now->copy()->subHours(2)]);
        DB::table('ticket_messages')->where('id', $m2->id)->update(['created_at' => $now->copy()->subHours(1)]); // Diff = 1 hour = 3600s

        DB::table('tickets')->where('id', $t2->id)->update(['created_at' => $now->copy()->subHours(4)]);
        DB::table('ticket_messages')->where('id', $m3->id)->update(['created_at' => $now->copy()->subHours(1)]); // Diff = 3 hours = 10800s

        // Average difference should be (3600 + 10800) / 2 = 7200 seconds
        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/organizations/{$organization->id}/analytics");

        $response->assertOk();
        $response->assertJsonPath('data.avg_response_time_seconds', 7200);
    }

    public function test_analytics_enforces_strict_tenant_isolation(): void
    {
        [$orgA, $ownerA] = $this->makeOrganizationWithTeam();
        [$orgB, $ownerB] = $this->makeOrganizationWithTeam();

        // Owner A tries to query Org B analytics -> Forbidden due to organization.access check
        $this->actingAs($ownerA, 'sanctum')
            ->getJson("/api/v1/organizations/{$orgB->id}/analytics")
            ->assertForbidden();
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
