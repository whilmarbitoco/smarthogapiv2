<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\HogPens\SyncSinricRoomsAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\HogPensRequest;
use App\Http\Resources\HogPenResource;
use App\Models\Farms;
use App\Models\HogPens;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class HogPensController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string { return HogPens::class; }
    protected function resourceClass(): string { return HogPenResource::class; }
    protected function relationships(): array { return ['farm']; }
    protected function ownedParentFields(): array { return ['farm_id' => Farms::class]; }

    public function index(SyncSinricRoomsAction $syncSinricRoomsAction): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $syncSinricRoomsAction->execute($user);
        }

        return $this->crudIndex();
    }

    public function store(HogPensRequest $request): JsonResponse { return $this->crudStore($request->validated()); }
    public function show(HogPens $hogPen): JsonResponse { return $this->crudShow($hogPen); }
    public function update(HogPensRequest $request, HogPens $hogPen): JsonResponse { return $this->crudUpdate($hogPen, $request->validated()); }
    public function destroy(HogPens $hogPen): JsonResponse { return $this->crudDestroy($hogPen); }
}
