<?php

use App\Http\Controllers\Api\V1\AlertsController;
use App\Http\Controllers\Api\V1\DailyFarmReportsController;
use App\Http\Controllers\Api\V1\FarmsController;
use App\Http\Controllers\Api\V1\HogPensController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('farms', FarmsController::class)->parameters(['farms' => 'farm']);
    Route::get('hogpens', [HogPensController::class, 'index'])->name('hogpens.index');
    Route::apiResource('hog-pens', HogPensController::class)->parameters(['hog-pens' => 'hogPen']);
    Route::apiResource('daily-farm-reports', DailyFarmReportsController::class)->parameters(['daily-farm-reports' => 'dailyFarmReport']);
    Route::apiResource('alerts', AlertsController::class)->parameters(['alerts' => 'alert']);
});
