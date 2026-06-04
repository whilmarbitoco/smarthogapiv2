<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Feeding\GenerateFeedingPredictionAction;
use App\Http\Controllers\Api\V1\Concerns\HandlesCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedingPredictionsRequest;
use App\Http\Requests\GenerateFeedingPredictionRequest;
use App\Http\Resources\FeedingPredictionResource;
use App\Http\Responses\ApiResponse;
use App\Models\FeedingPredictions;
use App\Models\HogPens;
use Illuminate\Http\JsonResponse;

class FeedingPredictionsController extends Controller
{
    use HandlesCrud;

    protected function modelClass(): string
    {
        return FeedingPredictions::class;
    }

    protected function resourceClass(): string
    {
        return FeedingPredictionResource::class;
    }

    protected function relationships(): array
    {
        return ['hogPen', 'mlModel'];
    }

    protected function ownedParentFields(): array
    {
        return ['hog_pen_id' => HogPens::class];
    }

    public function generate(
        GenerateFeedingPredictionRequest $request,
        GenerateFeedingPredictionAction $action,
    ): JsonResponse {
        $result = $action->execute($request->validated());

        if (! ($result['success'] ?? false)) {
            return ApiResponse::error(
                message: (string) ($result['message'] ?? 'Feeding prediction failed.'),
                error: $result['error'] ?? null,
                status: (int) ($result['status'] ?? 500),
            );
        }

        return ApiResponse::success(
            $result['data'] ?? null,
            (string) ($result['message'] ?? 'Feeding prediction generated successfully.'),
        );
    }

    public function index(): JsonResponse
    {
        return $this->crudIndex();
    }

    public function store(FeedingPredictionsRequest $request): JsonResponse
    {
        return $this->crudStore($request->validated());
    }

    public function show(FeedingPredictions $feedingPrediction): JsonResponse
    {
        return $this->crudShow($feedingPrediction);
    }

    public function update(FeedingPredictionsRequest $request, FeedingPredictions $feedingPrediction): JsonResponse
    {
        return $this->crudUpdate($feedingPrediction, $request->validated());
    }

    public function destroy(FeedingPredictions $feedingPrediction): JsonResponse
    {
        return $this->crudDestroy($feedingPrediction);
    }
}
