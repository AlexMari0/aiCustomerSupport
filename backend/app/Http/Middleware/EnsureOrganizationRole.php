<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $organizationId = $request->attributes->get('organization_id');

        if ($organizationId === null) {
            $organization = $request->route('organization');
            if ($organization instanceof Organization) {
                $organizationId = (int) $organization->id;
            } elseif ($organization !== null) {
                $organizationId = (int) $organization;
            }
        }

        if ($organizationId === null || $organizationId === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! $request->user()->hasOrganizationRole((int) $organizationId, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient role permissions for this organization.',
                'errors' => [],
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
