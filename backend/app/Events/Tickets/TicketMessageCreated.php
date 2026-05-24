<?php

namespace App\Events\Tickets;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketMessage $message,
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
        return 'ticket.message-created';
    }

    public function broadcastWith(): array
    {
        $message = $this->message->fresh(['senderUser:id,name,email']);

        return [
            'ticket_id' => $this->ticket->id,
            'message' => [
                'id' => $message?->id,
                'sender_type' => $message?->sender_type,
                'sender_user' => $message?->senderUser?->only(['id', 'name', 'email']),
                'body' => $message?->body,
                'created_at' => $message?->created_at,
            ],
            'actor_user_id' => $this->actorUserId,
        ];
    }
}
