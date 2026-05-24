<?php

use App\Models\Ticket;
use App\Models\User;
use App\Support\OrganizationRoles;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('organizations.{organizationId}.tickets', function (User $user, int $organizationId): bool {
    return $user->belongsToOrganization($organizationId);
});

Broadcast::channel('organizations.{organizationId}.tickets.{ticketId}', function (User $user, int $organizationId, int $ticketId): bool {
    if (! $user->belongsToOrganization($organizationId)) {
        return false;
    }

    $role = $user->organizationRole($organizationId);

    if ($role !== OrganizationRoles::AGENT) {
        return true;
    }

    return Ticket::query()
        ->where('organization_id', $organizationId)
        ->whereKey($ticketId)
        ->where('assigned_to', $user->id)
        ->exists();
});

Broadcast::channel('users.{id}.assignments', function (User $user, int $id): bool {
    return (int) $user->id === (int) $id;
});
