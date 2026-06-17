<?php

use App\Http\Controllers\Api\V1\MlModelsController;
use App\Http\Controllers\Api\V1\MlTrainingController;
use App\Http\Controllers\Api\V1\PredictionCacheController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('ml-models', MlModelsController::class)->parameters(['ml-models' => 'mlModel']);
    Route::apiResource('prediction-cache', PredictionCacheController::class)->parameters(['prediction-cache' => 'predictionCache']);

    // ML Training endpoints
    Route::post('ml/train', [MlTrainingController::class, 'train']);
    Route::post('ml/seed', [MlTrainingController::class, 'seed']);
    Route::get('ml/health', [MlTrainingController::class, 'health']);
});
