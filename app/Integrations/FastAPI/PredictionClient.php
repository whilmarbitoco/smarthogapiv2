<?php

namespace App\Integrations\FastAPI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PredictionClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function predict(array $payload): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout((int) config('services.fastapi.timeout', 30))
                ->connectTimeout((int) config('services.fastapi.connect_timeout', 5))
                ->withHeaders($this->headers())
                ->post('/predict', $payload);
        } catch (ConnectionException $exception) {
            return [
                'success' => false,
                'message' => 'ML prediction service is unavailable.',
                'error' => $exception->getMessage(),
                'status' => 502,
            ];
        }

        $json = $response->json();
        $body = is_array($json) ? $json : [];

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => (string) ($body['error'] ?? 'ML prediction request failed.'),
                'error' => $body,
                'status' => $response->status(),
            ];
        }

        return [
            'success' => true,
            'data' => $body,
            'status' => $response->status(),
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.fastapi.url'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $apiKey = config('services.fastapi.api_key');

        return is_string($apiKey) && $apiKey !== ''
            ? ['x-api-key' => $apiKey]
            : [];
    }
}
