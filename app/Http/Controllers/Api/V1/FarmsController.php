<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Farms\SyncSinricHomesAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\FarmsRequest;
use App\Http\Resources\FarmResource;
use App\Models\Farms;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class FarmsController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string { return Farms::class; }
    protected function resourceClass(): string { return FarmResource::class; }
    protected function relationships(): array { return ['hogPens']; }
    protected function ownedParentFields(): array { return ['user_id' => User::class]; }

    public function index(SyncSinricHomesAction $syncSinricHomesAction): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $syncSinricHomesAction->execute($user);
        }

        return $this->crudIndex();
    }

    public function store(FarmsRequest $request): JsonResponse { return $this->crudStore($request->validated()); }
    public function show(Farms $farm): JsonResponse { return $this->crudShow($farm); }
    public function update(FarmsRequest $request, Farms $farm): JsonResponse { return $this->crudUpdate($farm, $request->validated()); }
    public function destroy(Farms $farm): JsonResponse { return $this->crudDestroy($farm); }

    protected function prepareForCreate(array $data): array
    {
        $data['user_id'] = (int) auth()->id();

        return $data;
    }
}
