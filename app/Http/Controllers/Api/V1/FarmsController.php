<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Farms\SyncSinricHomesAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\FarmsRequest;
use App\Http\Resources\FarmResource;
use App\Http\Resources\FarmSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Integrations\SinricPro\SinricHomesClient;
use App\Models\Farms;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FarmsController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string { return Farms::class; }
    protected function resourceClass(): string { return FarmResource::class; }
    protected function relationships(): array { return ['hogPens']; }
    protected function ownedParentFields(): array { return ['user_id' => User::class]; }

public function summary()
{
    $user = auth()->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 401);
    }

    $farms = Farms::where('user_id', $user->id)->get();

    return response()->json([
        'success' => true,
        'data' => FarmSummaryResource::collection($farms),
    ]);
}

    public function index(SyncSinricHomesAction $syncSinricHomesAction): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $syncSinricHomesAction->execute($user);
        }

        return $this->crudIndex();
    }

    public function store(FarmsRequest $request, SinricHomesClient $sinricHomesClient): JsonResponse
    {
        $data = $request->validated();
        $user = auth()->user();

        if ($user instanceof User && $this->hasSinricToken($user)) {
            Log::info('Creating Sinric home for farm', [
                'user_id' => $user->id,
                'payload' => $this->sinricHomePayload($data),
            ]);

            $result = $sinricHomesClient->create($user, $this->sinricHomePayload($data));

            if (! ($result['success'] ?? false)) {
                Log::error('Sinric home creation failed', [
                    'user_id' => $user->id,
                    'result' => $result,
                ]);
                return $this->sinricError($result, 'Sinric home creation failed.');
            }

            Log::info('Sinric home created successfully', [
                'user_id' => $user->id,
                'external_home_id' => $result['home']['id'] ?? null,
            ]);

            $data = $this->mergeSinricHomeData($data, $result);
        }

        return $this->crudStore($this->localFarmData($data));
    }

    public function show(Farms $farm, SinricHomesClient $sinricHomesClient): JsonResponse
    {
        $this->syncFarmFromSinric($farm, $sinricHomesClient);

        return $this->crudShow($farm->refresh());
    }

    public function update(FarmsRequest $request, Farms $farm, SinricHomesClient $sinricHomesClient): JsonResponse
    {
        $this->authorizeOwnedModel($farm);

        $data = $request->validated();
        $user = auth()->user();

        if ($user instanceof User && $this->hasSinricToken($user) && $this->isSinricFarm($farm)) {
            Log::info('Updating Sinric home for farm', [
                'user_id' => $user->id,
                'farm_id' => $farm->id,
                'external_home_id' => $farm->external_home_id,
            ]);

            $result = $sinricHomesClient->update(
                $user,
                (string) $farm->external_home_id,
                $this->sinricHomePayload($data, $farm),
            );

            if (! ($result['success'] ?? false)) {
                Log::error('Sinric home update failed', [
                    'user_id' => $user->id,
                    'farm_id' => $farm->id,
                    'result' => $result,
                ]);
                return $this->sinricError($result, 'Sinric home update failed.');
            }

            $freshResult = $sinricHomesClient->home($user, (string) $farm->external_home_id);
            $home = data_get($freshResult, 'home', data_get($freshResult, 'data.home'));

            if (! ($freshResult['success'] ?? false) || ! is_array($home)) {
                Log::error('Sinric home update verification failed', [
                    'user_id' => $user->id,
                    'farm_id' => $farm->id,
                    'result' => $freshResult,
                ]);
                return $this->sinricError($freshResult, 'Sinric home update could not be verified.');
            }

            if (! $this->sinricHomeMatchesPayload($home, $this->sinricHomePayload($data, $farm))) {
                Log::warning('Sinric home payload mismatch after update', [
                    'user_id' => $user->id,
                    'farm_id' => $farm->id,
                    'home' => $home,
                ]);
                return ApiResponse::error('Sinric home update could not be verified.', null, 502);
            }

            Log::info('Sinric home updated successfully', [
                'user_id' => $user->id,
                'farm_id' => $farm->id,
                'external_home_id' => $farm->external_home_id,
            ]);

            $data = $this->mergeSinricHomeData($data, ['home' => $home], $farm);
            $data['location'] = $this->homeString($home, ['name']) ?? $farm->location;
            $data['timezone'] = $this->homeString($home, ['timeZone', 'timezone']) ?? $farm->timezone;
        }

        return $this->crudUpdate($farm, $this->localFarmData($data, $farm));
    }

    public function destroy(Farms $farm, SinricHomesClient $sinricHomesClient): JsonResponse
    {
        $this->authorizeOwnedModel($farm);

        $user = auth()->user();

        if ($user instanceof User && $this->hasSinricToken($user) && $this->isSinricFarm($farm)) {
            Log::info('Deleting Sinric home for farm', [
                'user_id' => $user->id,
                'farm_id' => $farm->id,
                'external_home_id' => $farm->external_home_id,
            ]);

            $result = $sinricHomesClient->delete($user, (string) $farm->external_home_id);

            if (! ($result['success'] ?? false) && ! $this->sinricHomeAlreadyDeleted($result)) {
                Log::error('Sinric home deletion failed', [
                    'user_id' => $user->id,
                    'farm_id' => $farm->id,
                    'result' => $result,
                ]);
                return $this->sinricError($result, 'Sinric home deletion failed.');
            }

            if (! $this->sinricHomeAlreadyDeleted($result)) {
                $verification = $sinricHomesClient->home($user, (string) $farm->external_home_id);

                if (! $this->sinricHomeAlreadyDeleted($verification)) {
                    if ($verification['success'] ?? false) {
                        Log::error('Sinric home still exists after deletion attempt', [
                            'user_id' => $user->id,
                            'farm_id' => $farm->id,
                            'external_home_id' => $farm->external_home_id,
                        ]);
                        return ApiResponse::error('Sinric home deletion could not be verified.', null, 502);
                    }

                    Log::error('Sinric home deletion verification failed', [
                        'user_id' => $user->id,
                        'farm_id' => $farm->id,
                        'result' => $verification,
                    ]);
                    return $this->sinricError($verification, 'Sinric home deletion could not be verified.');
                }
            }

            Log::info('Sinric home deleted successfully', [
                'user_id' => $user->id,
                'farm_id' => $farm->id,
                'external_home_id' => $farm->external_home_id,
            ]);
        }

        $this->deleteFarmLocally($farm);

        return ApiResponse::deleted($this->resourceName().' deleted successfully');
    }

    protected function prepareForCreate(array $data): array
    {
        $data['user_id'] = (int) auth()->id();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sinricHomePayload(array $data, ?Farms $farm = null): array
    {
        $metadata = is_array($farm?->external_metadata) ? $farm->external_metadata : [];
        $payload = [
            'name' => $data['name'] ?? $data['location'] ?? $farm?->location,
        ];

        $imageUrl = $data['imageUrl'] ?? $metadata['imageUrl'] ?? null;

        if (is_string($imageUrl) && $imageUrl !== '') {
            $payload['imageUrl'] = $imageUrl;
        }

        return array_filter($payload, fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function mergeSinricHomeData(array $data, array $result, ?Farms $farm = null): array
    {
        $home = data_get($result, 'home', data_get($result, 'data.home'));
        $home = is_array($home) ? $home : [];
        $metadata = array_merge(is_array($farm?->external_metadata) ? $farm->external_metadata : [], $home);

        if (isset($data['imageUrl']) && is_string($data['imageUrl'])) {
            $metadata['imageUrl'] = $data['imageUrl'];
        }

        $homeId = $this->homeString($home, ['id', '_id', 'homeId', 'home_id']) ?? $farm?->external_home_id;

        if (is_string($homeId) && $homeId !== '') {
            $data['external_provider'] = 'sinric';
            $data['external_home_id'] = $homeId;
        }

        $data['external_metadata'] = $metadata;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function localFarmData(array $data, ?Farms $farm = null): array
    {
        $location = $data['location'] ?? $data['name'] ?? $farm?->location;
        $timezone = $data['timezone'] ?? $farm?->timezone ?? config('app.timezone', 'UTC');

        unset($data['name'], $data['imageUrl']);

        if (is_string($location) && $location !== '') {
            $data['location'] = $location;
        }

        if (is_string($timezone) && $timezone !== '') {
            $data['timezone'] = $timezone;
        }

        return $data;
    }

    private function syncFarmFromSinric(Farms $farm, SinricHomesClient $sinricHomesClient): void
    {
        $this->authorizeOwnedModel($farm);

        $user = auth()->user();

        if (! ($user instanceof User) || ! $this->hasSinricToken($user) || ! $this->isSinricFarm($farm)) {
            return;
        }

        $result = $sinricHomesClient->home($user, (string) $farm->external_home_id);
        $home = data_get($result, 'home', data_get($result, 'data.home'));

        if (! ($result['success'] ?? false) || ! is_array($home)) {
            return;
        }

        $farm->update([
            'location' => $this->homeString($home, ['name']) ?? $farm->location,
            'timezone' => $this->homeString($home, ['timeZone', 'timezone']) ?? $farm->timezone,
            'external_metadata' => $home,
        ]);
    }

    /**
     * @param  array<string, mixed>  $home
     * @param  list<string>  $keys
     */
    private function homeString(array $home, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $home[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isSinricFarm(Farms $farm): bool
    {
        return $farm->external_provider === 'sinric'
            && is_string($farm->external_home_id)
            && $farm->external_home_id !== '';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sinricHomeAlreadyDeleted(array $result): bool
    {
        $status = (int) ($result['status'] ?? 0);

        return in_array($status, [404, 410, 422], true);
    }

    private function deleteFarmLocally(Farms $farm): void
    {
        $this->authorizeOwnedModel($farm);

        DB::transaction(function () use ($farm): void {
            $penIds = DB::table('hog_pens')
                ->where('farm_id', $farm->id)
                ->pluck('id');
            $hogIds = DB::table('hogs')
                ->whereIn('hog_pen_id', $penIds)
                ->pluck('id');
            $deviceIds = DB::table('iot_devices')
                ->whereIn('hog_pen_id', $penIds)
                ->pluck('id');
            $feederIds = DB::table('feeders')
                ->whereIn('hog_pen_id', $penIds)
                ->pluck('id');
            $sensorIds = DB::table('sensors')
                ->whereIn('hog_pen_id', $penIds)
                ->pluck('id');

            DB::table('sensor_readings')->whereIn('sensor_id', $sensorIds)->delete();
            DB::table('device_logs')->whereIn('device_id', $deviceIds)->delete();
            DB::table('device_commands')->whereIn('iot_device_id', $deviceIds)->delete();
            DB::table('device_credentials')->whereIn('iot_device_id', $deviceIds)->update(['iot_device_id' => null]);

            DB::table('feeder_feed_type_mapping')->whereIn('feeder_id', $feederIds)->delete();
            DB::table('feeding_logs')
                ->whereIn('feeder_id', $feederIds)
                ->orWhereIn('pen_id', $penIds)
                ->delete();
            DB::table('feeding_queue')
                ->whereIn('feeder_id', $feederIds)
                ->orWhereIn('hog_pen_id', $penIds)
                ->delete();

            DB::table('feeding_schedule')->whereIn('hog_pen_id', $penIds)->delete();
            DB::table('feeding_predictions')->whereIn('hog_pen_id', $penIds)->delete();
            DB::table('prediction_cache')->whereIn('pen_id', $penIds)->delete();
            DB::table('hog_daily_records')
                ->whereIn('hog_id', $hogIds)
                ->orWhereIn('hog_pen_id', $penIds)
                ->delete();

            DB::table('alerts')
                ->where('farm_id', $farm->id)
                ->orWhereIn('hog_pen_id', $penIds)
                ->delete();
            DB::table('daily_farm_reports')->where('farm_id', $farm->id)->delete();
            DB::table('webhook_logs')->where('farm_id', $farm->id)->delete();
            DB::table('sensors')->whereIn('id', $sensorIds)->delete();
            DB::table('feeders')->whereIn('id', $feederIds)->delete();
            DB::table('iot_devices')->whereIn('id', $deviceIds)->delete();
            DB::table('hogs')->whereIn('id', $hogIds)->delete();
            DB::table('hog_pens')->whereIn('id', $penIds)->delete();

            $farm->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $home
     * @param  array<string, mixed>  $payload
     */
    private function sinricHomeMatchesPayload(array $home, array $payload): bool
    {
        $name = $payload['name'] ?? null;

        if (is_string($name) && $name !== '' && $this->homeString($home, ['name']) !== $name) {
            return false;
        }

        $imageUrl = $payload['imageUrl'] ?? null;

        if (is_string($imageUrl) && $imageUrl !== '') {
            $homeImageUrl = $this->homeString($home, ['imageUrl', 'image_url']);

            if ($homeImageUrl !== null && $homeImageUrl !== $imageUrl) {
                return false;
            }
        }

        return true;
    }

    private function hasSinricToken(User $user): bool
    {
        return is_string($user->access_token) && $user->access_token !== '';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sinricError(array $result, string $fallbackMessage): JsonResponse
    {
        return ApiResponse::error(
            message: (string) ($result['message'] ?? $fallbackMessage),
            error: $result['error'] ?? null,
            status: (int) ($result['status'] ?? 502),
        );
    }
}
