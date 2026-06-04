<?php

namespace App\Integrations\SinricPro;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SinricHomesClient
{
    /**
     * @return array<string, mixed>
     */
    public function homes(User $user): array
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
                ->retry(2, 100)
                ->acceptJson()
                ->withToken($accessToken)
                ->timeout((int) config('services.sinric.timeout'))
                ->connectTimeout((int) config('services.sinric.connect_timeout'))
                ->get('/homes');

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => data_get($payload, 'message', 'Sinric homes request failed.'),
                    'status' => $response->status(),
                ];
            }

            $homes = data_get($payload, 'homes', data_get($payload, 'data.homes', []));

            return [
                'success' => is_array($homes),
                'homes' => is_array($homes) ? $homes : [],
                'status' => 200,
            ];
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'message' => 'Sinric homes service is unavailable.',
                'status' => 503,
                'error' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Sinric homes sync failed.',
                'status' => 500,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function home(User $user, string $homeId): array
    {
        return $this->request($user, 'GET', '/homes/'.$homeId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        return $this->request($user, 'POST', '/homes', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, string $homeId, array $data): array
    {
        $result = $this->request($user, 'PUT', '/homes/'.$homeId, $data);

        if ($this->shouldRetryLegacyEndpoint($result)) {
            return $this->request($user, 'PUT', '/homes', array_merge(['id' => $homeId], $data));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, string $homeId): array
    {
        $result = $this->request($user, 'DELETE', '/homes/'.$homeId);

        if ($this->shouldRetryLegacyEndpoint($result)) {
            return $this->request($user, 'DELETE', '/homes', ['id' => $homeId]);
        }

        return $result;
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
                ->asJson()
                ->withToken($accessToken)
                ->timeout((int) config('services.sinric.timeout'))
                ->connectTimeout((int) config('services.sinric.connect_timeout'));

            $response = match ($method) {
                'DELETE' => $pendingRequest->delete($endpoint, $data),
                'GET' => $pendingRequest->get($endpoint),
                'POST' => $pendingRequest->post($endpoint, $data),
                'PUT' => $pendingRequest->put($endpoint, $data),
                default => throw new \InvalidArgumentException('Unsupported Sinric homes method.'),
            };

            $payload = $response->json() ?? [];

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => data_get($payload, 'message', 'Sinric homes request failed.'),
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
                'message' => 'Sinric homes service is unavailable.',
                'status' => 503,
                'error' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Sinric homes request failed.',
                'status' => 500,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldRetryLegacyEndpoint(array $result): bool
    {
        return in_array((int) ($result['status'] ?? 0), [404, 405], true);
    }
}
