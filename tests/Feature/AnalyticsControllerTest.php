<?php

namespace Tests\Feature;

use App\Models\Alerts;
use App\Models\DailyFarmReports;
use App\Models\Farms;
use App\Models\FeedingLogs;
use App\Models\Feeders;
use App\Models\Hogs;
use App\Models\HogPens;
use App\Models\IotDevices;
use App\Models\User;
use App\Models\WebHookLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_returns_owned_counts_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $owned = $this->createFarmGraph($user);
        $other = $this->createFarmGraph($otherUser);

        Hogs::query()->create([
            'hog_pen_id' => $owned['pen']->id,
            'ear_tag_id' => 'HOG-001',
            'breed' => 'Large White',
            'gender' => 'female',
            'current_age' => 120,
            'weight_current' => 42.5,
        ]);
        Hogs::query()->create([
            'hog_pen_id' => $other['pen']->id,
            'ear_tag_id' => 'HOG-OTHER',
            'breed' => 'Large White',
            'gender' => 'male',
            'current_age' => 110,
            'weight_current' => 41,
        ]);

        Alerts::query()->create([
            'farm_id' => $owned['farm']->id,
            'hog_pen_id' => $owned['pen']->id,
            'type' => 'temperature',
            'message' => 'Hot',
            'severity' => 'high',
            'status' => 'active',
        ]);
        Alerts::query()->create([
            'farm_id' => $other['farm']->id,
            'hog_pen_id' => $other['pen']->id,
            'type' => 'temperature',
            'message' => 'Other',
            'severity' => 'high',
            'status' => 'active',
        ]);

        FeedingLogs::query()->create([
            'feeder_id' => $owned['feeder']->id,
            'pen_id' => $owned['pen']->id,
            'feed_amount_given' => 12.5,
            'triggered' => 'auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        FeedingLogs::query()->create([
            'feeder_id' => $other['feeder']->id,
            'pen_id' => $other['pen']->id,
            'feed_amount_given' => 99,
            'triggered' => 'auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WebHookLogs::query()->create([
            'farm_id' => $owned['farm']->id,
            'url' => 'https://example.com',
            'event' => 'device.offline',
            'payload' => [],
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics/overview')
            ->assertOk()
            ->assertJsonPath('data.totals.farms', 1)
            ->assertJsonPath('data.totals.hog_pens', 1)
            ->assertJsonPath('data.totals.hogs', 1)
            ->assertJsonPath('data.totals.iot_devices', 2)
            ->assertJsonPath('data.devices.online', 1)
            ->assertJsonPath('data.devices.offline', 1)
            ->assertJsonPath('data.alerts.active', 1)
            ->assertJsonPath('data.feeding_today.log_count', 1)
            ->assertJsonPath('data.feeding_today.total_feed_amount', 12.5)
            ->assertJsonPath('data.webhooks.recent_failures', 1);
    }

    public function test_device_status_groups_devices_and_lists_offline_devices(): void
    {
        $user = User::factory()->create();
        $owned = $this->createFarmGraph($user);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/analytics/devices/status');

        $response
            ->assertOk()
            ->assertJsonPath('data.by_status.online', 1)
            ->assertJsonPath('data.by_status.offline', 1)
            ->assertJsonPath('data.sinric_online.online', 1)
            ->assertJsonPath('data.sinric_online.offline', 1)
            ->assertJsonPath('data.offline_devices.0.name', 'GROWER')
            ->assertJsonPath('data.offline_devices.0.hog_pen.id', $owned['pen']->id);

        $this->assertSame(2, $response->json('data.by_type')['sinric.devices.types.SWITCH']);
    }

    public function test_farm_summary_returns_owned_farm_aggregates(): void
    {
        $user = User::factory()->create();
        $owned = $this->createFarmGraph($user);

        Alerts::query()->create([
            'farm_id' => $owned['farm']->id,
            'hog_pen_id' => $owned['pen']->id,
            'type' => 'temperature',
            'message' => 'Hot',
            'severity' => 'high',
            'status' => 'active',
        ]);
        FeedingLogs::query()->create([
            'feeder_id' => $owned['feeder']->id,
            'pen_id' => $owned['pen']->id,
            'feed_amount_given' => 7,
            'triggered' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DailyFarmReports::query()->create([
            'farm_id' => $owned['farm']->id,
            'total_feed_consumed' => 7,
            'total_hogs' => 1,
            'avg_weight' => 42,
            'mortality_count' => 0,
            'report_date' => now(),
        ]);
        WebHookLogs::query()->create([
            'farm_id' => $owned['farm']->id,
            'url' => 'https://example.com',
            'event' => 'device.status',
            'payload' => ['ok' => true],
            'status' => 'sent',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/analytics/farms/{$owned['farm']->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.farm.id', $owned['farm']->id)
            ->assertJsonPath('data.totals.hog_pens', 1)
            ->assertJsonPath('data.totals.iot_devices', 2)
            ->assertJsonPath('data.devices.by_status.online', 1)
            ->assertJsonPath('data.alerts.by_severity.high', 1)
            ->assertJsonPath('data.alerts.by_status.active', 1)
            ->assertJsonPath('data.latest_daily_farm_report.total_hogs', 1)
            ->assertJsonPath('data.latest_webhook_logs.0.event', 'device.status');

        $this->assertCount(7, $this->getJson("/api/v1/analytics/farms/{$owned['farm']->id}/summary")->json('data.feeding_last_7_days'));
    }

    public function test_farm_summary_for_other_user_farm_is_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $other = $this->createFarmGraph($otherUser);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/analytics/farms/{$other['farm']->id}/summary")
            ->assertForbidden();
    }

    public function test_empty_account_returns_zero_analytics(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/analytics/overview')
            ->assertOk()
            ->assertJsonPath('data.totals.farms', 0)
            ->assertJsonPath('data.totals.hog_pens', 0)
            ->assertJsonPath('data.totals.hogs', 0)
            ->assertJsonPath('data.totals.iot_devices', 0)
            ->assertJsonPath('data.devices.online', 0)
            ->assertJsonPath('data.devices.offline', 0);

        $this->getJson('/api/v1/analytics/devices/status')
            ->assertOk()
            ->assertJsonPath('data.by_status', [])
            ->assertJsonPath('data.by_type', [])
            ->assertJsonPath('data.offline_devices', []);
    }

    /**
     * @return array{farm: Farms, pen: HogPens, feeder: Feeders}
     */
    private function createFarmGraph(User $user): array
    {
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
            'external_provider' => 'sinric',
            'external_home_id' => 'sinric-home-123-'.$user->id,
        ]);
        $pen = HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'capacity' => 2,
            'status' => 1,
            'external_provider' => 'sinric',
            'external_room_id' => 'sinric-room-123-'.$user->id,
        ]);
        $offlineDevice = IotDevices::query()->create([
            'hog_pen_id' => $pen->id,
            'type' => 'sinric.devices.types.SWITCH',
            'api_provider' => 'sinric',
            'status' => 'offline',
            'external_provider' => 'sinric',
            'external_device_id' => 'sinric-device-grower-'.$user->id,
            'external_metadata' => [
                'name' => 'GROWER',
                'isOnline' => false,
                'lastDisconnectedOn' => '2026-05-17T04:30:04.711Z',
                'lastDisconnectedReason' => 'code (1006)',
            ],
        ]);
        IotDevices::query()->create([
            'hog_pen_id' => $pen->id,
            'type' => 'sinric.devices.types.SWITCH',
            'api_provider' => 'sinric',
            'status' => 'online',
            'external_provider' => 'sinric',
            'external_device_id' => 'sinric-device-starter-'.$user->id,
            'external_metadata' => [
                'name' => 'STARTER',
                'isOnline' => true,
            ],
        ]);
        $feeder = Feeders::query()->create([
            'hog_pen_id' => $pen->id,
            'device_id' => $offlineDevice->id,
            'status' => 'active',
        ]);

        return [
            'farm' => $farm,
            'pen' => $pen,
            'feeder' => $feeder,
        ];
    }
}
