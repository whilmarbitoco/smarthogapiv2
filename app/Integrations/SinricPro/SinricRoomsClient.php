<?php

namespace App\Integrations\SinricPro;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SinricRoomsClient
{
    /**
     * @return array<string, mixed>
     */
    public function rooms(User $user): array
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
            $response = Http::baseUrl((string) config('services.sinric.base_url'))
                ->acceptJson()
                ->withToken($accessToken)
                ->timeout((int) config('services.sinric.timeout'))
                ->connectTimeout((int) config('services.sinric.connect_timeout'))
                ->get('/rooms');

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => data_get($payload, 'message', 'Sinric rooms request failed.'),
                    'status' => $response->status(),
                ];
            }

            $rooms = data_get($payload, 'rooms', data_get($payload, 'data.rooms', []));

            return [
                'success' => is_array($rooms),
                'rooms' => is_array($rooms) ? $rooms : [],
                'status' => 200,
            ];
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'message' => 'Sinric rooms service is unavailable.',
                'status' => 503,
                'error' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Sinric rooms sync failed.',
                'status' => 500,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        return $this->request($user, 'POST', '/rooms', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, string $roomId, array $data): array
    {
        return $this->request($user, 'PUT', '/rooms', array_merge(['id' => $roomId], $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, string $roomId): array
    {
        return $this->request($user, 'DELETE', '/rooms/'.$roomId);
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
                ->acceptJson()
                ->asForm()
                ->withToken($accessToken)
                ->timeout((int) config('services.sinric.timeout'))
                ->connectTimeout((int) config('services.sinric.connect_timeout'));

            $response = match ($method) {
                'DELETE' => $pendingRequest->delete($endpoint),
                'POST' => $pendingRequest->post($endpoint, $data),
                'PUT' => $pendingRequest->put($endpoint, $data),
                default => throw new \InvalidArgumentException('Unsupported Sinric rooms method.'),
            };

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => data_get($payload, 'message', 'Sinric rooms request failed.'),
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
                'message' => 'Sinric rooms service is unavailable.',
                'status' => 503,
                'error' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Sinric rooms request failed.',
                'status' => 500,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
