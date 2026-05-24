<?php

namespace App\Events\Tickets;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, array<string, mixed>>  $changes
     */
    public function __construct(
        public Ticket $ticket,
        public array $changes,
        public int $actorUserId
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("organizations.{$this->ticket->organization_id}.tickets"),
            new PrivateChannel("organizations.{$this->ticket->organization_id}.tickets.{$this->ticket->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.updated';
    }

    public function broadcastWith(): array
    {
        $ticket = $this->ticket->fresh(['assignee:id,name,email']);

        return [
            'ticket' => [
                'id' => $ticket?->id,
                'status' => $ticket?->status,
                'priority' => $ticket?->priority,
                'assigned_to' => $ticket?->assigned_to,
                'assignee' => $ticket?->assignee?->only(['id', 'name', 'email']),
                'updated_at' => $ticket?->updated_at,
            ],
            'changes' => $this->changes,
            'actor_user_id' => $this->actorUserId,
        ];
    }
}
