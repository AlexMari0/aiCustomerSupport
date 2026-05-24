<?php

namespace App\Events\Tickets;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketAssigned implements ShouldBroadcastNow
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
        $channels = [
            new PrivateChannel("organizations.{$this->ticket->organization_id}.tickets"),
            new PrivateChannel("organizations.{$this->ticket->organization_id}.tickets.{$this->ticket->id}"),
        ];

        if ($this->ticket->assigned_to !== null) {
            $channels[] = new PrivateChannel("users.{$this->ticket->assigned_to}.assignments");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ticket.assigned';
    }

    public function broadcastWith(): array
    {
        $ticket = $this->ticket->fresh(['assignee:id,name,email']);

        return [
            'ticket' => [
                'id' => $ticket?->id,
                'subject' => $ticket?->subject,
                'assigned_to' => $ticket?->assigned_to,
                'assignee' => $ticket?->assignee?->only(['id', 'name', 'email']),
                'updated_at' => $ticket?->updated_at,
            ],
            'actor_user_id' => $this->actorUserId,
        ];
    }
}
