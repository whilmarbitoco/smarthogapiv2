<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\HogPens\SyncSinricRoomsAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\HogPensRequest;
use App\Http\Resources\HogPenResource;
use App\Http\Resources\HogPenSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Integrations\SinricPro\SinricRoomsClient;
use App\Models\Farms;
use App\Models\HogPens;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HogPensController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string
    {
        return HogPens::class;
    }

    protected function resourceClass(): string
    {
        return HogPenResource::class;
    }

    protected function relationships(): array
    {
        return ['farm'];
    }

    protected function ownedParentFields(): array
    {
        return ['farm_id' => Farms::class];
    }

    public function summary(int|string $farmId): JsonResponse
    {
        $farm = Farms::query()->findOrFail($farmId);

        abort_unless($farm->belongsToUser((int) auth()->id()), 403);

        $hogPens = HogPens::query()
            ->where('farm_id', $farm->id)
            ->select([
                'id',
                'farm_id',
                'name',
                'capacity',
                'status',
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => HogPenSummaryResource::collection($hogPens),
        ]);
    }

    public function index(SyncSinricRoomsAction $syncSinricRoomsAction): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $syncSinricRoomsAction->execute($user);
        }

        return $this->crudIndex();
    }

    public function store(HogPensRequest $request, SinricRoomsClient $sinricRoomsClient): JsonResponse
    {
        $data = $request->validated();
        $this->assertOwnedParents($data);

        $farm = Farms::query()->findOrFail($data['farm_id']);
        $user = auth()->user();

        if ($this->isSinricFarm($farm)) {
            if (! ($user instanceof User) || ! $this->hasSinricToken($user)) {
                return ApiResponse::error('Missing Sinric access token.', null, 422);
            }

            $result = $sinricRoomsClient->create($user, $this->sinricRoomPayload($data, $farm));

            if (! ($result['success'] ?? false)) {
                return $this->sinricError($result, 'Sinric room creation failed.');
            }

            $data = $this->mergeSinricRoomData($data, $result);
        }

        return $this->crudStore($this->localHogPenData($data));
    }

    public function show(HogPens $hogPen, SyncSinricRoomsAction $syncSinricRoomsAction): JsonResponse
    {
        $this->syncLinkedHogPenFromSinric($hogPen, $syncSinricRoomsAction);

        return $this->crudShow($hogPen->refresh());
    }

    public function update(HogPensRequest $request, HogPens $hogPen, SinricRoomsClient $sinricRoomsClient): JsonResponse
    {
        $this->authorizeOwnedModel($hogPen);

        $data = $request->validated();
        $this->assertOwnedParents($data);

        $farm = $this->targetFarm($hogPen, $data);
        $user = auth()->user();

        if ($this->isSinricFarm($farm)) {
            if (! ($user instanceof User) || ! $this->hasSinricToken($user)) {
                return ApiResponse::error('Missing Sinric access token.', null, 422);
            }

            if ($this->isSinricHogPen($hogPen)) {
                $result = $sinricRoomsClient->update(
                    $user,
                    (string) $hogPen->external_room_id,
                    $this->sinricRoomPayload($data, $farm, $hogPen),
                );

                if (! ($result['success'] ?? false)) {
                    return $this->sinricError($result, 'Sinric room update failed.');
                }

                $data = $this->mergeSinricRoomData($data, $result, $hogPen);
            } else {
                $result = $sinricRoomsClient->create($user, $this->sinricRoomPayload($data, $farm, $hogPen));

                if (! ($result['success'] ?? false)) {
                    return $this->sinricError($result, 'Sinric room creation failed.');
                }

                $data = $this->mergeSinricRoomData($data, $result, $hogPen);
            }
        } elseif ($this->isSinricHogPen($hogPen)) {
            return ApiResponse::error('The selected farm is not linked to a Sinric home.', null, 422);
        }

        return $this->crudUpdate($hogPen, $this->localHogPenData($data, $hogPen));
    }

    public function updateBySinricRoom(HogPensRequest $request, SinricRoomsClient $sinricRoomsClient): JsonResponse
    {
        $hogPen = $this->hogPenFromSinricRoomRequest($request);

        return $this->update($request, $hogPen, $sinricRoomsClient);
    }

    public function destroy(HogPens $hogPen, SinricRoomsClient $sinricRoomsClient): JsonResponse
    {
        $this->authorizeOwnedModel($hogPen);

        $user = auth()->user();

        if ($this->isSinricHogPen($hogPen)) {
            if (! ($user instanceof User) || ! $this->hasSinricToken($user)) {
                return ApiResponse::error('Missing Sinric access token.', null, 422);
            }

            $result = $sinricRoomsClient->delete($user, (string) $hogPen->external_room_id);

            if (! ($result['success'] ?? false) && ! $this->sinricRoomAlreadyDeleted($result)) {
                return $this->sinricError($result, 'Sinric room deletion failed.');
            }
        }

        $this->deleteHogPenLocally($hogPen);

        return ApiResponse::deleted($this->resourceName().' deleted successfully');
    }

    public function destroyBySinricRoom(Request $request, SinricRoomsClient $sinricRoomsClient, ?string $roomId = null): JsonResponse
    {
        $hogPen = $this->hogPenFromSinricRoomRequest($request, $roomId);

        return $this->destroy($hogPen, $sinricRoomsClient);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sinricRoomPayload(array $data, Farms $farm, ?HogPens $hogPen = null): array
    {
        $metadata = is_array($hogPen?->external_metadata) ? $hogPen->external_metadata : [];
        $payload = [
            'name' => $data['name'] ?? $hogPen?->name,
            'homeId' => $farm->external_home_id,
        ];

        $description = $data['description'] ?? $metadata['description'] ?? null;
        $imageUrl = $data['imageUrl'] ?? $metadata['imageUrl'] ?? null;

        if (is_string($description) && $description !== '') {
            $payload['description'] = $description;
        }

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
    private function mergeSinricRoomData(array $data, array $result, ?HogPens $hogPen = null): array
    {
        $room = data_get($result, 'room', data_get($result, 'data.room'));
        $room = is_array($room) ? $room : [];
        $metadata = array_merge(is_array($hogPen?->external_metadata) ? $hogPen->external_metadata : [], $room);

        foreach (['description', 'imageUrl'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $metadata[$field] = $data[$field];
            }
        }

        $roomId = $this->roomString($room, ['id', '_id', 'roomId', 'room_id']) ?? $hogPen?->external_room_id;

        if (is_string($roomId) && $roomId !== '') {
            $data['external_provider'] = 'sinric';
            $data['external_room_id'] = $roomId;
        }

        $data['external_metadata'] = $metadata;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function localHogPenData(array $data, ?HogPens $hogPen = null): array
    {
        $metadata = is_array($data['external_metadata'] ?? null) ? $data['external_metadata'] : [];

        foreach (['description', 'imageUrl'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $metadata[$field] = $data[$field];
            }
        }

        // Only extract capacity from description if there's no existing capacity to preserve
        if (isset($data['capacity'])) {
            $capacity = $data['capacity'];
        } elseif ($hogPen?->capacity !== null && $hogPen->capacity > 0) {
            // Preserve existing capacity if it's already set
            $capacity = $hogPen->capacity;
        } else {
            // Only extract from description if creating new or no capacity exists
            $capacity = $this->capacityFromDescription($data['description'] ?? null);
        }

        $status = $data['status'] ?? $hogPen?->status ?? 1;

        unset($data['description'], $data['imageUrl']);

        $data['capacity'] = is_numeric($capacity) ? max(0, (int) $capacity) : 0;
        $data['status'] = is_numeric($status) ? (int) $status : 1;

        if ($metadata !== []) {
            $data['external_metadata'] = $metadata;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function targetFarm(HogPens $hogPen, array $data): Farms
    {
        if (isset($data['farm_id'])) {
            return Farms::query()->findOrFail($data['farm_id']);
        }

        return $hogPen->farm()->firstOrFail();
    }

    private function syncLinkedHogPenFromSinric(HogPens $hogPen, SyncSinricRoomsAction $syncSinricRoomsAction): void
    {
        $this->authorizeOwnedModel($hogPen);

        $user = auth()->user();

        if (! ($user instanceof User) || ! $this->hasSinricToken($user) || ! $this->isSinricHogPen($hogPen)) {
            return;
        }

        $syncSinricRoomsAction->execute($user);
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<string>  $keys
     */
    private function roomString(array $room, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $room[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function capacityFromDescription(mixed $description): int
    {
        if (is_string($description) && preg_match('/(\d+)\s*hog/i', $description, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function isSinricFarm(Farms $farm): bool
    {
        return $farm->external_provider === 'sinric'
            && is_string($farm->external_home_id)
            && $farm->external_home_id !== '';
    }

    private function isSinricHogPen(HogPens $hogPen): bool
    {
        return $hogPen->external_provider === 'sinric'
            && is_string($hogPen->external_room_id)
            && $hogPen->external_room_id !== '';
    }

    private function hasSinricToken(User $user): bool
    {
        return is_string($user->access_token) && $user->access_token !== '';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sinricRoomAlreadyDeleted(array $result): bool
    {
        $status = (int) ($result['status'] ?? 0);

        return in_array($status, [404, 410, 422], true);
    }

    private function deleteHogPenLocally(HogPens $hogPen): void
    {
        $this->authorizeOwnedModel($hogPen);

        DB::transaction(function () use ($hogPen): void {
            $deviceIds = DB::table('iot_devices')
                ->where('hog_pen_id', $hogPen->id)
                ->pluck('id');
            $feederIds = DB::table('feeders')
                ->where('hog_pen_id', $hogPen->id)
                ->pluck('id');
            $sensorIds = DB::table('sensors')
                ->where('hog_pen_id', $hogPen->id)
                ->pluck('id');
            $hogIds = DB::table('hogs')
                ->where('hog_pen_id', $hogPen->id)
                ->pluck('id');

            DB::table('sensor_readings')->whereIn('sensor_id', $sensorIds)->delete();
            DB::table('device_logs')->whereIn('device_id', $deviceIds)->delete();
            DB::table('device_commands')->whereIn('iot_device_id', $deviceIds)->delete();
            DB::table('device_credentials')->whereIn('iot_device_id', $deviceIds)->update(['iot_device_id' => null]);

            DB::table('feeder_feed_type_mapping')->whereIn('feeder_id', $feederIds)->delete();
            DB::table('feeding_logs')
                ->whereIn('feeder_id', $feederIds)
                ->orWhere('pen_id', $hogPen->id)
                ->delete();
            DB::table('feeding_queue')
                ->whereIn('feeder_id', $feederIds)
                ->orWhere('hog_pen_id', $hogPen->id)
                ->delete();

            DB::table('feeding_schedule')->where('hog_pen_id', $hogPen->id)->delete();
            DB::table('feeding_predictions')->where('hog_pen_id', $hogPen->id)->delete();
            DB::table('prediction_cache')->where('pen_id', $hogPen->id)->delete();
            DB::table('hog_daily_records')
                ->whereIn('hog_id', $hogIds)
                ->orWhere('hog_pen_id', $hogPen->id)
                ->delete();
            DB::table('alerts')->where('hog_pen_id', $hogPen->id)->delete();

            DB::table('sensors')->whereIn('id', $sensorIds)->delete();
            DB::table('feeders')->whereIn('id', $feederIds)->delete();
            DB::table('iot_devices')->whereIn('id', $deviceIds)->delete();
            DB::table('hogs')->whereIn('id', $hogIds)->delete();

            $hogPen->delete();
        });
    }

    private function hogPenFromSinricRoomRequest(Request $request, ?string $roomId = null): HogPens
    {
        $roomId = $roomId
            ?? $request->input('external_room_id')
            ?? $request->input('id')
            ?? $request->input('roomId')
            ?? $request->input('room_id');

        abort_unless(is_string($roomId) && $roomId !== '', 422, 'The Sinric room ID is required.');

        $hogPen = HogPens::query()
            ->where('external_provider', 'sinric')
            ->where('external_room_id', $roomId)
            ->first();

        if (! $hogPen instanceof HogPens && ctype_digit($roomId)) {
            $hogPen = HogPens::query()->find($roomId);
        }

        abort_unless($hogPen instanceof HogPens, 404);

        return $hogPen;
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
