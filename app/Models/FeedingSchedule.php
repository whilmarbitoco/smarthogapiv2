<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedingSchedule extends Model
{
    use BelongsToUser, HasFactory;

    protected $table = 'feeding_schedule';

    protected $fillable = [
        'hog_pen_id',
        'time',
        'feed_amount',
        'feed_type',
        'breed',
        'mode',
        'frequency',
        'custom_days',
        'is_active',
        'feeding_times',
        'daily_feeding_count',
        'last_dispatched_at',
    ];

    protected $casts = [
        'time' => 'datetime',
        'feed_amount' => 'decimal:2',
        'custom_days' => 'array',
        'is_active' => 'boolean',
        'feeding_times' => 'array',
        'daily_feeding_count' => 'integer',
        'last_dispatched_at' => 'datetime',
    ];

    public function hogPen(): BelongsTo
    {
        return $this->belongsTo(HogPens::class, 'hog_pen_id');
    }

    public function feedingLogs(): HasMany
    {
        return $this->hasMany(FeedingLogs::class, 'feeding_schedule_id');
    }
}
