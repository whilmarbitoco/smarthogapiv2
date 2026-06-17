<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

trait HandlesCrud
{
    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * @return class-string<JsonResource>
     */
    abstract protected function resourceClass(): string;

    /**
     * @return array<int, string>
     */
    protected function relationships(): array
    {
        return [];
    }

    /**
     * @return array<string, class-string<Model>>
     */
    protected function ownedParentFields(): array
    {
        return [];
    }

    protected function resource(Model $model): JsonResource
    {
        $resourceClass = $this->resourceClass();

        return new $resourceClass($model);
    }

    public function crudIndex(): JsonResponse
    {
        $model = new ($this->modelClass());
        $query = $this->modelClass()::query()->with($this->relationships())->latest();

        if (method_exists($model, 'scopeOwnedByUser') && auth()->id() !== null) {
            $query->ownedByUser((int) auth()->id());
        }

        $paginator = $query->paginate(25);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Model $model) => $this->resource($model))
        );

        return ApiResponse::paginated($paginator, $this->resourceNamePlural().' retrieved successfully');
    }

    protected function crudStore(array $data): JsonResponse
    {
        $this->assertOwnedParents($data);
        $data = $this->prepareForCreate($data);
        $model = $this->modelClass()::query()->create($data)->load($this->relationships());

        return ApiResponse::created($this->resource($model), $this->resourceName().' created successfully');
        Log::debug('Created new '.$this->resourceName().': '.$model->id);
    }

    protected function crudShow(Model $model): JsonResponse
    {
        $this->authorizeOwnedModel($model);

        return ApiResponse::success(
            $this->resource($model->load($this->relationships())),
            $this->resourceName().' retrieved successfully'
        );
    }

    protected function crudUpdate(Model $model, array $data): JsonResponse
    {
        $this->authorizeOwnedModel($model);
        $this->assertOwnedParents($data);
        $model->update($data);

        return ApiResponse::success(
            $this->resource($model->refresh()->load($this->relationships())),
            $this->resourceName().' updated successfully'
        );
    }

    protected function crudDestroy(Model $model): JsonResponse
    {
        $this->authorizeOwnedModel($model);
        $model->delete();

        return ApiResponse::deleted($this->resourceName().' deleted successfully');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareForCreate(array $data): array
    {
        return $data;
    }

    protected function authorizeOwnedModel(Model $model): void
    {
        if (method_exists($model, 'belongsToUser') && auth()->id() !== null) {
            abort_unless($model->belongsToUser((int) auth()->id()), 403);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertOwnedParents(array $data): void
    {
        foreach ($this->ownedParentFields() as $field => $modelClass) {
            if (! array_key_exists($field, $data) || $data[$field] === null || auth()->id() === null) {
                continue;
            }

            if ($modelClass === User::class) {
                abort_unless((int) $data[$field] === (int) auth()->id(), 403);

                continue;
            }

            $model = $modelClass::query()->find($data[$field]);

            abort_unless($model instanceof Model, 404);

            if (method_exists($model, 'belongsToUser')) {
                abort_unless($model->belongsToUser((int) auth()->id()), 403);
            }
        }
    }

    protected function resourceName(): string
    {
        return str(class_basename($this->modelClass()))->headline()->singular()->toString();
    }

    protected function resourceNamePlural(): string
    {
        return str(class_basename($this->modelClass()))->headline()->plural()->toString();
    }
}
