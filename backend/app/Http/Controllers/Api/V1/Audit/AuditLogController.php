<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Api\ApiController;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    /**
     * Retrieve the audit logs list for owners and admins.
     * GET /api/v1/organizations/{organization}/audit-logs
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $auditLogs = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email')
            ->latest()
            ->get()
            ->map(function (AuditLog $log) {
                return [
                    'id' => $log->id,
                    'organization_id' => $log->organization_id,
                    'user_id' => $log->user_id,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ] : null,
                    'event' => $log->event,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'metadata' => $log->metadata,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at,
                ];
            });

        return $this->success($auditLogs, 'Audit logs retrieved successfully.');
    }
}
