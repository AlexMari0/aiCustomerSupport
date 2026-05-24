<?php

namespace App\Events\Tickets;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public int $actorUserId
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("organizations.{$this->ticket->organization_id}.tickets"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.created';
    }

    public function broadcastWith(): array
    {
        $ticket = $this->ticket->fresh(['customer:id,name,email', 'assignee:id,name,email']);

        return [
            'ticket' => [
                'id' => $ticket?->id,
                'subject' => $ticket?->subject,
                'status' => $ticket?->status,
                'priority' => $ticket?->priority,
                'category' => $ticket?->category,
                'source_channel' => $ticket?->source_channel,
                'assigned_to' => $ticket?->assigned_to,
                'customer' => $ticket?->customer?->only(['id', 'name', 'email']),
                'assignee' => $ticket?->assignee?->only(['id', 'name', 'email']),
                'created_at' => $ticket?->created_at,
                'updated_at' => $ticket?->updated_at,
            ],
            'actor_user_id' => $this->actorUserId,
        ];
    }
}
