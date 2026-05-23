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

    public function test_farm_store_creates_sinric_home_when_user_has_token(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
                'home' => [
                    'id' => 'sinric-home-123',
                    'name' => 'Farm1',
                    'imageUrl' => 'https://example.com/farm.png',
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/farms', [
            'name' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'imageUrl' => 'https://example.com/farm.png',
        ])
            ->assertCreated()
            ->assertJsonPath('data.location', 'Farm1')
            ->assertJsonPath('data.timezone', 'Asia/Manila')
            ->assertJsonPath('data.external_provider', 'sinric')
            ->assertJsonPath('data.external_home_id', 'sinric-home-123');

        $this->assertDatabaseHas('farms', [
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/homes'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token')
                && $request['name'] === 'Farm1'
                && $request['imageUrl'] === 'https://example.com/farm.png';
        });
    }

    public function test_farm_show_refreshes_linked_sinric_home(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/homes/sinric-home-123' => Http::response([
                'success' => true,
                'home' => [
                    'id' => 'sinric-home-123',
                    'name' => 'Farm1 Updated',
                    'timeZone' => 'Asia/Manila',
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'UTC',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/farms/{$farm->id}")
            ->assertOk()
            ->assertJsonPath('data.location', 'Farm1 Updated')
            ->assertJsonPath('data.timezone', 'Asia/Manila');

        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'location' => 'Farm1 Updated',
            'timezone' => 'Asia/Manila',
        ]);
    }

    public function test_farm_update_updates_linked_sinric_home(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
            'external_metadata' => [
                'id' => 'sinric-home-123',
                'name' => 'Farm1',
            ],
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/farms/{$farm->id}", [
            'location' => 'Farm1 Updated',
            'imageUrl' => 'https://example.com/farm-updated.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.location', 'Farm1 Updated')
            ->assertJsonPath('data.external_metadata.imageUrl', 'https://example.com/farm-updated.png');

        $this->assertDatabaseHas('farms', [
            'id' => $farm->id,
            'location' => 'Farm1 Updated',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/homes'
                && $request->method() === 'PUT'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token')
                && $request['id'] === 'sinric-home-123'
                && $request['name'] === 'Farm1 Updated'
                && $request['imageUrl'] === 'https://example.com/farm-updated.png';
        });
    }

    public function test_farm_destroy_deletes_linked_sinric_home(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/homes' => Http::response([
                'success' => true,
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/farms/{$farm->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Farm deleted successfully');

        $this->assertDatabaseMissing('farms', ['id' => $farm->id]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/homes'
                && $request->method() === 'DELETE'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token')
                && $request['id'] === 'sinric-home-123';
        });
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

    public function test_hog_pen_index_syncs_sinric_rooms_for_authenticated_user(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

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
                    [
                        'id' => 'sinric-room-456',
                        'name' => 'sta. felomina',
                        'description' => '5 hog capacity',
                        'home' => [
                            'id' => 'sinric-home-123',
                            'name' => 'Farm1',
                        ],
                        'devices' => [],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/hog-pens')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Small Cage',
                'capacity' => 2,
                'external_provider' => 'sinric',
                'external_room_id' => 'sinric-room-123',
            ])
            ->assertJsonFragment([
                'name' => 'sta. felomina',
                'capacity' => 5,
                'external_provider' => 'sinric',
                'external_room_id' => 'sinric-room-456',
            ]);

        $farm = Farms::query()->where('external_home_id', 'sinric-home-123')->firstOrFail();

        $this->assertDatabaseHas('hog_pens', [
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'capacity' => 2,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
        ]);
        $this->assertDatabaseHas('hog_pens', [
            'farm_id' => $farm->id,
            'name' => 'sta. felomina',
            'capacity' => 5,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-456',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/rooms'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token');
        });
    }

    public function test_repeated_hog_pen_index_sync_updates_sinric_room_without_duplicates(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Old Room Name',
            'capacity' => 1,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
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
            'https://api.sinric.pro/api/v1/rooms' => Http::response([
                'success' => true,
                'rooms' => [
                    [
                        'id' => 'sinric-room-123',
                        'name' => 'Small Cage Updated',
                        'description' => '3 hog capacity',
                        'home' => [
                            'id' => 'sinric-home-123',
                        ],
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/hog-pens')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Small Cage Updated')
            ->assertJsonPath('data.0.capacity', 3);

        $this->assertDatabaseCount('hog_pens', 1);
        $this->assertDatabaseHas('hog_pens', [
            'farm_id' => $farm->id,
            'name' => 'Small Cage Updated',
            'capacity' => 3,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
        ]);
    }

    public function test_sinric_room_sync_remains_scoped_to_authenticated_user(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        $user = User::factory()->create([
            'access_token' => 'user-sinric-token',
        ]);
        $otherUser = User::factory()->create();
        $otherFarm = Farms::query()->create([
            'user_id' => $otherUser->id,
            'location' => 'Other Farm',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        HogPens::query()->create([
            'farm_id' => $otherFarm->id,
            'name' => 'Other User Room',
            'capacity' => 9,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
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
            'https://api.sinric.pro/api/v1/rooms' => Http::response([
                'success' => true,
                'rooms' => [
                    [
                        'id' => 'sinric-room-123',
                        'name' => 'Small Cage',
                        'description' => '2 hog capacity',
                        'home' => [
                            'id' => 'sinric-home-123',
                        ],
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/hog-pens')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Small Cage')
            ->assertJsonMissing(['name' => 'Other User Room']);

        $this->assertDatabaseCount('hog_pens', 2);
        $this->assertDatabaseHas('hog_pens', [
            'name' => 'Small Cage',
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
        ]);
    }

    public function test_hog_pen_store_creates_sinric_room_for_linked_farm(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/rooms' => Http::response([
                'success' => true,
                'room' => [
                    'id' => 'sinric-room-123',
                    'name' => 'Small Cage',
                    'imageUrl' => 'https://example.com/room.png',
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/hog-pens', [
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'description' => '2 hog capacity',
            'imageUrl' => 'https://example.com/room.png',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Small Cage')
            ->assertJsonPath('data.capacity', 2)
            ->assertJsonPath('data.status', 1)
            ->assertJsonPath('data.external_provider', 'sinric')
            ->assertJsonPath('data.external_room_id', 'sinric-room-123')
            ->assertJsonPath('data.external_metadata.description', '2 hog capacity');

        $this->assertDatabaseHas('hog_pens', [
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'capacity' => 2,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/rooms'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token')
                && $request['name'] === 'Small Cage'
                && $request['homeId'] === 'sinric-home-123'
                && $request['description'] === '2 hog capacity'
                && $request['imageUrl'] === 'https://example.com/room.png';
        });
    }

    public function test_hog_pen_update_updates_linked_sinric_room(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/rooms' => Http::response([
                'success' => true,
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);
        $hogPen = HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'capacity' => 2,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
            'external_metadata' => [
                'id' => 'sinric-room-123',
                'description' => '2 hog capacity',
            ],
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/hog-pens/{$hogPen->id}", [
            'name' => 'Small Cage Updated',
            'description' => '3 hog capacity',
            'imageUrl' => 'https://example.com/room-updated.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Small Cage Updated')
            ->assertJsonPath('data.capacity', 2)
            ->assertJsonPath('data.external_metadata.description', '3 hog capacity')
            ->assertJsonPath('data.external_metadata.imageUrl', 'https://example.com/room-updated.png');

        $this->assertDatabaseHas('hog_pens', [
            'id' => $hogPen->id,
            'name' => 'Small Cage Updated',
            'capacity' => 2,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/rooms'
                && $request->method() === 'PUT'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token')
                && $request['id'] === 'sinric-room-123'
                && $request['name'] === 'Small Cage Updated'
                && $request['homeId'] === 'sinric-home-123'
                && $request['description'] === '3 hog capacity'
                && $request['imageUrl'] === 'https://example.com/room-updated.png';
        });
    }

    public function test_hog_pen_destroy_deletes_linked_sinric_room(): void
    {
        config()->set('services.sinric.base_url', 'https://api.sinric.pro/api/v1');

        Http::fake([
            'https://api.sinric.pro/api/v1/rooms/sinric-room-123' => Http::response([
                'success' => true,
            ]),
        ]);

        $user = User::factory()->create([
            'access_token' => 'sinric-access-token',
        ]);
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123',
        ]);
        $hogPen = HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'capacity' => 2,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/hog-pens/{$hogPen->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Hog Pen deleted successfully');

        $this->assertDatabaseMissing('hog_pens', ['id' => $hogPen->id]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.sinric.pro/api/v1/rooms/sinric-room-123'
                && $request->method() === 'DELETE'
                && $request->hasHeader('Authorization', 'Bearer sinric-access-token');
        });
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
