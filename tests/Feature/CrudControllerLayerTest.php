<?php

namespace Tests\Feature;

use App\Models\Alerts;
use App\Models\Farms;
use App\Models\HogPens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrudControllerLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_crud_owned_farm(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/farms', [
            'location' => 'North Barn',
            'timezone' => 'Asia/Manila',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.location', 'North Barn')
            ->assertJsonPath('data.user_id', $user->id);

        $farmId = $createResponse->json('data.id');

        $this->getJson("/api/v1/farms/{$farmId}")
            ->assertOk()
            ->assertJsonPath('data.id', $farmId);

        $this->patchJson("/api/v1/farms/{$farmId}", [
            'location' => 'South Barn',
        ])
            ->assertOk()
            ->assertJsonPath('data.location', 'South Barn');

        $this->deleteJson("/api/v1/farms/{$farmId}")
            ->assertOk()
            ->assertJsonPath('message', 'Farm deleted successfully');

        $this->assertDatabaseMissing('farms', ['id' => $farmId]);
    }

    public function test_farm_index_only_returns_owned_records_and_cross_user_show_is_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedFarm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Owned Barn',
            'timezone' => 'Asia/Manila',
        ]);
        $otherFarm = Farms::query()->create([
            'user_id' => $otherUser->id,
            'location' => 'Other Barn',
            'timezone' => 'Asia/Manila',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/farms')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownedFarm->id)
            ->assertJsonMissing(['location' => 'Other Barn']);

        $this->getJson("/api/v1/farms/{$otherFarm->id}")
            ->assertForbidden();
    }

    public function test_farm_index_syncs_sinric_homes_for_authenticated_user(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
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
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/farms')
            ->assertOk()
            ->assertJsonPath('data.0.location', 'Farm1')
            ->assertJsonPath('data.0.external_provider', 'sinric')
            ->assertJsonPath('data.0.external_home_id', 'sinric-home-123');

        $this->assertDatabaseHas('farms', [
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => config('app.timezone'),
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/homes'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token');
        });
    }

    public function test_repeated_farm_index_sync_updates_sinric_home_without_duplicates(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);

        Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Old Farm Name',
            'timezone' => 'UTC',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Http::fake([
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
                'homes' => [
                    [
                        'id' => 'sinric-home-123',
                        'name' => 'Farm1 Updated',
                        'timeZone' => 'Asia/Manila',
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/farms')
            ->assertOk()
            ->assertJsonPath('data.0.location', 'Farm1 Updated');

        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseHas('farms', [
            'user_id' => $user->id,
            'location' => 'Farm1 Updated',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);
    }

    public function test_sinric_home_sync_remains_scoped_to_authenticated_user(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        $user = User::factory()->create([
            'access_token' => 'user-sinric-token',
        ]);
        $otherUser = User::factory()->create();

        Farms::query()->create([
            'user_id' => $otherUser->id,
            'location' => 'Other User Farm',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Http::fake([
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
                'homes' => [
                    [
                        'id' => 'sinric-home-123',
                        'name' => 'Farm1',
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/farms')
            ->assertOk()
            ->assertJsonPath('data.0.location', 'Farm1')
            ->assertJsonMissing(['location' => 'Other User Farm']);

        $this->assertDatabaseCount('farms', 2);
        $this->assertDatabaseHas('farms', [
            'user_id' => $user->id,
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);
    }

    public function test_hog_pen_creation_requires_owned_farm(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedFarm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Owned Barn',
            'timezone' => 'Asia/Manila',
        ]);
        $otherFarm = Farms::query()->create([
            'user_id' => $otherUser->id,
            'location' => 'Other Barn',
            'timezone' => 'Asia/Manila',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/hog-pens', [
            'farm_id' => $ownedFarm->id,
            'name' => 'Pen A',
            'capacity' => 30,
            'status' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pen A');

        $this->postJson('/api/v1/hog-pens', [
            'farm_id' => $otherFarm->id,
            'name' => 'Pen B',
            'capacity' => 25,
            'status' => 1,
        ])
            ->assertForbidden();
    }

    public function test_alert_creation_requires_owned_farm_and_pen(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedFarm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Owned Barn',
            'timezone' => 'Asia/Manila',
        ]);
        $otherFarm = Farms::query()->create([
            'user_id' => $otherUser->id,
            'location' => 'Other Barn',
            'timezone' => 'Asia/Manila',
        ]);
        $ownedPen = HogPens::query()->create([
            'farm_id' => $ownedFarm->id,
            'name' => 'Pen A',
            'capacity' => 30,
            'status' => 1,
        ]);
        $otherPen = HogPens::query()->create([
            'farm_id' => $otherFarm->id,
            'name' => 'Pen B',
            'capacity' => 25,
            'status' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/alerts', [
            'farm_id' => $ownedFarm->id,
            'hog_pen_id' => $ownedPen->id,
            'type' => 'temperature',
            'message' => 'Temperature is above threshold.',
            'severity' => 'high',
            'status' => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'temperature');

        $this->postJson('/api/v1/alerts', [
            'farm_id' => $ownedFarm->id,
            'hog_pen_id' => $otherPen->id,
            'type' => 'temperature',
            'message' => 'Cross-user pen should be rejected.',
            'severity' => 'high',
            'status' => 'active',
        ])
            ->assertForbidden();
    }

    public function test_unauthenticated_crud_route_returns_unauthorized(): void
    {
        $this->getJson('/api/v1/farms')
            ->assertUnauthorized();
    }
}
