<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_view_profile_and_logout(): void
    {
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice Agent',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $registerResponse
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'alice@example.com');

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $token = $loginResponse->json('data.token');

        $this->assertIsString($token);

        $meResponse = $this->withToken($token)->getJson('/api/v1/auth/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'alice@example.com');

        $logoutResponse = $this->withToken($token)->postJson('/api/v1/auth/logout');

        $logoutResponse
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, User::where('email', 'alice@example.com')->firstOrFail()->tokens()->count());
    }

    public function test_login_fails_for_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'valid@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'valid@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }
}
