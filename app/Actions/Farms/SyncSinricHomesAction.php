<?php

namespace App\Actions\Farms;

use App\Integrations\SinricPro\SinricHomesClient;
use App\Models\Farms;
use App\Models\User;

class SyncSinricHomesAction
{
    public function __construct(private SinricHomesClient $sinricHomesClient) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, ?string $timezone = null): array
    {
        $result = $this->sinricHomesClient->homes($user);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $synced = 0;
        $defaultTimezone = $this->defaultTimezone($timezone);

        foreach ($result['homes'] ?? [] as $home) {
            if (! is_array($home)) {
                continue;
            }

            $homeId = $this->homeString($home, ['id', '_id', 'homeId', 'home_id']);

            if ($homeId === null) {
                continue;
            }

            Farms::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'external_provider' => 'sinric',
                    'external_home_id' => $homeId,
                ],
                [
                    'location' => $this->homeString($home, ['name']) ?? 'Sinric Home '.$homeId,
                    'timezone' => $this->homeString($home, ['timeZone', 'timezone']) ?? $defaultTimezone,
                    'external_metadata' => $home,
                ],
            );

            $synced++;
        }

        return [
            'success' => true,
            'synced' => $synced,
            'status' => 200,
        ];
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

    private function defaultTimezone(?string $timezone): string
    {
        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        $configuredTimezone = config('app.timezone', 'UTC');

        return is_string($configuredTimezone) && $configuredTimezone !== '' ? $configuredTimezone : 'UTC';
    }
}
