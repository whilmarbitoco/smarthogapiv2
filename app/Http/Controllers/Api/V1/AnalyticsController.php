<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Alerts;
use App\Models\DailyFarmReports;
use App\Models\Farms;
use App\Models\FeedingLogs;
use App\Models\Hogs;
use App\Models\HogPens;
use App\Models\IotDevices;
use App\Models\User;
use App\Models\WebHookLogs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview(): JsonResponse
    {
        $user = $this->authenticatedUser();
        $today = now()->startOfDay();

        return ApiResponse::success([
            'totals' => [
                'farms' => $this->ownedFarms($user)->count(),
                'hog_pens' => $this->ownedHogPens($user)->count(),
                'hogs' => $this->ownedHogs($user)->count(),
                'iot_devices' => $this->ownedIotDevices($user)->count(),
            ],
            'devices' => [
                'online' => $this->ownedIotDevices($user)->where('status', 'online')->count(),
                'offline' => $this->ownedIotDevices($user)->where('status', 'offline')->count(),
            ],
            'alerts' => [
                'active' => $this->ownedAlerts($user)->where('status', 'active')->count(),
            ],
            'feeding_today' => [
                'log_count' => $this->ownedFeedingLogs($user)->where('feeding_logs.created_at', '>=', $today)->count(),
                'total_feed_amount' => $this->decimalSum(
                    $this->ownedFeedingLogs($user)->where('feeding_logs.created_at', '>=', $today),
                    'feeding_logs.feed_amount_given'
                ),
            ],
            'webhooks' => [
                'recent_failures' => $this->ownedWebhookLogs($user)
                    ->where('status', 'failed')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],
        ], 'Analytics overview retrieved successfully.');
    }

    public function deviceStatus(): JsonResponse
    {
        $user = $this->authenticatedUser();

        $devices = $this->ownedIotDevices($user)
            ->with('hogPen:id,name')
            ->get();

        return ApiResponse::success([
            'by_status' => $this->countsBy($devices, 'status'),
            'by_type' => $this->countsBy($devices, 'type'),
            'sinric_online' => [
                'online' => $devices->filter(fn (IotDevices $device): bool => data_get($device->external_metadata, 'isOnline') === true)->count(),
                'offline' => $devices->filter(fn (IotDevices $device): bool => data_get($device->external_metadata, 'isOnline') === false)->count(),
            ],
            'offline_devices' => $devices
                ->filter(fn (IotDevices $device): bool => $device->status === 'offline' || data_get($device->external_metadata, 'isOnline') === false)
                ->values()
                ->map(fn (IotDevices $device): array => [
                    'id' => $device->id,
                    'name' => data_get($device->external_metadata, 'name', $device->type),
                    'type' => $device->type,
                    'status' => $device->status,
                    'hog_pen' => $device->hogPen ? [
                        'id' => $device->hogPen->id,
                        'name' => $device->hogPen->name,
                    ] : null,
                    'last_disconnected_on' => data_get($device->external_metadata, 'lastDisconnectedOn'),
                    'last_disconnected_reason' => data_get($device->external_metadata, 'lastDisconnectedReason'),
                ]),
        ], 'Device status analytics retrieved successfully.');
    }

    public function farmSummary(Farms $farm): JsonResponse
    {
        $user = $this->authenticatedUser();

        abort_unless($farm->belongsToUser($user->id), 403);

        $feedingStart = now()->startOfDay()->subDays(6);

        return ApiResponse::success([
            'farm' => [
                'id' => $farm->id,
                'location' => $farm->location,
                'timezone' => $farm->timezone,
                'external_provider' => $farm->external_provider,
                'external_home_id' => $farm->external_home_id,
            ],
            'totals' => [
                'hog_pens' => $this->farmHogPens($farm)->count(),
                'hogs' => $this->farmHogs($farm)->count(),
                'iot_devices' => $this->farmIotDevices($farm)->count(),
            ],
            'devices' => [
                'by_status' => $this->countsBy($this->farmIotDevices($farm)->get(), 'status'),
            ],
            'alerts' => [
                'by_severity' => $this->queryCountsBy($this->farmAlerts($farm), 'severity'),
                'by_status' => $this->queryCountsBy($this->farmAlerts($farm), 'status'),
            ],
            'feeding_last_7_days' => $this->feedingTotalsByDay($farm, $feedingStart),
            'latest_daily_farm_report' => DailyFarmReports::query()
                ->where('farm_id', $farm->id)
                ->latest('report_date')
                ->first(),
            'latest_webhook_logs' => WebHookLogs::query()
                ->where('farm_id', $farm->id)
                ->latest()
                ->limit(5)
                ->get(),
        ], 'Farm summary analytics retrieved successfully.');
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function ownedFarms(User $user): Builder
    {
        return Farms::query()->where('user_id', $user->id);
    }

    private function ownedHogPens(User $user): Builder
    {
        return HogPens::query()->whereHas('farm', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private function ownedHogs(User $user): Builder
    {
        return Hogs::query()->whereHas('hogPen.farm', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private function ownedIotDevices(User $user): Builder
    {
        return IotDevices::query()->whereHas('hogPen.farm', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private function ownedAlerts(User $user): Builder
    {
        return Alerts::query()->whereHas('farm', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private function ownedFeedingLogs(User $user): Builder
    {
        return FeedingLogs::query()->whereHas('hogPen.farm', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private function ownedWebhookLogs(User $user): Builder
    {
        return WebHookLogs::query()->whereHas('farm', fn (Builder $query) => $query->where('user_id', $user->id));
    }

    private function farmHogPens(Farms $farm): Builder
    {
        return HogPens::query()->where('farm_id', $farm->id);
    }

    private function farmHogs(Farms $farm): Builder
    {
        return Hogs::query()->whereHas('hogPen', fn (Builder $query) => $query->where('farm_id', $farm->id));
    }

    private function farmIotDevices(Farms $farm): Builder
    {
        return IotDevices::query()->whereHas('hogPen', fn (Builder $query) => $query->where('farm_id', $farm->id));
    }

    private function farmAlerts(Farms $farm): Builder
    {
        return Alerts::query()->where('farm_id', $farm->id);
    }

    /**
     * @param  Collection<int, object>  $items
     * @return array<string, int>
     */
    private function countsBy(Collection $items, string $field): array
    {
        return $items
            ->groupBy(fn (object $item): string => (string) ($item->{$field} ?? 'unknown'))
            ->map(fn (Collection $items): int => $items->count())
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function queryCountsBy(Builder $query, string $field): array
    {
        return $query
            ->select($field, DB::raw('count(*) as aggregate'))
            ->groupBy($field)
            ->pluck('aggregate', $field)
            ->map(fn (mixed $count): int => (int) $count)
            ->sortKeys()
            ->all();
    }

    /**
     * @return list<array{date: string, log_count: int, total_feed_amount: float}>
     */
    private function feedingTotalsByDay(Farms $farm, Carbon $start): array
    {
        $totals = FeedingLogs::query()
            ->whereHas('hogPen', fn (Builder $query) => $query->where('farm_id', $farm->id))
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as feed_date, COUNT(*) as log_count, COALESCE(SUM(feed_amount_given), 0) as total_feed_amount')
            ->groupBy('feed_date')
            ->pluck('total_feed_amount', 'feed_date');

        $counts = FeedingLogs::query()
            ->whereHas('hogPen', fn (Builder $query) => $query->where('farm_id', $farm->id))
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as feed_date, COUNT(*) as log_count')
            ->groupBy('feed_date')
            ->pluck('log_count', 'feed_date');

        return collect(range(0, 6))
            ->map(function (int $offset) use ($start, $totals, $counts): array {
                $date = $start->copy()->addDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'log_count' => (int) ($counts[$date] ?? 0),
                    'total_feed_amount' => (float) ($totals[$date] ?? 0),
                ];
            })
            ->all();
    }

    private function decimalSum(Builder $query, string $field): float
    {
        return (float) ($query->sum($field) ?? 0);
    }
}
