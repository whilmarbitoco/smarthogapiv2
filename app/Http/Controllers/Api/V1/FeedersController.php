<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedersRequest;
use App\Http\Requests\FeedingQueueRequest;
use App\Http\Resources\FeederResource;
use App\Models\Feeders;
use App\Models\HogPens;
use App\Models\IotDevices;
use Illuminate\Http\JsonResponse;

class FeedersController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string { return Feeders::class; }
    protected function resourceClass(): string { return FeederResource::class; }
    protected function relationships(): array { return ['hogPen', 'iotDevice']; }
    protected function ownedParentFields(): array { return ['hog_pen_id' => HogPens::class, 'device_id' => IotDevices::class]; }

    public function index(): JsonResponse { return $this->crudIndex(); }
    public function store(FeedersRequest $request): JsonResponse { return $this->crudStore($request->validated()); }
    public function show(Feeders $feeder): JsonResponse { return $this->crudShow($feeder); }
    public function update(FeedersRequest $request, Feeders $feeder): JsonResponse { return $this->crudUpdate($feeder, $request->validated()); }
    public function destroy(Feeders $feeder): JsonResponse { return $this->crudDestroy($feeder); }

    public function feed(FeedingQueueRequest $request, Feeders $feeder): JsonResponse
    {
        $this->authorizeOwnedModel($feeder);

        $data = $request->validated();

        $command = \App\Models\DeviceCommands::query()->create([
            'iot_device_id' => $feeder->device_id,
            'action' => 'feed',
            'payload' => [
                'feeder_id' => $feeder->id,
                'feed_quantity' => $data['feed_quantity'] ?? 1.0,
                'requested_at' => now()->toISOString(),
            ],
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Feed command queued.',
            'data' => [
                'command_id' => $command->id,
                'feeder_id' => $feeder->id,
                'feed_quantity' => $data['feed_quantity'] ?? 1.0,
                'status' => 'pending',
            ],
        ], 202);
    }
}
