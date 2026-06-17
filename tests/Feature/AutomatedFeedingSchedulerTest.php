<?php

namespace Tests\Feature;

use App\Jobs\ExecuteFeedingJob;
use App\Models\Farms;
use App\Models\Feeders;
use App\Models\FeedingLogs;
use App\Models\FeedingSchedule;
use App\Models\HogPens;
use App\Models\IotDevices;
use App\Models\User;
use App\Integrations\SinricPro\SinricDevicesClient;
use App\Services\DeviceCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AutomatedFeedingSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_command_dispatches_due_everyday_schedule(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        $graph = $this->createFarmGraph();

        $schedule = FeedingSchedule::query()->create([
            'hog_pen_id' => $graph['pen']->id,
            'time' => '2026-06-16 06:00:00',
            'feed_amount' => 2.5,
            'mode' => 'auto',
            'frequency' => 'everyday',
            'is_active' => true,
        ]);

        $this->artisan('feeding:process-schedules')->assertSuccessful();

        Queue::assertPushed(ExecuteFeedingJob::class, fn (ExecuteFeedingJob $job): bool => $job->feedingScheduleId === $schedule->id
            && $job->feedingDate === '2026-06-16'
            && $job->feedingTime === '06:00');

        $this->assertNotNull($schedule->fresh()->last_dispatched_at);
    }

    public function test_process_command_skips_duplicate_activity(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        $graph = $this->createFarmGraph();

        $schedule = FeedingSchedule::query()->create([
            'hog_pen_id' => $graph['pen']->id,
            'time' => '2026-06-16 06:00:00',
            'feed_amount' => 2.5,
            'mode' => 'auto',
            'frequency' => 'everyday',
            'is_active' => true,
        ]);

        FeedingLogs::query()->create([
            'feeding_schedule_id' => $schedule->id,
            'feeder_id' => $graph['feeder']->id,
            'device_id' => $graph['device']->id,
            'pen_id' => $graph['pen']->id,
            'feed_amount_given' => 2.5,
            'feeding_date' => '2026-06-16',
            'feeding_time' => '06:00',
            'status' => 'success',
            'trigger_source' => 'scheduled',
            'triggered' => 'scheduled',
        ]);

        $this->artisan('feeding:process-schedules')->assertSuccessful();

        Queue::assertNotPushed(ExecuteFeedingJob::class);
    }

    public function test_execute_job_records_success_activity_notification_and_analytics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        $graph = $this->createFarmGraph();
        $schedule = FeedingSchedule::query()->create([
            'hog_pen_id' => $graph['pen']->id,
            'time' => '2026-06-16 06:00:00',
            'feed_amount' => 3.25,
            'mode' => 'auto',
            'frequency' => 'everyday',
            'is_active' => true,
        ]);

        $service = Mockery::mock(DeviceCommandService::class);
        $service->shouldReceive('sendFeedCommand')
            ->once()
            ->with((string) $graph['device']->id, 3.25)
            ->andReturn(['provider' => 'mqtt', 'command_id' => 99]);

        (new ExecuteFeedingJob($schedule->id, '2026-06-16', '06:00'))->handle($service);

        $this->assertDatabaseHas('feeding_logs', [
            'feeding_schedule_id' => $schedule->id,
            'device_id' => $graph['device']->id,
            'feeding_date' => '2026-06-16 00:00:00',
            'feeding_time' => '06:00',
            'status' => 'success',
            'trigger_source' => 'scheduled',
        ]);
        $this->assertDatabaseHas('alerts', [
            'farm_id' => $graph['farm']->id,
            'type' => 'scheduled_feeding',
            'message' => 'Scheduled feeding completed successfully',
        ]);
        $this->assertDatabaseHas('daily_farm_reports', [
            'farm_id' => $graph['farm']->id,
            'total_feed_consumed' => 3.25,
        ]);
    }

    public function test_execute_job_records_failed_activity_when_device_is_offline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        $graph = $this->createFarmGraph(deviceStatus: 'offline');
        $schedule = FeedingSchedule::query()->create([
            'hog_pen_id' => $graph['pen']->id,
            'time' => '2026-06-16 06:00:00',
            'feed_amount' => 3.25,
            'mode' => 'auto',
            'frequency' => 'everyday',
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            (new ExecuteFeedingJob($schedule->id, '2026-06-16', '06:00'))->handle(app(DeviceCommandService::class));
        } finally {
            $this->assertDatabaseHas('feeding_logs', [
                'feeding_schedule_id' => $schedule->id,
                'device_id' => $graph['device']->id,
                'status' => 'failed',
                'trigger_source' => 'scheduled',
                'error_message' => 'Feeding device is offline.',
            ]);
            $this->assertDatabaseHas('alerts', [
                'farm_id' => $graph['farm']->id,
                'type' => 'scheduled_feeding',
                'message' => 'Scheduled feeding failed',
            ]);
        }
    }

    public function test_send_feed_command_throws_when_no_device_provider_is_configured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        Config::set('services.feeding_devices.mqtt.endpoint', null);
        Config::set('services.feeding_devices.sinric.endpoint', null);
        Config::set('services.feeding_devices.http.endpoint', null);

        $graph = $this->createFarmGraph();
        $device = $graph['device'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No device provider configured for feed command.');

        try {
            app(DeviceCommandService::class)->sendFeedCommand((string) $device->id, 1.5);
        } finally {
            $this->assertDatabaseCount('device_commands', 0);
        }
    }

    public function test_execute_job_prefers_sinric_metadata_when_device_status_is_mismatched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        $graph = $this->createFarmGraph(deviceStatus: 'online');
        $graph['device']->update([
            'external_provider' => 'sinric',
            'external_metadata' => [
                'isOnline' => false,
            ],
        ]);

        $schedule = FeedingSchedule::query()->create([
            'hog_pen_id' => $graph['pen']->id,
            'time' => '2026-06-16 06:00:00',
            'feed_amount' => 3.25,
            'mode' => 'auto',
            'frequency' => 'everyday',
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            (new ExecuteFeedingJob($schedule->id, '2026-06-16', '06:00'))->handle(app(DeviceCommandService::class));
        } finally {
            $this->assertDatabaseHas('feeding_logs', [
                'feeding_schedule_id' => $schedule->id,
                'device_id' => $graph['device']->id,
                'status' => 'failed',
                'trigger_source' => 'scheduled',
                'error_message' => 'Feeding device is offline.',
            ]);
            $this->assertDatabaseHas('alerts', [
                'farm_id' => $graph['farm']->id,
                'type' => 'scheduled_feeding',
                'message' => 'Scheduled feeding failed',
            ]);
        }
    }

    public function test_send_feed_command_uses_sinric_action_for_sinric_linked_device(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 06:00:00'));

        $user = User::factory()->create();
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
        ]);
        $pen = HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Grower Pen',
            'capacity' => 10,
            'status' => 1,
        ]);
        $device = IotDevices::query()->create([
            'hog_pen_id' => $pen->id,
            'type' => 'feeder',
            'api_provider' => 'sinric',
            'external_provider' => 'sinric',
            'status' => 'online',
            'external_device_id' => 'sinric-device-123',
            'external_metadata' => [
                'isOnline' => true,
            ],
        ]);
        Feeders::query()->create([
            'hog_pen_id' => $pen->id,
            'device_id' => $device->id,
            'status' => 'active',
        ]);

        $sinricClient = Mockery::mock(SinricDevicesClient::class);
        $sinricClient->shouldReceive('action')
            ->once()
            ->with(
                Mockery::on(fn ($value): bool => $value instanceof User && $value->id === $user->id),
                'sinric-device-123',
                Mockery::on(fn (array $payload): bool =>
                    $payload['action'] === 'feed'
                    && $payload['feed_quantity'] === 1.5
                ),
            )
            ->andReturn(['success' => true, 'status' => 200]);

        $this->app->instance(SinricDevicesClient::class, $sinricClient);

        $result = app(DeviceCommandService::class)->sendFeedCommand((string) $device->id, 1.5);

        $this->assertSame('sinric', $result['provider']);
        $this->assertDatabaseHas('device_commands', [
            'iot_device_id' => $device->id,
            'status' => 'completed',
        ]);
    }

    public function test_feeding_analytics_exposes_scheduler_dashboard_fields(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00'));

        $graph = $this->createFarmGraph();

        FeedingLogs::query()->create([
            'feeder_id' => $graph['feeder']->id,
            'device_id' => $graph['device']->id,
            'pen_id' => $graph['pen']->id,
            'feed_amount_given' => 4,
            'feeding_date' => '2026-06-16',
            'feeding_time' => '06:00',
            'status' => 'success',
            'trigger_source' => 'scheduled',
            'triggered' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FeedingSchedule::query()->create([
            'hog_pen_id' => $graph['pen']->id,
            'time' => '2026-06-16 18:00:00',
            'feed_amount' => 2,
            'mode' => 'auto',
            'frequency' => 'everyday',
            'is_active' => true,
        ]);

        $this->actingAs($graph['user'], 'sanctum')
            ->getJson('/api/v1/analytics/feeding')
            ->assertOk()
            ->assertJsonPath('data.total_feed_dispensed', 4)
            ->assertJsonPath('data.daily_feed_consumption', 4)
            ->assertJsonPath('data.successful_feedings', 1)
            ->assertJsonPath('data.failed_feedings', 0)
            ->assertJsonStructure([
                'data' => [
                    'next_feeding_time',
                    'last_feeding_time',
                    'todays_feedings',
                    'missed_feedings',
                    'weekly_feed_consumption',
                    'monthly_feed_consumption',
                ],
            ]);
    }

    /**
     * @return array{user: User, farm: Farms, pen: HogPens, device: IotDevices, feeder: Feeders}
     */
    private function createFarmGraph(string $deviceStatus = 'online'): array
    {
        $user = User::factory()->create();
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
        ]);
        $pen = HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Grower Pen',
            'capacity' => 10,
            'status' => 1,
        ]);
        $device = IotDevices::query()->create([
            'hog_pen_id' => $pen->id,
            'type' => 'feeder',
            'api_provider' => 'mqtt',
            'status' => $deviceStatus,
            'external_provider' => 'mqtt',
            'external_device_id' => 'feeder-'.$user->id,
            'external_metadata' => [
                'isOnline' => $deviceStatus === 'online',
            ],
        ]);
        $feeder = Feeders::query()->create([
            'hog_pen_id' => $pen->id,
            'device_id' => $device->id,
            'status' => 'active',
        ]);

        return [
            'user' => $user,
            'farm' => $farm,
            'pen' => $pen,
            'device' => $device,
            'feeder' => $feeder,
        ];
    }
}
