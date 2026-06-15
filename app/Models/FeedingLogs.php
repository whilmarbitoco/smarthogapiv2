<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedingLogs extends Model
{
    use BelongsToUser, HasFactory;

    protected $table = 'feeding_logs';

    protected $fillable = [
        'feeder_id',
        'feeding_schedule_id',
        'device_id',
        'pen_id',
        'feed_amount_given',
        'feeding_date',
        'feeding_time',
        'status',
        'trigger_source',
        'error_message',
        'triggered',
    ];

    protected $casts = [
        'feed_amount_given' => 'decimal:2',
        'feeding_date' => 'date',
    ];

    public function feedingSchedule(): BelongsTo
    {
        return $this->belongsTo(FeedingSchedule::class, 'feeding_schedule_id');
    }

    public function iotDevice(): BelongsTo
    {
        return $this->belongsTo(IotDevices::class, 'device_id');
    }

    public function feeder(): BelongsTo
    {
        return $this->belongsTo(Feeders::class, 'feeder_id');
    }

    public function hogPen(): BelongsTo
    {
        return $this->belongsTo(HogPens::class, 'pen_id');
    }
}
