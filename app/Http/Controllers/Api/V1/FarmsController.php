<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Farms\SyncSinricHomesAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\FarmsRequest;
use App\Http\Resources\FarmResource;
use App\Http\Responses\ApiResponse;
use App\Integrations\SinricPro\SinricHomesClient;
use App\Models\Farms;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\FarmSummaryResource;

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
            $result = $sinricHomesClient->create($user, $this->sinricHomePayload($data));

            if (! ($result['success'] ?? false)) {
                return $this->sinricError($result, 'Sinric home creation failed.');
            }

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
            $result = $sinricHomesClient->update(
                $user,
                (string) $farm->external_home_id,
                $this->sinricHomePayload($data, $farm),
            );

            if (! ($result['success'] ?? false)) {
                return $this->sinricError($result, 'Sinric home update failed.');
            }

            $data = $this->mergeSinricHomeData($data, $result, $farm);
        }

        return $this->crudUpdate($farm, $this->localFarmData($data, $farm));
    }

    public function destroy(Farms $farm, SinricHomesClient $sinricHomesClient): JsonResponse
    {
        $this->authorizeOwnedModel($farm);

        $user = auth()->user();

        // updated logic to attempt local deletion if Sinric home deletion fails due to the home not existing or Sinric being unavailable
        if ($user instanceof User && $this->hasSinricToken($user) && $this->isSinricFarm($farm)) {
            $result = $sinricHomesClient->delete($user, (string) $farm->external_home_id);

            if (! ($result['success'] ?? false)) {
                if ($this->canDeleteFarmLocallyAfterSinricFailure($result)) {
                    $this->crudDestroy($farm);

                    return ApiResponse::deleted('Farm deleted locally; Sinric home deletion failed or was already unavailable.');
                }

                return $this->sinricError($result, 'Sinric home deletion failed.');
            }
        }

        return $this->crudDestroy($farm);
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
    private function canDeleteFarmLocallyAfterSinricFailure(array $result): bool
    {
        $status = (int) ($result['status'] ?? 0);
        $message = (string) ($result['message'] ?? '');

        return $status === 404 || $message === 'Sinric homes request failed.';
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
