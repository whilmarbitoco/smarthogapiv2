<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'feeding_times',
        'daily_feeding_count',
    ];

    protected $casts = [
        'time' => 'datetime',
        'feed_amount' => 'decimal:2',
        'feeding_times' => 'array',
        'daily_feeding_count' => 'integer',
    ];

    public function hogPen(): BelongsTo
    {
        return $this->belongsTo(HogPens::class, 'hog_pen_id');
    }
}
