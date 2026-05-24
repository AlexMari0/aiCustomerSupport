<?php

namespace App\Http\Controllers\Api\V1\Automations;

use App\Http\Controllers\Api\ApiController;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Organization;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AutomationController extends ApiController
{
    /**
     * Display a listing of the automation rules.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $rules = AutomationRule::query()
            ->where('organization_id', $organization->id)
            ->with(['conditions', 'actions'])
            ->latest()
            ->get();

        return $this->success($rules, 'Automation rules retrieved.');
    }

    /**
     * Store a newly created automation rule.
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'trigger_type' => 'required|string|in:ticket_created,ticket_updated,priority_changed,category_detected,sentiment_detected',
            'conditions' => 'nullable|array',
            'conditions.*.field' => 'required|string|in:category,priority,sentiment,status',
            'conditions.*.value' => 'required|string|max:255',
            'actions' => 'required|array|min:1',
            'actions.*.action_type' => 'required|string|in:assign_to_agent,change_priority,add_internal_note,send_notification,mark_as_pending',
            'actions.*.action_value' => 'nullable|string',
        ]);

        $rule = DB::transaction(function () use ($request, $organization): AutomationRule {
            $rule = AutomationRule::query()->create([
                'organization_id' => $organization->id,
                'name' => $request->string('name')->value(),
                'trigger_type' => $request->string('trigger_type')->value(),
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            if ($request->has('conditions')) {
                foreach ($request->input('conditions') as $cond) {
                    $rule->conditions()->create([
                        'field' => $cond['field'],
                        'operator' => 'equals',
                        'value' => $cond['value'],
                    ]);
                }
            }

            foreach ($request->input('actions') as $act) {
                $rule->actions()->create([
                    'action_type' => $act['action_type'],
                    'action_value' => $act['action_value'] ?? null,
                ]);
            }

            return $rule;
        });

        $rule->load(['conditions', 'actions']);

        return $this->success($rule, 'Automation rule created successfully.', JsonResponse::HTTP_CREATED);
    }

    /**
     * Toggle the active status of a rule.
     */
    public function toggle(Request $request, Organization $organization, AutomationRule $rule): JsonResponse
    {
        if ((int) $rule->organization_id !== (int) $organization->id) {
            return $this->error('Rule not found in this organization.', JsonResponse::HTTP_NOT_FOUND);
        }

        $rule->update([
            'is_active' => !$rule->is_active,
        ]);

        return $this->success($rule, 'Rule active status toggled.');
    }

    /**
     * Remove the specified rule from storage.
     */
    public function destroy(Request $request, Organization $organization, AutomationRule $rule): JsonResponse
    {
        if ((int) $rule->organization_id !== (int) $organization->id) {
            return $this->error('Rule not found in this organization.', JsonResponse::HTTP_NOT_FOUND);
        }

        $rule->delete();

        return $this->success(null, 'Automation rule deleted successfully.');
    }

    /**
     * Display a listing of automation runs for a specific ticket.
     */
    public function ticketRuns(Request $request, Organization $organization, Ticket $ticket): JsonResponse
    {
        if ((int) $ticket->organization_id !== (int) $organization->id) {
            return $this->error('Ticket not found in this organization.', JsonResponse::HTTP_NOT_FOUND);
        }

        $runs = AutomationRun::query()
            ->where('organization_id', $organization->id)
            ->where('ticket_id', $ticket->id)
            ->with(['rule:id,name'])
            ->latest()
            ->get();

        return $this->success($runs, 'Automation runs for ticket retrieved.');
    }
}
