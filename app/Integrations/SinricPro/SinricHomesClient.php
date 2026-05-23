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
}
