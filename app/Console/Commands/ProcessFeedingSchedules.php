<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteFeedingJob;
use App\Models\FeedingLogs;
use App\Models\FeedingSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessFeedingSchedules extends Command
{
    protected $signature = 'feeding:process-schedules';
    protected $description = 'Dispatch due automated feeding schedules.';

    public function handle(): int
    {
        $now = now()->seconds(0);
        $windowStart = $now->copy()->subMinutes(2)->format('H:i');
        $date = $now->toDateString();
        $dispatched = 0;

        FeedingSchedule::query()
            ->where('is_active', true)
            ->with('hogPen.farm')
            ->chunkById(100, function ($schedules) use ($now, $windowStart, $date, &$dispatched): void {
                foreach ($schedules as $schedule) {
                    foreach ($this->dueFeedingTimes($schedule, $now, $windowStart) as $feedingTime) {
                        if ($this->alreadyExecuted($schedule->id, $date, $feedingTime)) {
                            Log::channel('feeding')->info('Scheduled feeding skipped — already succeeded.', [
                                'schedule_id' => $schedule->id,
                                'feeding_date' => $date,
                                'feeding_time' => $feedingTime,
                            ]);

                            continue;
                        }

                        Log::channel('feeding')->info('Dispatching scheduled feeding.', [
                            'schedule_id' => $schedule->id,
                            'feeding_date' => $date,
                            'feeding_time' => $feedingTime,
                            'execution_time' => now()->toISOString(),
                        ]);

                        ExecuteFeedingJob::dispatch($schedule->id, $date, $feedingTime);

                        $schedule->forceFill(['last_dispatched_at' => now()])->save();

                        $dispatched++;
                    }
                }
            });

        $this->info("Dispatched {$dispatched} feeding job(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function dueFeedingTimes(FeedingSchedule $schedule, Carbon $now, string $windowStart): array
    {
        if (! $this->runsToday($schedule, $now)) {
            return [];
        }

        $currentTime = $now->format('H:i');

        return collect($this->scheduledTimes($schedule))
            ->filter(fn (string $time): bool => $time >= $windowStart && $time <= $currentTime)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function scheduledTimes(FeedingSchedule $schedule): array
    {
        $times = $schedule->feeding_times ?: [$schedule->time?->format('H:i')];

        return collect($times)
            ->filter()
            ->map(function (mixed $time): ?string {
                try {
                    return Carbon::parse((string) $time)->format('H:i');
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function runsToday(FeedingSchedule $schedule, Carbon $now): bool
    {
        return match ($schedule->frequency ?? 'everyday') {
            'weekdays' => $now->isWeekday(),
            'weekends' => $now->isWeekend(),
            'custom' => $this->customDayMatches($schedule, $now),
            default => true,
        };
    }

    private function customDayMatches(FeedingSchedule $schedule, Carbon $now): bool
    {
        $days = collect($schedule->custom_days ?? [])
            ->map(fn (mixed $day): string => strtolower((string) $day))
            ->all();

        return in_array(strtolower($now->englishDayOfWeek), $days, true)
            || in_array((string) $now->dayOfWeekIso, $days, true)
            || in_array((string) $now->dayOfWeek, $days, true);
    }

    private function alreadyExecuted(int $scheduleId, string $date, string $feedingTime): bool
    {
        return FeedingLogs::query()
            ->where('feeding_schedule_id', $scheduleId)
            ->whereDate('feeding_date', $date)
            ->where('feeding_time', $feedingTime)
            ->where('status', 'success')
            ->exists();
    }
}
