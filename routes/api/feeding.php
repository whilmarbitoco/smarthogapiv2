<?php

use App\Http\Controllers\Api\V1\FeederFeedTypeMappingController;
use App\Http\Controllers\Api\V1\FeedersController;
use App\Http\Controllers\Api\V1\FeedingLogsController;
use App\Http\Controllers\Api\V1\FeedingPredictionsController;
use App\Http\Controllers\Api\V1\FeedingScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('feeders/{feeder}/feed', [FeedersController::class, 'feed']);
    Route::apiResource('feeders', FeedersController::class)->parameters(['feeders' => 'feeder']);
    Route::post('feeders/{feeder}/feed', [FeedersController::class, 'feed']);
    Route::apiResource('feeder-feed-type-mapping', FeederFeedTypeMappingController::class)->parameters(['feeder-feed-type-mapping' => 'feederFeedTypeMapping']);
    Route::apiResource('feeding-logs', FeedingLogsController::class)->parameters(['feeding-logs' => 'feedingLog']);
    Route::apiResource('feeding-schedule', FeedingScheduleController::class)->parameters(['feeding-schedule' => 'feedingSchedule']);
    Route::post('feeding-predictions/generate', [FeedingPredictionsController::class, 'generate']);
    Route::apiResource('feeding-predictions', FeedingPredictionsController::class)->parameters(['feeding-predictions' => 'feedingPrediction']);
});
