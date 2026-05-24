<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'password' => $request->string('password')->value(),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $user,
        ], 'Registration successful.', JsonResponse::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->value())
            ->first();

        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            return $this->error(
                message: 'Invalid credentials.',
                status: JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                errors: ['email' => ['The provided credentials are incorrect.']]
            );
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $user,
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success(null, 'Logout successful.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'organizations:id,name,slug,join_code,owner_user_id',
        ]);

        $organizations = $user->organizations->map(function ($organization) {
            return [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'join_code' => $organization->join_code,
                'role' => $organization->pivot->role,
            ];
        })->values();

        return $this->success([
            'user' => $user->only(['id', 'name', 'email']),
            'organizations' => $organizations,
        ], 'Authenticated user profile.');
    }
}
