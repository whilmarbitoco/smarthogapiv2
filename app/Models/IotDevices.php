<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IotDevices extends Model
{
    use BelongsToUser, HasFactory;

    protected $table = 'iot_devices';

    protected $fillable = [
        'type',
        'hog_pen_id',
        'api_provider',
        'status',
        'external_provider',
        'external_device_id',
        'external_metadata',
    ];

    protected $casts = [
        'external_metadata' => 'array',
    ];

    public function isOnline(): bool
    {
        if ($this->external_provider === 'sinric') {
            $sinricOnline = data_get($this->external_metadata, 'isOnline');

            if ($sinricOnline !== null) {
                return $sinricOnline === true;
            }
        }

        return $this->status === 'online'
            && data_get($this->external_metadata, 'isOnline', true) !== false;
    }

    public function hogPen(): BelongsTo
    {
        return $this->belongsTo(HogPens::class, 'hog_pen_id');
    }

    public function deviceCommands(): HasMany
    {
        return $this->hasMany(DeviceCommands::class, 'iot_device_id');
    }

    public function deviceCredentials(): HasMany
    {
        return $this->hasMany(DeviceCredentials::class, 'iot_device_id');
    }

    public function deviceLogs(): HasMany
    {
        return $this->hasMany(DeviceLogs::class, 'device_id');
    }

    public function feeders(): HasMany
    {
        return $this->hasMany(Feeders::class, 'device_id');
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensors::class, 'device_id');
    }
}
