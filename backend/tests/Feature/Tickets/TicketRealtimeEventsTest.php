<?php

namespace Tests\Feature\Tickets;

use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketMessageCreated;
use App\Events\Tickets\TicketResolved;
use App\Events\Tickets\TicketUpdated;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Support\OrganizationRoles;
use App\Support\TicketMessageSenderTypes;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketRealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_created_event_is_dispatched(): void
    {
        [$organization, $owner] = $this->makeOrganizationWithTeam();

        Event::fake([TicketCreated::class]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets", [
                'customer_name' => 'Realtime Customer',
                'customer_email' => 'realtime@example.com',
                'subject' => 'Need billing support',
                'priority' => TicketPriorities::HIGH,
                'category' => 'billing',
                'message' => 'Please help me with invoice details.',
            ]);

        $response->assertCreated();

        Event::assertDispatched(TicketCreated::class, function (TicketCreated $event) use ($organization, $owner): bool {
            return (int) $event->ticket->organization_id === (int) $organization->id
                && (int) $event->actorUserId === (int) $owner->id;
        });
    }

    public function test_status_update_dispatches_updated_and_resolved_events(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Realtime Agent Customer',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'assigned_to' => $agent->id,
            'subject' => 'Pending issue',
            'status' => TicketStatuses::PENDING,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'support',
            'source_channel' => 'web',
        ]);

        Event::fake([TicketUpdated::class, TicketResolved::class]);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/status", [
                'status' => TicketStatuses::RESOLVED,
            ])
            ->assertOk();

        Event::assertDispatched(TicketUpdated::class, function (TicketUpdated $event) use ($ticket, $agent): bool {
            return (int) $event->ticket->id === (int) $ticket->id
                && (int) $event->actorUserId === (int) $agent->id
                && (($event->changes['status']['to'] ?? null) === TicketStatuses::RESOLVED);
        });

        Event::assertDispatched(TicketResolved::class, function (TicketResolved $event) use ($ticket): bool {
            return (int) $event->ticket->id === (int) $ticket->id;
        });
    }

    public function test_assignment_and_message_dispatch_realtime_events(): void
    {
        [$organization, $owner, $admin, $agent] = $this->makeOrganizationWithTeam();

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Realtime Dispatch Customer',
        ]);

        $ticket = Ticket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'created_by' => $owner->id,
            'subject' => 'Assignment and message',
            'status' => TicketStatuses::OPEN,
            'priority' => TicketPriorities::MEDIUM,
            'category' => 'support',
            'source_channel' => 'web',
        ]);

        Event::fake([TicketAssigned::class, TicketUpdated::class, TicketMessageCreated::class]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/assign", [
                'assignee_id' => $agent->id,
            ])
            ->assertOk();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/organizations/{$organization->id}/tickets/{$ticket->id}/messages", [
                'sender_type' => TicketMessageSenderTypes::AGENT,
                'body' => 'I am assigned and handling this now.',
            ])
            ->assertCreated();

        Event::assertDispatched(TicketAssigned::class, function (TicketAssigned $event) use ($ticket, $agent): bool {
            return (int) $event->ticket->id === (int) $ticket->id
                && (int) $event->ticket->assigned_to === (int) $agent->id;
        });

        Event::assertDispatched(TicketUpdated::class, function (TicketUpdated $event) use ($ticket, $agent): bool {
            return (int) $event->ticket->id === (int) $ticket->id
                && (($event->changes['assigned_to']['to'] ?? null) === $agent->id);
        });

        Event::assertDispatched(TicketMessageCreated::class, function (TicketMessageCreated $event) use ($ticket): bool {
            return (int) $event->ticket->id === (int) $ticket->id
                && $event->message->sender_type === TicketMessageSenderTypes::AGENT;
        });
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
            'name' => 'Realtime Workspace',
            'slug' => 'realtime-workspace',
            'join_code' => Str::upper(Str::random(10)),
            'owner_user_id' => $owner->id,
        ]);

        $organization->users()->attach($owner->id, ['role' => OrganizationRoles::OWNER]);
        $organization->users()->attach($admin->id, ['role' => OrganizationRoles::ADMIN]);
        $organization->users()->attach($agent->id, ['role' => OrganizationRoles::AGENT]);

        return [$organization, $owner, $admin, $agent];
    }
}
