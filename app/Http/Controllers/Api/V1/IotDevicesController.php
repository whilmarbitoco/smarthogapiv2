<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IotDevices\SyncSinricDevicesAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\IotDevicesRequest;
use App\Http\Resources\IotDeviceResource;
use App\Http\Responses\ApiResponse;
use App\Integrations\SinricPro\SinricDevicesClient;
use App\Models\HogPens;
use App\Models\IotDevices;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IotDevicesController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string { return IotDevices::class; }
    protected function resourceClass(): string { return IotDeviceResource::class; }
    protected function relationships(): array { return ['hogPen']; }
    protected function ownedParentFields(): array { return ['hog_pen_id' => HogPens::class]; }

    public function index(SyncSinricDevicesAction $syncSinricDevicesAction): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $syncSinricDevicesAction->execute($user);
        }

        return $this->crudIndex();
    }

    public function store(IotDevicesRequest $request, SinricDevicesClient $sinricDevicesClient): JsonResponse
    {
        $data = $request->validated();
        $this->assertOwnedParents($data);

        $hogPen = HogPens::query()->findOrFail($data['hog_pen_id']);
        $user = auth()->user();

        if ($user instanceof User && $this->hasSinricToken($user) && $this->isSinricHogPen($hogPen)) {
            $result = $sinricDevicesClient->create($user, $this->sinricDevicePayload($data, $hogPen));

            if (! ($result['success'] ?? false)) {
                return $this->sinricError($result, 'Sinric device creation failed.');
            }

            $data = $this->mergeSinricDeviceData($data, $result);
        }

        return $this->crudStore($this->localIotDeviceData($data));
    }

    public function show(IotDevices $iotDevice, SinricDevicesClient $sinricDevicesClient): JsonResponse
    {
        $this->syncDeviceFromSinric($iotDevice, $sinricDevicesClient);

        return $this->crudShow($iotDevice->refresh());
    }

    public function update(IotDevicesRequest $request, IotDevices $iotDevice, SinricDevicesClient $sinricDevicesClient): JsonResponse
    {
        $this->authorizeOwnedModel($iotDevice);

        $data = $request->validated();
        $this->assertOwnedParents($data);

        $hogPen = $this->targetHogPen($iotDevice, $data);
        $user = auth()->user();

        if ($user instanceof User && $this->hasSinricToken($user) && $this->isSinricDevice($iotDevice)) {
            if (! $this->isSinricHogPen($hogPen)) {
                return ApiResponse::error('The selected hog pen is not linked to a Sinric room.', null, 422);
            }

            $result = $sinricDevicesClient->update(
                $user,
                (string) $iotDevice->external_device_id,
                $this->sinricDevicePayload($data, $hogPen, $iotDevice),
            );

            if (! ($result['success'] ?? false)) {
                return $this->sinricError($result, 'Sinric device update failed.');
            }

            $data = $this->mergeSinricDeviceData($data, $result, $iotDevice);
        }

        return $this->crudUpdate($iotDevice, $this->localIotDeviceData($data, $iotDevice));
    }

    public function action(Request $request, string $deviceId, SinricDevicesClient $sinricDevicesClient): JsonResponse
    {
        $user = auth()->user();

        if (! ($user instanceof User)) {
            return ApiResponse::error('Unauthenticated.', null, 401);
        }

        if (! $this->hasSinricToken($user)) {
            return ApiResponse::error('Missing Sinric access token.', null, 422);
        }

        $result = $sinricDevicesClient->action($user, $deviceId, $request->query());

        if (! ($result['success'] ?? false)) {
            return ApiResponse::error(
                (string) data_get($result, 'message', 'Sinric device action failed.'),
                $result,
                (int) ($result['status'] ?? 500),
            );
        }

        return ApiResponse::success($result, 'Device action sent successfully.');
    }

    public function destroy(IotDevices $iotDevice, SinricDevicesClient $sinricDevicesClient): JsonResponse
    {
        $this->authorizeOwnedModel($iotDevice);

        $user = auth()->user();

        if ($user instanceof User && $this->hasSinricToken($user) && $this->isSinricDevice($iotDevice)) {
            $result = $sinricDevicesClient->delete($user, (string) $iotDevice->external_device_id);

            if (! ($result['success'] ?? false)) {
                return $this->sinricError($result, 'Sinric device deletion failed.');
            }
        }

        return $this->crudDestroy($iotDevice);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sinricDevicePayload(array $data, HogPens $hogPen, ?IotDevices $iotDevice = null): array
    {
        $metadata = is_array($iotDevice?->external_metadata) ? $iotDevice->external_metadata : [];
        $payload = [
            'name' => $data['name'] ?? $metadata['name'] ?? $data['type'] ?? $iotDevice?->type,
            'productId' => $data['productId'] ?? data_get($metadata, 'product.id'),
            'roomId' => $hogPen->external_room_id ?? $data['roomId'] ?? data_get($metadata, 'room.id'),
        ];

        foreach ([
            'description',
            'macAddress',
            'lastConnectedSSID',
            'hwVersion',
            'swVersion',
            'serialNumber',
            'lastIpAddress',
            'customData',
            'accessKeyId',
            'alias',
        ] as $field) {
            $value = $data[$field] ?? $metadata[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $payload[$field] = $value;
            } elseif (is_array($value) && $value !== []) {
                $payload[$field] = $value;
            }
        }

        foreach ($this->sinricAttributes($data['attributes'] ?? $metadata['attributes'] ?? null) as $key => $value) {
            $payload[$key] = $value;
        }

        return array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function mergeSinricDeviceData(array $data, array $result, ?IotDevices $iotDevice = null): array
    {
        $device = $this->resultDevice($result);
        $metadata = array_merge(is_array($iotDevice?->external_metadata) ? $iotDevice->external_metadata : [], $device);

        foreach ([
            'name',
            'description',
            'productId',
            'roomId',
            'macAddress',
            'lastConnectedSSID',
            'hwVersion',
            'swVersion',
            'serialNumber',
            'lastIpAddress',
            'customData',
            'accessKeyId',
            'alias',
            'attributes',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $metadata[$field] = $data[$field];
            }
        }

        $deviceId = $this->deviceString($device, ['id', '_id', 'deviceId', 'device_id']) ?? $iotDevice?->external_device_id;

        if (is_string($deviceId) && $deviceId !== '') {
            $data['external_provider'] = 'sinric';
            $data['external_device_id'] = $deviceId;
        }

        $data['external_metadata'] = $metadata;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function localIotDeviceData(array $data, ?IotDevices $iotDevice = null): array
    {
        $metadata = is_array($data['external_metadata'] ?? null) ? $data['external_metadata'] : [];

        foreach ([
            'name',
            'description',
            'productId',
            'roomId',
            'macAddress',
            'lastConnectedSSID',
            'hwVersion',
            'swVersion',
            'serialNumber',
            'lastIpAddress',
            'customData',
            'accessKeyId',
            'alias',
            'attributes',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $metadata[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        $data['type'] = $data['type'] ?? $this->deviceType($metadata) ?? $iotDevice?->type ?? 'sinric-device';
        $data['api_provider'] = $data['api_provider']
            ?? $iotDevice?->api_provider
            ?? (($data['external_provider'] ?? null) === 'sinric' ? 'sinric' : 'local');
        $data['status'] = $data['status'] ?? $this->deviceStatus($metadata) ?? $iotDevice?->status ?? 'offline';

        if ($metadata !== []) {
            $data['external_metadata'] = $metadata;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function targetHogPen(IotDevices $iotDevice, array $data): HogPens
    {
        if (isset($data['hog_pen_id'])) {
            return HogPens::query()->findOrFail($data['hog_pen_id']);
        }

        return $iotDevice->hogPen()->firstOrFail();
    }

    private function syncDeviceFromSinric(IotDevices $iotDevice, SinricDevicesClient $sinricDevicesClient): void
    {
        $this->authorizeOwnedModel($iotDevice);

        $user = auth()->user();

        if (! ($user instanceof User) || ! $this->hasSinricToken($user) || ! $this->isSinricDevice($iotDevice)) {
            return;
        }

        $result = $sinricDevicesClient->device($user, (string) $iotDevice->external_device_id);
        $device = $this->resultDevice($result);

        if (! ($result['success'] ?? false) || $device === []) {
            return;
        }

        $iotDevice->update($this->localIotDeviceData([
            'external_metadata' => $device,
        ], $iotDevice));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function resultDevice(array $result): array
    {
        $device = data_get($result, 'device', data_get($result, 'data.device'));

        if (! is_array($device)) {
            $device = data_get($result, 'devices', data_get($result, 'data.devices'));
        }

        return is_array($device) ? $device : [];
    }

    /**
     * @return array<string, string>
     */
    private function sinricAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $payload = [];

        foreach (array_values($attributes) as $index => $attribute) {
            $payload['attributes['.$index.']'] = is_array($attribute) ? json_encode($attribute) : (string) $attribute;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $device
     * @param  list<string>  $keys
     */
    private function deviceString(array $device, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $device[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function deviceType(array $metadata): ?string
    {
        $product = $metadata['product'] ?? null;

        if (is_array($product)) {
            $type = $this->deviceString($product, ['code', 'name']);

            if ($type !== null) {
                return $type;
            }
        }

        return $this->deviceString($metadata, ['type', 'name']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function deviceStatus(array $metadata): ?string
    {
        if (($metadata['isOnline'] ?? null) === true) {
            return 'online';
        }

        if (($metadata['isOnline'] ?? null) === false) {
            return 'offline';
        }

        return null;
    }

    private function isSinricHogPen(HogPens $hogPen): bool
    {
        return $hogPen->external_provider === 'sinric'
            && is_string($hogPen->external_room_id)
            && $hogPen->external_room_id !== '';
    }

    private function isSinricDevice(IotDevices $iotDevice): bool
    {
        return $iotDevice->external_provider === 'sinric'
            && is_string($iotDevice->external_device_id)
            && $iotDevice->external_device_id !== '';
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
