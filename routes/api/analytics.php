<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\WebHookLogsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('analytics/devices', [AnalyticsController::class, 'deviceStatus']);
    Route::get('analytics/devices/status', [AnalyticsController::class, 'deviceStatus']);
    Route::get('analytics/feeding', [AnalyticsController::class, 'feeding']);
    Route::get('analytics/farms/{farm}/summary', [AnalyticsController::class, 'farmSummary']);

    Route::apiResource('webhook-logs', WebHookLogsController::class)->parameters(['webhook-logs' => 'webHookLog']);
});
