<?php

use App\Http\Controllers\Api\V1\HogDailyRecordsController;
use App\Http\Controllers\Api\V1\HogPensController;
use App\Http\Controllers\Api\V1\HogsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/farms/{farmId}/hog-pens-summary', [HogPensController::class, 'summary']);
    Route::apiResource('hogs', HogsController::class)->parameters(['hogs' => 'hog']);
    Route::apiResource('hog-daily-records', HogDailyRecordsController::class)->parameters(['hog-daily-records' => 'hogDailyRecord']);
});
