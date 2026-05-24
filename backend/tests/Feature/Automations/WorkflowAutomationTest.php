<?php

namespace Tests\Feature\Automations;

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
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_engine_evaluates_and_executes_rules(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        // 1. Create a ticket
        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subject' => 'Need money back',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'refund',
        ]);

        // 2. Create an automation rule: IF category = refund THEN assign_to_agent = $agent->id AND change_priority = urgent
        $rule = AutomationRule::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Escalate Refund',
            'trigger_type' => 'ticket_created',
            'is_active' => true,
        ]);

        $rule->conditions()->create([
            'field' => 'category',
            'value' => 'refund',
        ]);

        $rule->actions()->create([
            'action_type' => 'assign_to_agent',
            'action_value' => (string) $agent->id,
        ]);

        $rule->actions()->create([
            'action_type' => 'change_priority',
            'action_value' => TicketPriorities::URGENT,
        ]);

        // 3. Trigger workflow engine
        app(WorkflowEngine::class)->trigger('ticket_created', $ticket);

        $ticket->refresh();

        // 4. Assert actions were executed
        $this->assertSame($agent->id, $ticket->assigned_to);
        $this->assertSame(TicketPriorities::URGENT, $ticket->priority);

        // 5. Assert run was logged
        $this->assertDatabaseHas('automation_runs', [
            'organization_id' => $organization->id,
            'automation_rule_id' => $rule->id,
            'ticket_id' => $ticket->id,
            'status' => 'success',
        ]);
    }

    public function test_mutating_rules_is_restricted_by_roles(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        // 1. Agent tries to create a rule -> forbidden
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/automations/rules", [
                'name' => 'Test Rule',
                'trigger_type' => 'ticket_created',
                'actions' => [
                    ['action_type' => 'mark_as_pending'],
                ],
            ])
            ->assertForbidden();

        // 2. Admin creates a rule -> created
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/automations/rules", [
                'name' => 'Admin Escalate',
                'trigger_type' => 'priority_changed',
                'conditions' => [
                    ['field' => 'status', 'value' => 'open'],
                ],
                'actions' => [
                    ['action_type' => 'change_priority', 'action_value' => 'high'],
                ],
            ]);

        $response->assertCreated();
        $ruleId = $response->json('data.id');

        $this->assertDatabaseHas('automation_rules', [
            'id' => $ruleId,
            'name' => 'Admin Escalate',
        ]);

        // 3. Owner deletes a rule -> ok
        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/organizations/{$organization->id}/automations/rules/{$ruleId}")
            ->assertOk();

        $this->assertDatabaseMissing('automation_rules', ['id' => $ruleId]);
    }

    public function test_automations_enforce_tenant_scoping(): void
    {
        [$orgA, $ownerA] = $this->makeOrganizationWithTeam();
        [$orgB, $ownerB] = $this->makeOrganizationWithTeam();

        $ruleB = AutomationRule::query()->create([
            'organization_id' => $orgB->id,
            'name' => 'Org B rule',
            'trigger_type' => 'ticket_created',
        ]);

        // Owner A tries to toggle rule in Org B -> Not Found or Forbidden
        $this->actingAs($ownerA, 'sanctum')
            ->patchJson("/api/v1/organizations/{$orgA->id}/automations/rules/{$ruleB->id}/toggle")
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
