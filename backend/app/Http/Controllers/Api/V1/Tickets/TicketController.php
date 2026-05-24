<?php

namespace App\Http\Controllers\Api\V1\Tickets;

use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketMessageCreated;
use App\Events\Tickets\TicketResolved;
use App\Events\Tickets\TicketUpdated;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\StoreTicketMessageRequest;
use App\Http\Requests\Tickets\StoreTicketNoteRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketPriorityRequest;
use App\Http\Requests\Tickets\UpdateTicketStatusRequest;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketNote;
use App\Support\OrganizationRoles;
use App\Support\TicketMessageSenderTypes;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends ApiController
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        $role = $user->organizationRole($organization->id);

        $query = Ticket::query()
            ->where('organization_id', $organization->id)
            ->with([
                'customer:id,name,email,source_channel,tags,last_contacted_at',
                'assignee:id,name,email',
            ])
            ->withCount(['messages', 'notes']);

        if ($role === OrganizationRoles::AGENT) {
            $query->where('assigned_to', $user->id);
        }

        $status = $request->query('status');
        if (is_string($status) && in_array($status, TicketStatuses::all(), true)) {
            $query->where('status', $status);
        }

        $priority = $request->query('priority');
        if (is_string($priority) && in_array($priority, TicketPriorities::all(), true)) {
            $query->where('priority', $priority);
        }

        $assigneeId = $request->query('assignee_id');
        if (is_numeric($assigneeId)) {
            $query->where('assigned_to', (int) $assigneeId);
        }

        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $query->where('category', $category);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $searchTerm = trim($search);
            $query->where(function (Builder $builder) use ($searchTerm): void {
                $builder->where('subject', 'like', "%{$searchTerm}%")
                    ->orWhere('category', 'like', "%{$searchTerm}%")
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($searchTerm): void {
                        $customerQuery->where('name', 'like', "%{$searchTerm}%")
                            ->orWhere('email', 'like', "%{$searchTerm}%");
                    })
                    ->orWhereHas('messages', function (Builder $messageQuery) use ($searchTerm): void {
                        $messageQuery->where('body', 'like', "%{$searchTerm}%");
                    });
            });
        }

        $tickets = $query
            ->latest('updated_at')
            ->get()
            ->map(function (Ticket $ticket) {
                return [
                    'id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'category' => $ticket->category,
                    'source_channel' => $ticket->source_channel,
                    'customer' => $ticket->customer?->only(['id', 'name', 'email', 'source_channel', 'tags', 'last_contacted_at']),
                    'assignee' => $ticket->assignee?->only(['id', 'name', 'email']),
                    'messages_count' => $ticket->messages_count,
                    'notes_count' => $ticket->notes_count,
                    'ai_category' => $ticket->ai_category,
                    'ai_sentiment' => $ticket->ai_sentiment,
                    'ai_priority' => $ticket->ai_priority,
                    'ai_confidence' => $ticket->ai_confidence,
                    'created_at' => $ticket->created_at,
                    'updated_at' => $ticket->updated_at,
                ];
            })
            ->values();

        return $this->success($tickets, 'Tickets retrieved.');
    }

    public function show(Request $request, Organization $organization, Ticket $ticket): JsonResponse
    {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $this->assertAgentCanAccessTicket($request, $organization, $scopedTicket);

        $scopedTicket->load([
            'customer:id,name,email,phone,source_channel,tags,last_contacted_at',
            'assignee:id,name,email',
            'creator:id,name,email',
            'messages' => fn ($query) => $query->with('senderUser:id,name,email')->orderBy('created_at'),
            'notes' => fn ($query) => $query->with('user:id,name,email')->latest('created_at'),
        ]);

        $customerTicketHistory = Ticket::query()
            ->where('organization_id', $organization->id)
            ->where('customer_id', $scopedTicket->customer_id)
            ->where('id', '!=', $scopedTicket->id)
            ->with(['assignee:id,name,email'])
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(function (Ticket $historyTicket) {
                return [
                    'id' => $historyTicket->id,
                    'subject' => $historyTicket->subject,
                    'status' => $historyTicket->status,
                    'priority' => $historyTicket->priority,
                    'category' => $historyTicket->category,
                    'assignee' => $historyTicket->assignee?->only(['id', 'name', 'email']),
                    'created_at' => $historyTicket->created_at,
                    'updated_at' => $historyTicket->updated_at,
                ];
            })
            ->values();

        return $this->success([
            'id' => $scopedTicket->id,
            'subject' => $scopedTicket->subject,
            'status' => $scopedTicket->status,
            'priority' => $scopedTicket->priority,
            'category' => $scopedTicket->category,
            'source_channel' => $scopedTicket->source_channel,
            'ai_category' => $scopedTicket->ai_category,
            'ai_sentiment' => $scopedTicket->ai_sentiment,
            'ai_priority' => $scopedTicket->ai_priority,
            'ai_confidence' => $scopedTicket->ai_confidence,
            'customer' => $scopedTicket->customer?->only([
                'id',
                'name',
                'email',
                'phone',
                'source_channel',
                'tags',
                'last_contacted_at',
            ]),
            'assignee' => $scopedTicket->assignee?->only(['id', 'name', 'email']),
            'creator' => $scopedTicket->creator?->only(['id', 'name', 'email']),
            'customer_ticket_history' => $customerTicketHistory,
            'messages' => $scopedTicket->messages->map(function (TicketMessage $message) {
                return [
                    'id' => $message->id,
                    'sender_type' => $message->sender_type,
                    'sender_user' => $message->senderUser?->only(['id', 'name', 'email']),
                    'body' => $message->body,
                    'created_at' => $message->created_at,
                ];
            })->values(),
            'notes' => $scopedTicket->notes->map(function (TicketNote $note) {
                return [
                    'id' => $note->id,
                    'note' => $note->note,
                    'is_private' => $note->is_private,
                    'user' => $note->user?->only(['id', 'name', 'email']),
                    'created_at' => $note->created_at,
                ];
            })->values(),
            'created_at' => $scopedTicket->created_at,
            'updated_at' => $scopedTicket->updated_at,
        ], 'Ticket detail retrieved.');
    }

    public function store(StoreTicketRequest $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        $ticket = DB::transaction(function () use ($request, $organization, $user): Ticket {
            $customer = null;

            if ($request->filled('customer_id')) {
                $customer = Customer::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey((int) $request->integer('customer_id'))
                    ->first();

                if ($customer === null) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Selected customer does not belong to this organization.',
                        'errors' => ['customer_id' => ['Invalid customer for this organization.']],
                    ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY));
                }
            } else {
                $customer = Customer::query()->create([
                    'organization_id' => $organization->id,
                    'name' => $request->string('customer_name')->value(),
                    'email' => $request->string('customer_email')->value() ?: null,
                    'phone' => $request->string('customer_phone')->value() ?: null,
                    'source_channel' => $request->string('customer_source_channel')->value() ?: $request->string('source_channel')->value() ?: null,
                    'tags' => $request->input('customer_tags', []),
                    'last_contacted_at' => now(),
                ]);
            }

            if ($request->filled('customer_id') && $customer !== null) {
                $customer->update([
                    'source_channel' => $customer->source_channel ?: ($request->string('customer_source_channel')->value() ?: $request->string('source_channel')->value() ?: null),
                    'last_contacted_at' => now(),
                ]);
            }

            $ticket = Ticket::query()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'created_by' => $user->id,
                'subject' => $request->string('subject')->value(),
                'status' => TicketStatuses::OPEN,
                'priority' => $request->string('priority')->value() ?: TicketPriorities::MEDIUM,
                'category' => $request->string('category')->value() ?: null,
                'source_channel' => $request->string('source_channel')->value() ?: 'web',
            ]);

            TicketMessage::query()->create([
                'organization_id' => $organization->id,
                'ticket_id' => $ticket->id,
                'sender_type' => TicketMessageSenderTypes::CUSTOMER,
                'body' => $request->string('message')->value(),
                'is_ai_generated' => false,
            ]);

            return $ticket;
        });

        TicketCreated::dispatch($ticket, $request->user()->id);

        \App\Jobs\ClassifyTicketJob::dispatch($ticket);

        app(\App\Services\WorkflowEngine::class)->trigger('ticket_created', $ticket);

        app(\App\Services\AuditLogger::class)->log(
            organizationId: $organization->id,
            userId: $request->user()->id,
            event: 'ticket_created',
            targetType: 'Ticket',
            targetId: $ticket->id,
            metadata: [
                'subject' => $ticket->subject,
                'priority' => $ticket->priority,
                'category' => $ticket->category,
                'source_channel' => $ticket->source_channel,
            ]
        );

        return $this->success([
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
        ], 'Ticket created successfully.', JsonResponse::HTTP_CREATED);
    }

    public function updateStatus(
        UpdateTicketStatusRequest $request,
        Organization $organization,
        Ticket $ticket
    ): JsonResponse {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $this->assertAgentCanAccessTicket($request, $organization, $scopedTicket);
        $previousStatus = $scopedTicket->status;

        $scopedTicket->update([
            'status' => $request->string('status')->value(),
        ]);

        TicketUpdated::dispatch(
            $scopedTicket,
            [
                'status' => [
                    'from' => $previousStatus,
                    'to' => $scopedTicket->status,
                ],
            ],
            $request->user()->id
        );

        if ($scopedTicket->status === TicketStatuses::RESOLVED && $previousStatus !== TicketStatuses::RESOLVED) {
            TicketResolved::dispatch($scopedTicket, $request->user()->id);
        }

        app(\App\Services\WorkflowEngine::class)->trigger('ticket_updated', $scopedTicket);

        app(\App\Services\AuditLogger::class)->log(
            organizationId: $organization->id,
            userId: $request->user()->id,
            event: 'status_changed',
            targetType: 'Ticket',
            targetId: $scopedTicket->id,
            metadata: [
                'previous_status' => $previousStatus,
                'new_status' => $scopedTicket->status,
            ]
        );

        return $this->success([
            'id' => $scopedTicket->id,
            'status' => $scopedTicket->status,
        ], 'Ticket status updated.');
    }

    public function updatePriority(
        UpdateTicketPriorityRequest $request,
        Organization $organization,
        Ticket $ticket
    ): JsonResponse {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $previousPriority = $scopedTicket->priority;

        $scopedTicket->update([
            'priority' => $request->string('priority')->value(),
        ]);

        TicketUpdated::dispatch(
            $scopedTicket,
            [
                'priority' => [
                    'from' => $previousPriority,
                    'to' => $scopedTicket->priority,
                ],
            ],
            $request->user()->id
        );

        app(\App\Services\WorkflowEngine::class)->trigger('priority_changed', $scopedTicket);

        return $this->success([
            'id' => $scopedTicket->id,
            'priority' => $scopedTicket->priority,
        ], 'Ticket priority updated.');
    }

    public function assign(
        AssignTicketRequest $request,
        Organization $organization,
        Ticket $ticket
    ): JsonResponse {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $previousAssigneeId = $scopedTicket->assigned_to;
        $assigneeId = (int) $request->integer('assignee_id');

        if (! $request->user()->belongsToOrganization($organization->id)) {
            return $this->error('You do not have access to this organization.', JsonResponse::HTTP_FORBIDDEN);
        }

        $isMember = $organization->users()->where('users.id', $assigneeId)->exists();
        if (! $isMember) {
            return $this->error(
                message: 'Assignee must be a member of this organization.',
                status: JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                errors: ['assignee_id' => ['User is not in this organization.']]
            );
        }

        $scopedTicket->update([
            'assigned_to' => $assigneeId,
        ]);

        TicketAssigned::dispatch($scopedTicket, $request->user()->id);

        TicketUpdated::dispatch(
            $scopedTicket,
            [
                'assigned_to' => [
                    'from' => $previousAssigneeId,
                    'to' => $scopedTicket->assigned_to,
                ],
            ],
            $request->user()->id
        );

        $assignee = \App\Models\User::find($assigneeId);
        app(\App\Services\AuditLogger::class)->log(
            organizationId: $organization->id,
            userId: $request->user()->id,
            event: 'assigned_agent_changed',
            targetType: 'Ticket',
            targetId: $scopedTicket->id,
            metadata: [
                'previous_assignee_id' => $previousAssigneeId,
                'new_assignee_id' => $scopedTicket->assigned_to,
                'new_assignee_name' => $assignee?->name,
            ]
        );

        return $this->success([
            'id' => $scopedTicket->id,
            'assigned_to' => $scopedTicket->assigned_to,
        ], 'Ticket assigned successfully.');
    }

    public function addNote(
        StoreTicketNoteRequest $request,
        Organization $organization,
        Ticket $ticket
    ): JsonResponse {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $this->assertAgentCanAccessTicket($request, $organization, $scopedTicket);

        $note = TicketNote::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $scopedTicket->id,
            'user_id' => $request->user()->id,
            'note' => $request->string('note')->value(),
            'is_private' => $request->boolean('is_private', true),
        ]);

        return $this->success([
            'id' => $note->id,
            'ticket_id' => $note->ticket_id,
            'note' => $note->note,
            'is_private' => $note->is_private,
            'created_at' => $note->created_at,
        ], 'Internal note added.', JsonResponse::HTTP_CREATED);
    }

    public function addMessage(
        StoreTicketMessageRequest $request,
        Organization $organization,
        Ticket $ticket
    ): JsonResponse {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $this->assertAgentCanAccessTicket($request, $organization, $scopedTicket);

        $senderType = $request->string('sender_type')->value();

        $message = TicketMessage::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $scopedTicket->id,
            'sender_type' => $senderType,
            'sender_user_id' => $senderType === TicketMessageSenderTypes::AGENT ? $request->user()->id : null,
            'body' => $request->string('body')->value(),
            'is_ai_generated' => false,
        ]);

        $scopedTicket->customer?->update([
            'last_contacted_at' => now(),
        ]);

        TicketMessageCreated::dispatch($scopedTicket, $message, $request->user()->id);

        return $this->success([
            'id' => $message->id,
            'ticket_id' => $message->ticket_id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'created_at' => $message->created_at,
        ], 'Ticket message added.', JsonResponse::HTTP_CREATED);
    }

    private function resolveScopedTicket(Organization $organization, Ticket $ticket): Ticket
    {
        if ((int) $ticket->organization_id !== (int) $organization->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Ticket not found in this organization.',
                'errors' => [],
            ], JsonResponse::HTTP_NOT_FOUND));
        }

        return $ticket;
    }

    private function assertAgentCanAccessTicket(Request $request, Organization $organization, Ticket $ticket): void
    {
        $role = $request->user()->organizationRole($organization->id);

        if ($role === OrganizationRoles::AGENT && (int) $ticket->assigned_to !== (int) $request->user()->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Agents can only access assigned tickets.',
                'errors' => [],
            ], JsonResponse::HTTP_FORBIDDEN));
        }
    }

    public function classify(Request $request, Organization $organization, Ticket $ticket): JsonResponse
    {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $this->assertAgentCanAccessTicket($request, $organization, $scopedTicket);

        // Dispatches/runs job synchronously for immediate UI feedback
        $job = new \App\Jobs\ClassifyTicketJob($scopedTicket);
        app()->call([$job, 'handle']);

        return $this->success([
            'id' => $scopedTicket->id,
            'ai_category' => $scopedTicket->ai_category,
            'ai_sentiment' => $scopedTicket->ai_sentiment,
            'ai_priority' => $scopedTicket->ai_priority,
            'ai_confidence' => $scopedTicket->ai_confidence,
            'category' => $scopedTicket->category,
            'priority' => $scopedTicket->priority,
        ], 'Ticket classified successfully.');
    }

    public function updateCategory(Request $request, Organization $organization, Ticket $ticket): JsonResponse
    {
        $scopedTicket = $this->resolveScopedTicket($organization, $ticket);
        $this->assertAgentCanAccessTicket($request, $organization, $scopedTicket);

        $request->validate([
            'category' => 'nullable|string|max:255',
        ]);

        $previousCategory = $scopedTicket->category;

        $scopedTicket->update([
            'category' => $request->string('category')->value() ?: null,
        ]);

        TicketUpdated::dispatch(
            $scopedTicket,
            [
                'category' => [
                    'from' => $previousCategory,
                    'to' => $scopedTicket->category,
                ],
            ],
            $request->user()->id
        );

        return $this->success([
            'id' => $scopedTicket->id,
            'category' => $scopedTicket->category,
        ], 'Ticket category updated.');
    }
}
