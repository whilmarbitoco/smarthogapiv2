<?php

namespace Tests\Feature;

use App\Models\Farms;
use App\Models\HogPens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');
    }

    public function test_login_syncs_sinric_user_and_issues_sanctum_token(): void
    {
        Http::fake([
            'https://api.sinric.pro/api/v1/auth' => Http::response([
                'success' => true,
                'accessToken' => 'sinric-access-token',
                'refreshToken' => 'sinric-refresh-token',
                'account' => [
                    'id' => 'sinric-user-123',
                    'email' => 'owner@example.com',
                    'name' => 'Farm Owner',
                    'timeZone' => 'Asia/Manila',
                ],
            ]),
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
                'homes' => [
                    [
                        'id' => 'sinric-home-123',
                        'name' => 'Farm1',
                        'rooms' => [],
                    ],
                ],
            ]),
            'https://api.sinric.pro/api/v1/rooms' => Http::response([
                'success' => true,
                'rooms' => [
                    [
                        'id' => 'sinric-room-123',
                        'name' => 'Small Cage',
                        'description' => '2 hog capacity',
                        'home' => [
                            'id' => 'sinric-home-123',
                            'name' => 'Farm1',
                        ],
                        'devices' => [],
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'sinric-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User logged in successfully.')
            ->assertJsonPath('data.user.email', 'owner@example.com')
            ->assertJsonMissing(['accessToken' => 'sinric-access-token'])
            ->assertJsonMissing(['refreshToken' => 'sinric-refresh-token']);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/auth'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('owner@example.com:sinric-password'))
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && $request['client_id'] === 'android-app'
                && ! isset($request['email'], $request['password']);
        });

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertSame('Farm Owner', $user->name);
        $this->assertSame('sinric-user-123', $user->sinric_user_id);
        $this->assertSame('sinric-access-token', $user->access_token);
        $this->assertSame('sinric-refresh-token', $user->refresh_token);
        $this->assertNotNull($user->last_login_at);

        $farm = Farms::query()->where('external_home_id', 'sinric-home-123')->firstOrFail();

        $this->assertSame($user->id, $farm->user_id);
        $this->assertSame('sinric', $farm->external_provider);
        $this->assertSame('Farm1', $farm->location);
        $this->assertSame('Asia/Manila', $farm->timezone);
        $this->assertSame('Farm1', $farm->external_metadata['name']);

        $hogPen = HogPens::query()->where('external_room_id', 'sinric-room-123')->firstOrFail();

        $this->assertSame($farm->id, $hogPen->farm_id);
        $this->assertSame('sinric', $hogPen->external_provider);
        $this->assertSame('Small Cage', $hogPen->name);
        $this->assertSame(2, $hogPen->capacity);
        $this->assertSame(1, $hogPen->status);
    }

    public function test_login_rejects_mismatched_sinric_email(): void
    {
        Http::fake([
            'https://api.sinric.pro/api/v1/auth' => Http::response([
                'accessToken' => 'sinric-access-token',
                'account' => [
                    'email' => 'different@example.com',
                ],
            ]),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'sinric-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid Sinric response.');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        Http::fake([
            'https://api.sinric.pro/api/v1/auth' => Http::response([
                'accessToken' => 'sinric-access-token',
                'refreshToken' => 'sinric-refresh-token',
                'account' => [
                    'id' => 'sinric-user-123',
                    'email' => 'owner@example.com',
                    'name' => 'Farm Owner',
                ],
            ]),
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
                'homes' => [],
            ]),
            'https://api.sinric.pro/api/v1/rooms' => Http::response([
                'success' => true,
                'rooms' => [],
            ]),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'sinric-password',
        ])->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@example.com');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'User logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_errors_use_standard_json_shape(): void
    {
        $this->getJson('/api/v1/missing-route')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Resource not found',
            ]);
    }
}
