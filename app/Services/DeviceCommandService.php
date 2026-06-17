<?php

namespace App\Services;

use App\Integrations\SinricPro\SinricDevicesClient;
use App\Models\DeviceCommands;
use App\Models\IotDevices;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeviceCommandService
{
    public function __construct(private SinricDevicesClient $sinricDevicesClient) {}

    /**
     * @return array{provider: string, command_id: int}
     */
    public function sendFeedCommand(string $deviceId, float $feedQuantity): array
    {
        $device = IotDevices::query()
            ->whereKey($deviceId)
            ->orWhere('external_device_id', $deviceId)
            ->first();

        if (! $device) {
            throw new RuntimeException("Device [{$deviceId}] was not found.");
        }

        $payload = [
            'device_id' => (string) $device->id,
            'external_device_id' => $device->external_device_id,
            'feed_quantity' => $feedQuantity,
            'requested_at' => now()->toISOString(),
        ];

        $provider = $this->resolveBestAvailableProvider($device, $payload);

        $command = DeviceCommands::query()->create([
            'iot_device_id' => $device->id,
            'action' => 'feed',
            'payload' => $payload,
            'status' => 'processing',
        ]);

        try {
            $this->sendViaBestAvailableProvider($device, $payload);

            $command->update([
                'payload' => array_merge($payload, ['provider' => $provider]),
                'status' => 'completed',
                'executed_at' => now(),
            ]);

            return [
                'provider' => $provider,
                'command_id' => $command->id,
            ];
        } catch (\Throwable $exception) {
            $command->update([
                'payload' => array_merge($payload, [
                    'error' => $exception->getMessage(),
                ]),
                'status' => 'failed',
                'executed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function resolveBestAvailableProvider(IotDevices $device, array $payload): string
    {
        if (config('services.feeding_devices.mqtt.endpoint')) {
            return 'mqtt';
        }

        if ($device->external_provider === 'sinric') {
            return 'sinric';
        }

        if (config('services.feeding_devices.sinric.endpoint')) {
            return 'sinric';
        }

        $deviceEndpoint = data_get($device->external_metadata, 'command_url')
            ?? config('services.feeding_devices.http.endpoint');

        if ($deviceEndpoint) {
            return 'http';
        }

        throw new RuntimeException('No device provider configured for feed command.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendViaBestAvailableProvider(IotDevices $device, array $payload): string
    {
        if ($endpoint = config('services.feeding_devices.mqtt.endpoint')) {
            $this->postCommand($endpoint, [
                'topic' => config('services.feeding_devices.mqtt.topic'),
                'payload' => $payload,
            ], config('services.feeding_devices.mqtt.token'));

            return 'mqtt';
        }

        if ($this->isSinricDevice($device)) {
            $this->sendViaSinric($device, $payload);

            return 'sinric';
        }

        if ($endpoint = config('services.feeding_devices.sinric.endpoint')) {
            $this->postCommand($endpoint, $payload, config('services.feeding_devices.sinric.token'));

            return 'sinric';
        }

        $deviceEndpoint = data_get($device->external_metadata, 'command_url')
            ?? config('services.feeding_devices.http.endpoint');

        if ($deviceEndpoint) {
            $this->postCommand($deviceEndpoint, $payload, config('services.feeding_devices.http.token'));

            return 'http';
        }

        throw new RuntimeException(
            "No command provider available for device [{$device->id}]. " .
            "Configure FEEDING_MQTT_ENDPOINT, FEEDING_SINRIC_ENDPOINT, or FEEDING_HTTP_ENDPOINT, " .
            "or attach the device to SinricPro."
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendViaSinric(IotDevices $device, array $payload): void
    {
        $user = $device->user;

        if (! $user) {
            throw new RuntimeException("Device [{$device->id}] has no associated user for SinricPro command.");
        }

        $externalDeviceId = $device->external_device_id;

        if (! is_string($externalDeviceId) || $externalDeviceId === '') {
            throw new RuntimeException("Device [{$device->id}] is missing external_device_id for SinricPro command.");
        }

        $result = $this->sinricDevicesClient->action($user, (string) $externalDeviceId, [
            'action' => 'setPowerState',
            'value' => 'on',
        ]);

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException(
                data_get($result, 'message', 'SinricPro device command failed.')
            );
        }
    }

    private function isSinricDevice(IotDevices $device): bool
    {
        return $device->external_provider === 'sinric'
            && is_string($device->external_device_id)
            && $device->external_device_id !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postCommand(string $endpoint, array $payload, ?string $token): void
    {
        $request = Http::timeout(10);

        if ($token) {
            $request = $request->withToken($token);
        }

        $response = $request->post($endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException("Device command failed with HTTP status {$response->status()}.");
        }
    }
}
