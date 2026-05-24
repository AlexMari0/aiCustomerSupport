<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Organizations\JoinOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRoleRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationMembershipController extends ApiController
{
    public function join(JoinOrganizationRequest $request): JsonResponse
    {
        $joinCode = strtoupper($request->string('join_code')->value());

        $organization = Organization::query()
            ->where('join_code', $joinCode)
            ->first();

        if ($organization === null) {
            return $this->error(
                message: 'Join code is invalid.',
                status: JsonResponse::HTTP_NOT_FOUND,
                errors: ['join_code' => ['Organization not found for this join code.']]
            );
        }

        if ($request->user()->belongsToOrganization($organization->id)) {
            return $this->error(
                message: 'You are already a member of this organization.',
                status: JsonResponse::HTTP_CONFLICT
            );
        }

        $organization->users()->attach($request->user()->id, [
            'role' => OrganizationRoles::AGENT,
        ]);

        return $this->success([
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'role' => OrganizationRoles::AGENT,
        ], 'Joined organization successfully.');
    }

    public function members(Organization $organization): JsonResponse
    {
        $members = $organization->users()
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                ];
            })
            ->values();

        return $this->success($members, 'Organization members retrieved.');
    }

    public function updateRole(
        UpdateOrganizationMemberRoleRequest $request,
        Organization $organization,
        User $member
    ): JsonResponse {
        if (! $organization->users()->where('users.id', $member->id)->exists()) {
            return $this->error(
                message: 'User is not a member of this organization.',
                status: JsonResponse::HTTP_NOT_FOUND
            );
        }

        if ((int) $organization->owner_user_id === (int) $member->id) {
            return $this->error(
                message: 'Owner role cannot be changed from this endpoint.',
                status: JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $organization->users()->updateExistingPivot($member->id, [
            'role' => $request->string('role')->value(),
        ]);

        return $this->success([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => $request->string('role')->value(),
        ], 'Member role updated.');
    }
}
