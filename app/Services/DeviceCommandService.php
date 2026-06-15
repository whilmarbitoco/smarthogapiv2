<?php

namespace App\Services;

use App\Models\DeviceCommands;
use App\Models\IotDevices;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeviceCommandService
{
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

        $command = DeviceCommands::query()->create([
            'iot_device_id' => $device->id,
            'action' => 'feed',
            'payload' => $payload,
            'status' => 'processing',
        ]);

        try {
            $provider = $this->sendViaBestAvailableProvider($device, $payload);

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

        return 'local';
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
