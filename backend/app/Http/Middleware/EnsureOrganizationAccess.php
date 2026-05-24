<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');
        $organizationId = null;

        if ($organization instanceof Organization) {
            $organizationId = (int) $organization->id;
        } elseif ($organization !== null) {
            $organizationId = (int) $organization;
        }

        if ($organizationId === null || $organizationId === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! $request->user()->belongsToOrganization($organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this organization.',
                'errors' => [],
            ], Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('organization_id', $organizationId);

        return $next($request);
    }
}
