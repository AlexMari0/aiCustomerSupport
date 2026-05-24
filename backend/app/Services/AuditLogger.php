<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Log a workspace audit event.
     */
    public function log(
        int $organizationId,
        ?int $userId,
        string $event,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $metadata = null
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
