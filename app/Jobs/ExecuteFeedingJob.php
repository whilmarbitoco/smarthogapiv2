<?php

namespace App\Jobs;

use App\Models\Alerts;
use App\Models\DailyFarmReports;
use App\Models\Feeders;
use App\Models\FeedingLogs;
use App\Models\FeedingSchedule;
use App\Models\IotDevices;
use App\Services\DeviceCommandService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExecuteFeedingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 180, 300];

    public function __construct(
        public int $feedingScheduleId,
        public string $feedingDate,
        public string $feedingTime,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))->expireAfter(600),
        ];
    }

    public function handle(DeviceCommandService $deviceCommandService): void
    {
        $schedule = FeedingSchedule::query()
            ->with(['hogPen.farm', 'hogPen.feeders.iotDevice'])
            ->find($this->feedingScheduleId);

        if (! $schedule || ! $schedule->is_active) {
            return;
        }

        $feeder = $this->resolveFeeder($schedule);
        $device = $feeder?->iotDevice;

        try {
            if (! $feeder || ! $device) {
                throw new RuntimeException('No feeder device is associated with this feeding schedule.');
            }

            if (! $this->deviceIsOnline($device)) {
                throw new RuntimeException('Feeding device is offline.');
            }

            DB::transaction(function () use ($schedule, $feeder, $device, $deviceCommandService): void {
                $commandResult = $deviceCommandService->sendFeedCommand((string) $device->id, (float) $schedule->feed_amount);

                $this->upsertActivity($schedule, $feeder, $device, 'success', null);
                $this->updateAnalytics($schedule);
                $this->storeNotification($schedule, 'Scheduled feeding completed successfully', 'info', 'resolved');

                Log::channel('feeding')->info('Scheduled feeding completed successfully.', [
                    'schedule_id' => $schedule->id,
                    'device_id' => $device->id,
                    'feeding_date' => $this->feedingDate,
                    'feeding_time' => $this->feedingTime,
                    'provider' => $commandResult['provider'],
                    'command_id' => $commandResult['command_id'],
                    'execution_time' => now()->toISOString(),
                    'result' => 'success',
                ]);
            });
        } catch (Throwable $exception) {
            if ($feeder) {
                $this->recordFailure($schedule, $feeder, $device, $exception);
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $schedule = FeedingSchedule::query()->with(['hogPen.farm', 'hogPen.feeders.iotDevice'])->find($this->feedingScheduleId);

        if (! $schedule) {
            return;
        }

        $feeder = $this->resolveFeeder($schedule);

        if (! $feeder) {
            return;
        }

        $this->recordFailure($schedule, $feeder, $feeder->iotDevice, $exception);
    }

    private function resolveFeeder(FeedingSchedule $schedule): ?Feeders
    {
        return $schedule->hogPen?->feeders
            ->first(fn (Feeders $feeder): bool => $feeder->status === 'active')
            ?? $schedule->hogPen?->feeders->first();
    }

    private function deviceIsOnline(IotDevices $device): bool
    {
        return $device->status === 'online'
            && data_get($device->external_metadata, 'isOnline', true) !== false;
    }

    private function recordFailure(FeedingSchedule $schedule, Feeders $feeder, ?IotDevices $device, Throwable $exception): void
    {
        DB::transaction(function () use ($schedule, $feeder, $device, $exception): void {
            $this->upsertActivity($schedule, $feeder, $device, 'failed', $exception->getMessage());
            $this->storeNotification($schedule, 'Scheduled feeding failed', 'high', 'active');
        });

        Log::channel('feeding')->error('Scheduled feeding failed.', [
            'schedule_id' => $schedule->id,
            'device_id' => $device?->id,
            'feeding_date' => $this->feedingDate,
            'feeding_time' => $this->feedingTime,
            'execution_time' => now()->toISOString(),
            'result' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }

    private function upsertActivity(
        FeedingSchedule $schedule,
        Feeders $feeder,
        ?IotDevices $device,
        string $status,
        ?string $errorMessage,
    ): void {
        FeedingLogs::query()->updateOrCreate([
            'feeding_schedule_id' => $schedule->id,
            'feeding_date' => $this->feedingDate,
            'feeding_time' => $this->feedingTime,
        ], [
            'feeder_id' => $feeder->id,
            'device_id' => $device?->id,
            'pen_id' => $schedule->hog_pen_id,
            'feed_amount_given' => $schedule->feed_amount,
            'status' => $status,
            'trigger_source' => 'scheduled',
            'triggered' => 'scheduled',
            'error_message' => $errorMessage,
        ]);
    }

    private function updateAnalytics(FeedingSchedule $schedule): void
    {
        $farm = $schedule->hogPen?->farm;

        if (! $farm) {
            return;
        }

        $report = DailyFarmReports::query()->firstOrNew([
            'farm_id' => $farm->id,
            'report_date' => $this->feedingDate,
        ]);

        $report->fill([
            'total_feed_consumed' => (float) ($report->total_feed_consumed ?? 0) + (float) $schedule->feed_amount,
            'total_hogs' => (int) ($report->total_hogs ?? 0),
            'avg_weight' => (float) ($report->avg_weight ?? 0),
            'mortality_count' => (float) ($report->mortality_count ?? 0),
        ]);

        $report->save();
    }

    private function storeNotification(FeedingSchedule $schedule, string $message, string $severity, string $status): void
    {
        $hogPen = $schedule->hogPen;

        if (! $hogPen) {
            return;
        }

        $existing = Alerts::query()
            ->where('farm_id', $hogPen->farm_id)
            ->where('hog_pen_id', $hogPen->id)
            ->where('type', 'scheduled_feeding')
            ->where('message', $message)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($existing) {
            return;
        }

        Alerts::query()->create([
            'farm_id' => $hogPen->farm_id,
            'hog_pen_id' => $hogPen->id,
            'type' => 'scheduled_feeding',
            'message' => $message,
            'severity' => $severity,
            'status' => $status,
        ]);
    }

    private function overlapKey(): string
    {
        return "feeding:{$this->feedingScheduleId}:{$this->feedingDate}:{$this->feedingTime}";
    }
}
