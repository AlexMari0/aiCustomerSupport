<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Models\Organization;
use App\Support\OrganizationRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()
            ->organizations()
            ->select('organizations.id', 'organizations.name', 'organizations.slug', 'organizations.join_code', 'organizations.webhook_token', 'organizations.owner_user_id')
            ->orderBy('organizations.name')
            ->get()
            ->map(function (Organization $organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'join_code' => $organization->join_code,
                    'webhook_token' => $organization->webhook_token,
                    'role' => $organization->pivot->role,
                ];
            })
            ->values();

        return $this->success($organizations, 'Organizations retrieved.');
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $user = $request->user();

        $organization = DB::transaction(function () use ($request, $user): Organization {
            $baseSlug = Str::slug($request->string('name')->value());
            if ($baseSlug === '') {
                $baseSlug = 'workspace';
            }
            $slug = $baseSlug;
            $counter = 1;

            while (Organization::query()->where('slug', $slug)->exists()) {
                $counter++;
                $slug = "{$baseSlug}-{$counter}";
            }

            do {
                $joinCode = Str::upper(Str::random(10));
            } while (Organization::query()->where('join_code', $joinCode)->exists());

            $organization = Organization::query()->create([
                'name' => $request->string('name')->value(),
                'slug' => $slug,
                'join_code' => $joinCode,
                'owner_user_id' => $user->id,
            ]);

            $organization->users()->syncWithoutDetaching([
                $user->id => ['role' => OrganizationRoles::OWNER],
            ]);

            return $organization;
        });

        return $this->success([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'join_code' => $organization->join_code,
            'webhook_token' => $organization->webhook_token,
            'role' => OrganizationRoles::OWNER,
        ], 'Organization created.', JsonResponse::HTTP_CREATED);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        return $this->success([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'join_code' => $organization->join_code,
            'webhook_token' => $organization->webhook_token,
            'owner_user_id' => $organization->owner_user_id,
            'role' => $request->user()->organizationRole($organization->id),
        ], 'Organization detail retrieved.');
    }
}
