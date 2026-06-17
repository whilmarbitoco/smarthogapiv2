<?php

namespace App\Integrations\SinricPro;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SinricDevicesClient
{
    /**
     * @return array<string, mixed>
     */
    public function devices(User $user): array
    {
        return $this->request($user, 'GET', '/devices');
    }

    /**
     * @return array<string, mixed>
     */
    public function device(User $user, string $deviceId): array
    {
        return $this->request($user, 'GET', '/devices/'.$deviceId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        return $this->request($user, 'POST', '/devices', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, string $deviceId, array $data): array
    {
        return $this->request($user, 'PUT', '/devices', array_merge(['id' => $deviceId], $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, string $deviceId): array
    {
        return $this->request($user, 'DELETE', '/devices/'.$deviceId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function find(User $user, array $data): array
    {
        return $this->request($user, 'POST', '/devices/find', $data);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function action(User $user, string $deviceId, array $query = []): array
    {
        return $this->request($user, 'POST', '/devices/'.$deviceId.'/action', $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(User $user, string $method, string $endpoint, array $data = []): array
    {
        $accessToken = is_string($user->access_token) ? $user->access_token : '';

        if ($accessToken === '') {
            return [
                'success' => false,
                'message' => 'Missing Sinric access token.',
                'status' => 400,
            ];
        }

        try {
            $pendingRequest = Http::baseUrl((string) config('services.sinric.base_url'))
                ->retry(2, 100)
                ->acceptJson()
                ->asForm()
                ->withToken($accessToken)
                ->timeout((int) config('services.sinric.timeout'))
                ->connectTimeout((int) config('services.sinric.connect_timeout'));

            $response = match ($method) {
                'DELETE' => $pendingRequest->delete($endpoint),
                'GET' => $pendingRequest->get($endpoint, $data),
                'POST' => $pendingRequest->post($endpoint, $data),
                'PUT' => $pendingRequest->put($endpoint, $data),
                default => throw new \InvalidArgumentException('Unsupported Sinric devices method.'),
            };

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => data_get($payload, 'message', 'Sinric devices request failed.'),
                    'status' => $response->status(),
                ];
            }

            return array_merge($payload, [
                'success' => (bool) ($payload['success'] ?? true),
                'status' => $response->status(),
            ]);
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'message' => 'Sinric devices service is unavailable.',
                'status' => 503,
                'error' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Sinric devices request failed.',
                'status' => 500,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
