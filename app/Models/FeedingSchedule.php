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

    /**
     * Get normalized feeding times in 24-hour H:i format.
     *
     * @return list<string>
     */
    public function getNormalizedFeedingTimesAttribute(): array
    {
        $times = $this->feeding_times ?: [];

        return collect($times)
            ->filter()
            ->map(function (mixed $time): ?string {
                $time = trim((string) $time);

                if ($time === '') {
                    return null;
                }

                if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                    return $time;
                }

                foreach (['H:i:s', 'g:i A', 'g:iA', 'h:i A', 'h:iA', 'G:i'] as $format) {
                    $parsed = \DateTime::createFromFormat($format, $time);
                    if ($parsed !== false) {
                        return $parsed->format('H:i');
                    }
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function hogPen(): BelongsTo
    {
        return $this->belongsTo(HogPens::class, 'hog_pen_id');
    }

    public function feedingLogs(): HasMany
    {
        return $this->hasMany(FeedingLogs::class, 'feeding_schedule_id');
    }
}
