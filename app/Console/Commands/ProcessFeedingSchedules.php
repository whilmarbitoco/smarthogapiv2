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
        $date = $now->toDateString();
        $dispatched = 0;

        FeedingSchedule::query()
            ->where('is_active', true)
            ->with('hogPen.farm')
            ->chunkById(100, function ($schedules) use ($now, $date, &$dispatched): void {
                foreach ($schedules as $schedule) {
                    $dueFeedingTimes = $this->dueFeedingTimes($schedule, $now);

                    foreach ($dueFeedingTimes as $feedingTime) {
                        if ($this->alreadyExecuted($schedule->id, $date, $feedingTime)) {
                            Log::channel('feeding')->info('Scheduled feeding skipped because activity already exists.', [
                                'schedule_id' => $schedule->id,
                                'feeding_date' => $date,
                                'feeding_time' => $feedingTime,
                            ]);

                            continue;
                        }

                        ExecuteFeedingJob::dispatch($schedule->id, $date, $feedingTime);

                        $dispatched++;

                        Log::channel('feeding')->info('Scheduled feeding job dispatched.', [
                            'schedule_id' => $schedule->id,
                            'feeding_date' => $date,
                            'feeding_time' => $feedingTime,
                            'execution_time' => now()->toISOString(),
                        ]);
                    }

                    if ($dueFeedingTimes !== []) {
                        $schedule->forceFill([
                            'last_dispatched_at' => $now,
                        ])->save();
                    }
                }
            });

        $this->info("Dispatched {$dispatched} feeding job(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function dueFeedingTimes(FeedingSchedule $schedule, Carbon $now): array
    {
        if (! $this->runsToday($schedule, $now)) {
            return [];
        }

        $lastDispatched = $schedule->last_dispatched_at
            ? Carbon::parse($schedule->last_dispatched_at)
            : $now->copy()->startOfDay();

        return collect($this->scheduledTimes($schedule))
            ->filter(function (string $time) use ($schedule, $now, $lastDispatched): bool {
                $runAt = Carbon::createFromFormat(
                    'Y-m-d H:i',
                    $now->toDateString() . ' ' . $time
                );

                return $runAt->gt($lastDispatched) && $runAt->lte($now);
            })
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
        $executionKey = hash('sha256', "{$scheduleId}|{$date}|{$feedingTime}");
        $normalizedTime = Carbon::parse($feedingTime)->format('H:i:s');

        return FeedingLogs::query()
            ->where(function ($query) use ($executionKey, $scheduleId, $date, $feedingTime, $normalizedTime): void {
                $query->where('execution_key', $executionKey)
                    ->orWhere(function ($legacyQuery) use ($scheduleId, $date, $feedingTime, $normalizedTime): void {
                        $legacyQuery
                            ->where('feeding_schedule_id', $scheduleId)
                            ->whereRaw('DATE(feeding_date) = ?', [$date])
                            ->where(function ($timeQuery) use ($feedingTime, $normalizedTime): void {
                                $timeQuery
                                    ->whereRaw('feeding_time = ?', [$feedingTime])
                                    ->orWhereRaw('feeding_time = ?', [$normalizedTime]);
                            });
                    });
            })
            ->exists();
    }
}
