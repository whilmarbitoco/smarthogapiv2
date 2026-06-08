<?php

namespace App\Actions\HogPens;

use App\Actions\Farms\SyncSinricHomesAction;
use App\Integrations\SinricPro\SinricRoomsClient;
use App\Models\Farms;
use App\Models\HogPens;
use App\Models\User;

class SyncSinricRoomsAction
{
    public function __construct(
        private SinricRoomsClient $sinricRoomsClient,
        private SyncSinricHomesAction $syncSinricHomesAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, ?string $timezone = null): array
    {
        $this->syncSinricHomesAction->execute($user, $timezone);

        $result = $this->sinricRoomsClient->rooms($user);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $synced = 0;

        foreach ($result['rooms'] ?? [] as $room) {
            if (! is_array($room)) {
                continue;
            }

            $roomId = $this->roomString($room, ['id', '_id', 'roomId', 'room_id']);
            $homeId = $this->homeId($room);

            if ($roomId === null || $homeId === null) {
                continue;
            }

            $farm = Farms::query()
                ->where('user_id', $user->id)
                ->where('external_provider', 'sinric')
                ->where('external_home_id', $homeId)
                ->first();

            if (! $farm instanceof Farms) {
                continue;
            }

            $existingHogPen = HogPens::query()
                ->where('farm_id', $farm->id)
                ->where('external_provider', 'sinric')
                ->where('external_room_id', $roomId)
                ->first();

            $updateData = [
                'name' => $this->roomString($room, ['name']) ?? 'Sinric Room '.$roomId,
                'external_metadata' => $room,
            ];

            $roomCapacity = $this->capacity($room);

            if (! $existingHogPen instanceof HogPens) {
                $updateData['status'] = 1;
                $updateData['capacity'] = $roomCapacity;
            } elseif ($roomCapacity > 0) {
                $updateData['capacity'] = $roomCapacity;
            }

            HogPens::query()->updateOrCreate(
                [
                    'farm_id' => $farm->id,
                    'external_provider' => 'sinric',
                    'external_room_id' => $roomId,
                ],
                $updateData,
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

    /**
     * @param  array<string, mixed>  $room
     */
    private function homeId(array $room): ?string
    {
        $home = $room['home'] ?? null;

        if (is_array($home)) {
            return $this->roomString($home, ['id', '_id', 'homeId', 'home_id']);
        }

        return $this->roomString($room, ['homeId', 'home_id', 'home']);
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function capacity(array $room): int
    {
        $capacity = $room['capacity'] ?? null;

        if (is_int($capacity)) {
            return max(0, $capacity);
        }

        $description = $this->roomString($room, ['description']);

        if ($description !== null && preg_match('/(\d+)\s*hog/i', $description, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
