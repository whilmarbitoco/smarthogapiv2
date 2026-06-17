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

        $this->info("=== FEEDING SCHEDULER START === now={$now->toDateTimeString()} date={$date}");

        $totalSchedules = FeedingSchedule::query()->where('is_active', true)->count();
        $this->info("Active schedules in DB: {$totalSchedules}");

        FeedingSchedule::query()
            ->where('is_active', true)
            ->with('hogPen.farm')
            ->chunkById(100, function ($schedules) use ($now, $date, &$dispatched): void {
                foreach ($schedules as $schedule) {
                    $rawTimes = $schedule->feeding_times ?: [];
                    $normalizedTimes = $this->scheduledTimes($schedule);
                    $runsToday = $this->runsToday($schedule, $now);

                    $this->info("Schedule #{$schedule->id}: raw=" . json_encode($rawTimes) . " normalized=" . json_encode($normalizedTimes) . " runsToday=" . ($runsToday ? 'yes' : 'no') . " frequency=" . ($schedule->frequency ?? 'everyday'));

                    if (! $runsToday) {
                        continue;
                    }

                    $dueFeedingTimes = $this->dueFeedingTimes($schedule, $now);
                    $this->info("Schedule #{$schedule->id}: dueTimes=" . json_encode($dueFeedingTimes));

                    foreach ($dueFeedingTimes as $feedingTime) {
                        if ($this->alreadyExecuted($schedule->id, $date, $feedingTime)) {
                            $this->info("Schedule #{$schedule->id} @ {$feedingTime}: SKIP (already succeeded)");
                            Log::channel('feeding')->info('Scheduled feeding skipped because activity already exists.', [
                                'schedule_id' => $schedule->id,
                                'feeding_date' => $date,
                                'feeding_time' => $feedingTime,
                            ]);

                            continue;
                        }

                        $this->info("Schedule #{$schedule->id} @ {$feedingTime}: DISPATCHING");
                        Log::channel('feeding')->info('Scheduled feeding job dispatched.', [
                            'schedule_id' => $schedule->id,
                            'feeding_date' => $date,
                            'feeding_time' => $feedingTime,
                            'execution_time' => now()->toISOString(),
                        ]);

                        ExecuteFeedingJob::dispatch($schedule->id, $date, $feedingTime);

                        $dispatched++;
                    }

                    if ($dueFeedingTimes !== []) {
                        $schedule->forceFill([
                            'last_dispatched_at' => $now,
                        ])->save();
                    }
                }
            });

        $this->info("=== FEEDING SCHEDULER END === dispatched={$dispatched}");

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
                $time = trim((string) $time);

                if ($time === '') {
                    return null;
                }

                // Already in H:i format (e.g. "14:30")
                if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                    return $time;
                }

                // Try parsing other formats
                foreach (['H:i:s', 'g:i A', 'g:iA', 'h:i A', 'h:iA', 'G:i'] as $format) {
                    $parsed = \DateTime::createFromFormat($format, $time);

                    if ($parsed !== false) {
                        return $parsed->format('H:i');
                    }
                }

                // Fallback to Carbon for anything else
                try {
                    return Carbon::parse($time)->format('H:i');
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
