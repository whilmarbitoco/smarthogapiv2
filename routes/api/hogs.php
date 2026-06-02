<?php

use App\Http\Controllers\Api\V1\HogDailyRecordsController;
use App\Http\Controllers\Api\V1\HogsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HogPensController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('hogs', HogsController::class)->parameters(['hogs' => 'hog']);
    Route::get('/hog-pens/summary', [HogPensController::class, 'summary']);
    Route::apiResource('hog-daily-records', HogDailyRecordsController::class)->parameters(['hog-daily-records' => 'hogDailyRecord']);
});
