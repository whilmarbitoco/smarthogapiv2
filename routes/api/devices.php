<?php

use App\Http\Controllers\Api\V1\DeviceCredentialsController;
use App\Http\Controllers\Api\V1\DeviceLogsController;
use App\Http\Controllers\Api\V1\IotDevicesController;
use App\Http\Controllers\Api\V1\SensorReadingsController;
use App\Http\Controllers\Api\V1\SensorsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('devices/{deviceId}/action', [IotDevicesController::class, 'action']);
    Route::apiResource('iot-devices', IotDevicesController::class)->parameters(['iot-devices' => 'iotDevice']);
    Route::apiResource('device-logs', DeviceLogsController::class)->parameters(['device-logs' => 'deviceLog']);
    Route::apiResource('device-credentials', DeviceCredentialsController::class)->parameters(['device-credentials' => 'deviceCredential']);
    Route::apiResource('sensors', SensorsController::class)->parameters(['sensors' => 'sensor']);
    Route::apiResource('sensor-readings', SensorReadingsController::class)->parameters(['sensor-readings' => 'sensorReading']);
});
