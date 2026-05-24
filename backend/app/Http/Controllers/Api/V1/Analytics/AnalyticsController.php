<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\AiSuggestion;
use App\Models\AutomationRun;
use App\Support\OrganizationRoles;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends ApiController
{
    /**
     * Retrieve workspace analytics data for owners and admins.
     * GET /api/v1/organizations/{organization}/analytics
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $organizationId = $organization->id;

        // 1. Total Tickets count
        $totalTickets = Ticket::query()->where('organization_id', $organizationId)->count();

        // 2. Tickets by Status count
        $ticketsByStatus = [
            'open' => Ticket::query()->where('organization_id', $organizationId)->where('status', TicketStatuses::OPEN)->count(),
            'pending' => Ticket::query()->where('organization_id', $organizationId)->where('status', TicketStatuses::PENDING)->count(),
            'resolved' => Ticket::query()->where('organization_id', $organizationId)->where('status', TicketStatuses::RESOLVED)->count(),
            'closed' => Ticket::query()->where('organization_id', $organizationId)->where('status', TicketStatuses::CLOSED)->count(),
        ];

        // 3. Average Response Time in Seconds (Calculated in PHP to be database-agnostic between PostgreSQL & SQLite)
        $ticketsWithReplies = Ticket::query()
            ->where('organization_id', $organizationId)
            ->whereHas('messages', function ($q): void {
                $q->where('sender_type', 'agent');
            })
            ->with(['messages' => function ($q): void {
                $q->where('sender_type', 'agent')->orderBy('created_at', 'asc');
            }])
            ->get();

        $totalDiff = 0;
        $countReplies = 0;

        foreach ($ticketsWithReplies as $ticket) {
            $firstAgentReply = $ticket->messages->first();
            if ($firstAgentReply) {
                $diff = $firstAgentReply->created_at->getTimestamp() - $ticket->created_at->getTimestamp();
                $totalDiff += max(0, $diff); // Ensure no negative values from seeder modifications
                $countReplies++;
            }
        }

        $avgResponseTimeSeconds = $countReplies > 0 ? (int) ($totalDiff / $countReplies) : 0;

        // 4. Tickets by Category
        $ticketsByCategoryRaw = Ticket::query()
            ->where('organization_id', $organizationId)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->get();

        $ticketsByCategory = $ticketsByCategoryRaw->map(function ($item) {
            return [
                'category' => $item->category ?? 'unclassified',
                'count' => (int) $item->count,
            ];
        })->toArray();

        // 5. Tickets by Priority
        $ticketsByPriorityRaw = Ticket::query()
            ->where('organization_id', $organizationId)
            ->select('priority', DB::raw('count(*) as count'))
            ->groupBy('priority')
            ->get();

        $ticketsByPriority = $ticketsByPriorityRaw->map(function ($item) {
            return [
                'priority' => $item->priority ?? TicketPriorities::MEDIUM,
                'count' => (int) $item->count,
            ];
        })->toArray();

        // 6. Tickets by Source Channel
        $ticketsBySourceChannelRaw = Ticket::query()
            ->where('organization_id', $organizationId)
            ->select('source_channel', DB::raw('count(*) as count'))
            ->groupBy('source_channel')
            ->get();

        $ticketsBySourceChannel = $ticketsBySourceChannelRaw->map(function ($item) {
            return [
                'source_channel' => $item->source_channel ?? 'web',
                'count' => (int) $item->count,
            ];
        })->toArray();

        // 7. AI Suggested Reply Usage Count
        $aiUsageCount = AiSuggestion::query()->where('organization_id', $organizationId)->count();

        // 8. Workflow Automation Runs Performance
        $automationRuns = [
            'success_count' => AutomationRun::query()->where('organization_id', $organizationId)->where('status', 'success')->count(),
            'failed_count' => AutomationRun::query()->where('organization_id', $organizationId)->where('status', 'failed')->count(),
        ];

        // 9. Team & Agent Workloads
        $agents = $organization->users()
            ->select('users.id', 'users.name', 'users.email')
            ->get()
            ->map(function ($user) use ($organizationId) {
                $totalAssigned = Ticket::query()
                    ->where('organization_id', $organizationId)
                    ->where('assigned_to', $user->id)
                    ->count();

                $resolvedCount = Ticket::query()
                    ->where('organization_id', $organizationId)
                    ->where('assigned_to', $user->id)
                    ->whereIn('status', [TicketStatuses::RESOLVED, TicketStatuses::CLOSED])
                    ->count();

                $role = $user->pivot->role ?? OrganizationRoles::AGENT;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role,
                    'assigned_tickets_count' => $totalAssigned,
                    'resolved_tickets_count' => $resolvedCount,
                ];
            })->toArray();

        // Package analytics payload
        $analytics = [
            'total_tickets' => $totalTickets,
            'tickets_by_status' => $ticketsByStatus,
            'avg_response_time_seconds' => $avgResponseTimeSeconds,
            'tickets_by_category' => $ticketsByCategory,
            'tickets_by_priority' => $ticketsByPriority,
            'tickets_by_source_channel' => $ticketsBySourceChannel,
            'ai_usage_count' => $aiUsageCount,
            'automation_runs' => $automationRuns,
            'agent_performance' => $agents,
        ];

        return $this->success($analytics, 'Analytics data retrieved.');
    }
}
