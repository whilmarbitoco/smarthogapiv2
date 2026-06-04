<?php

use App\Http\Controllers\Api\V1\AlertsController;
use App\Http\Controllers\Api\V1\DailyFarmReportsController;
use App\Http\Controllers\Api\V1\FarmsController;
use App\Http\Controllers\Api\V1\HogPensController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/farms-summary', [FarmsController::class, 'summary']);
    Route::apiResource('farms', FarmsController::class)->parameters(['farms' => 'farm']);
    Route::get('hogpens', [HogPensController::class, 'index'])->name('hogpens.index');
    Route::get('sinric/rooms', [HogPensController::class, 'index'])->name('sinric.rooms.index');
    Route::post('sinric/rooms', [HogPensController::class, 'store'])->name('sinric.rooms.store');
    Route::match(['put', 'patch'], 'sinric/rooms', [HogPensController::class, 'updateBySinricRoom'])->name('sinric.rooms.update');
    Route::delete('sinric/rooms/{roomId?}', [HogPensController::class, 'destroyBySinricRoom'])->name('sinric.rooms.destroy');
    Route::apiResource('hog-pens', HogPensController::class)->parameters(['hog-pens' => 'hogPen']);
    Route::apiResource('daily-farm-reports', DailyFarmReportsController::class)->parameters(['daily-farm-reports' => 'dailyFarmReport']);
    Route::apiResource('alerts', AlertsController::class)->parameters(['alerts' => 'alert']);
});
