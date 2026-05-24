<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketNote;
use App\Support\TicketMessageSenderTypes;
use App\Support\TicketStatuses;
use App\Events\Tickets\TicketUpdated;
use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketMessageCreated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowEngine
{
    /**
     * Trigger and process automation rules matching the trigger event type.
     */
    public function trigger(string $triggerType, Ticket $ticket): void
    {
        // 1. Fetch active rules for the organization matching this trigger
        $rules = AutomationRule::query()
            ->where('organization_id', $ticket->organization_id)
            ->where('trigger_type', $triggerType)
            ->where('is_active', true)
            ->with(['conditions', 'actions'])
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            $this->evaluateAndExecute($rule, $ticket);
        }
    }

    /**
     * Evaluate rule conditions and execute actions if conditions are satisfied.
     */
    protected function evaluateAndExecute(AutomationRule $rule, Ticket $ticket): void
    {
        $logs = [
            'rule_name' => $rule->name,
            'trigger_type' => $rule->trigger_type,
            'evaluated_at' => now()->toIso8601String(),
            'conditions_evaluated' => [],
            'actions_executed' => [],
        ];

        // 1. Evaluate Conditions
        $conditionsSatisfied = true;
        $fieldMap = [
            'category' => 'category',
            'priority' => 'priority',
            'sentiment' => 'ai_sentiment',
            'status' => 'status',
        ];

        foreach ($rule->conditions as $cond) {
            $mappedField = $fieldMap[$cond->field] ?? $cond->field;
            $ticketValue = $ticket->{$mappedField};

            // Case-insensitive comparisons
            $isMatch = Str::lower((string) $ticketValue) === Str::lower((string) $cond->value);
            
            $logs['conditions_evaluated'][] = [
                'field' => $cond->field,
                'expected' => $cond->value,
                'actual' => $ticketValue,
                'matched' => $isMatch,
            ];

            if (!$isMatch) {
                $conditionsSatisfied = false;
            }
        }

        // If conditions are not satisfied, we skip rule execution and do not log run (keeping list clean)
        if (!$conditionsSatisfied) {
            return;
        }

        $actorUserId = (int) ($rule->created_by ?: $ticket->created_by ?: 1);

        // 2. Execute Actions
        try {
            foreach ($rule->actions as $action) {
                $executed = $this->executeAction($action, $ticket, $actorUserId);
                $logs['actions_executed'][] = [
                    'action_type' => $action->action_type,
                    'action_value' => $action->action_value,
                    'result' => $executed,
                ];
            }

            // 3. Log Success Run
            AutomationRun::query()->create([
                'organization_id' => $ticket->organization_id,
                'automation_rule_id' => $rule->id,
                'ticket_id' => $ticket->id,
                'status' => 'success',
                'logs' => $logs,
            ]);

            app(\App\Services\AuditLogger::class)->log(
                organizationId: $ticket->organization_id,
                userId: $actorUserId > 0 ? $actorUserId : null,
                event: 'workflow_executed',
                targetType: 'Ticket',
                targetId: $ticket->id,
                metadata: [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'status' => 'success',
                    'actions_executed' => $logs['actions_executed'] ?? [],
                ]
            );

            Log::info("Automation rule '{$rule->name}' successfully executed on Ticket #{$ticket->id}.");
        } catch (\Exception $e) {
            $logs['error'] = $e->getMessage();
            
            AutomationRun::query()->create([
                'organization_id' => $ticket->organization_id,
                'automation_rule_id' => $rule->id,
                'ticket_id' => $ticket->id,
                'status' => 'failed',
                'logs' => $logs,
            ]);

            app(\App\Services\AuditLogger::class)->log(
                organizationId: $ticket->organization_id,
                userId: $actorUserId > 0 ? $actorUserId : null,
                event: 'workflow_executed',
                targetType: 'Ticket',
                targetId: $ticket->id,
                metadata: [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]
            );

            Log::error("Automation rule '{$rule->name}' failed on Ticket #{$ticket->id}: " . $e->getMessage());
        }
    }

    /**
     * Execute a specific automation action on the ticket.
     */
    protected function executeAction($action, Ticket $ticket, int $actorUserId): string
    {
        switch ($action->action_type) {
            case 'assign_to_agent':
                $previousAssigneeId = $ticket->assigned_to;
                $newAssigneeId = (int) $action->action_value;
                $ticket->update(['assigned_to' => $newAssigneeId]);
                
                TicketAssigned::dispatch($ticket, $actorUserId);
                TicketUpdated::dispatch($ticket, [
                    'assigned_to' => [
                        'from' => $previousAssigneeId,
                        'to' => $ticket->assigned_to,
                    ]
                ], $actorUserId);
                return "Assigned to user ID {$newAssigneeId}";

            case 'change_priority':
                $previousPriority = $ticket->priority;
                $newPriority = $action->action_value;
                $ticket->update(['priority' => $newPriority]);

                TicketUpdated::dispatch($ticket, [
                    'priority' => [
                        'from' => $previousPriority,
                        'to' => $ticket->priority,
                    ]
                ], $actorUserId);
                return "Priority changed to {$newPriority}";

            case 'add_internal_note':
                TicketNote::query()->create([
                    'organization_id' => $ticket->organization_id,
                    'ticket_id' => $ticket->id,
                    'user_id' => null, // Automation bot
                    'note' => $action->action_value,
                    'is_private' => true,
                ]);
                return "Internal note added";

            case 'send_notification':
                $message = TicketMessage::query()->create([
                    'organization_id' => $ticket->organization_id,
                    'ticket_id' => $ticket->id,
                    'sender_type' => TicketMessageSenderTypes::AGENT,
                    'sender_user_id' => null, // System bot
                    'body' => '🤖 [System Automation]: ' . $action->action_value,
                    'is_ai_generated' => true,
                ]);
                TicketMessageCreated::dispatch($ticket, $message, $actorUserId);
                return "System message notification created";

            case 'mark_as_pending':
                $previousStatus = $ticket->status;
                $ticket->update(['status' => TicketStatuses::PENDING]);

                TicketUpdated::dispatch($ticket, [
                    'status' => [
                        'from' => $previousStatus,
                        'to' => $ticket->status,
                    ]
                ], $actorUserId);
                return "Status changed to pending";

            default:
                throw new \InvalidArgumentException("Invalid action type: {$action->action_type}");
        }
    }
}
