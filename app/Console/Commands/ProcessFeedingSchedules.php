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
<<<<<<< HEAD
                    foreach ($this->dueFeedingTimes($schedule, $now, $windowStart) as $feedingTime) {
=======
                    $dueFeedingTimes = $this->dueFeedingTimes($schedule, $now);

                    foreach ($dueFeedingTimes as $feedingTime) {
>>>>>>> ec51521 (test schedule)
                        if ($this->alreadyExecuted($schedule->id, $date, $feedingTime)) {
                            Log::channel('feeding')->info('Scheduled feeding skipped — already succeeded.', [
                                'schedule_id' => $schedule->id,
                                'feeding_date' => $date,
                                'feeding_time' => $feedingTime,
                            ]);

                            continue;
                        }

<<<<<<< HEAD
                        Log::channel('feeding')->info('Dispatching scheduled feeding.', [
=======
                        ExecuteFeedingJob::dispatch($schedule->id, $date, $feedingTime);

                        $dispatched++;

                        Log::channel('feeding')->info('Scheduled feeding job dispatched.', [
>>>>>>> ec51521 (test schedule)
                            'schedule_id' => $schedule->id,
                            'feeding_date' => $date,
                            'feeding_time' => $feedingTime,
                            'execution_time' => now()->toISOString(),
                        ]);

                        ExecuteFeedingJob::dispatch($schedule->id, $date, $feedingTime);

                        $schedule->forceFill(['last_dispatched_at' => now()])->save();

                        $dispatched++;
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
    private function dueFeedingTimes(FeedingSchedule $schedule, Carbon $now, string $windowStart): array
    {
        if (! $this->runsToday($schedule, $now)) {
            return [];
        }

        $lastDispatched = $schedule->last_dispatched_at
            ? Carbon::parse($schedule->last_dispatched_at)
            : $now->copy()->startOfDay();

        return collect($this->scheduledTimes($schedule))
<<<<<<< HEAD
            ->filter(fn (string $time): bool => $time >= $windowStart && $time <= $currentTime)
=======
            ->filter(function (string $time) use ($schedule, $now, $lastDispatched) {

                $runAt = Carbon::createFromFormat(
                    'Y-m-d H:i',
                    $now->toDateString() . ' ' . $time
                );

                return $runAt->gt($lastDispatched) && $runAt->lte($now);
            })
>>>>>>> ec51521 (test schedule)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function scheduledTimes(FeedingSchedule $schedule): array
    {
        $times = $schedule->feeding_times ?: [];

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

                return null;
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
<<<<<<< HEAD
            ->where('feeding_schedule_id', $scheduleId)
            ->whereDate('feeding_date', $date)
            ->where('feeding_time', $feedingTime)
            ->where('status', 'success')
=======
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
>>>>>>> ec51521 (test schedule)
            ->exists();
    }
}
