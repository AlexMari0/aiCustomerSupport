<?php

namespace App\Events\Tickets;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketResolved implements ShouldBroadcastNow
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
            new PrivateChannel("organizations.{$this->ticket->organization_id}.tickets.{$this->ticket->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.resolved';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket' => [
                'id' => $this->ticket->id,
                'status' => $this->ticket->status,
                'updated_at' => $this->ticket->updated_at,
            ],
            'actor_user_id' => $this->actorUserId,
        ];
    }
}
