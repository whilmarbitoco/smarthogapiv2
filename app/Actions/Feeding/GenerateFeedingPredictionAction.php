<?php

namespace App\Actions\Feeding;

use App\Integrations\FastAPI\PredictionClient;
use App\Models\FeedingPredictions;
use App\Models\FeedingSchedule;
use App\Models\HogPens;
use App\Models\MlModels;
use App\Models\PredictionCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class GenerateFeedingPredictionAction
{
    private const DEFAULT_FEEDING_TIMES = ['6:00 am', '12:00 pm', '6:00 pm'];

    public function __construct(private PredictionClient $predictionClient) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        $hogPen = HogPens::query()
            ->with(['hogs.dailyRecords'])
            ->findOrFail($input['hog_pen_id']);

        if (! $hogPen->belongsToUser((int) auth()->id())) {
            return [
                'success' => false,
                'message' => 'Forbidden',
                'status' => 403,
            ];
        }

        if ($hogPen->hogs->isEmpty()) {
            return [
                'success' => false,
                'message' => 'The selected hog pen does not have any hogs to predict.',
                'status' => 422,
            ];
        }

        $schedule = FeedingSchedule::query()
            ->where('hog_pen_id', $hogPen->id)
            ->latest()
            ->first();

        $context = $this->predictionContext($input, $schedule);
        $payload = ['pigs' => $this->pigPayloads($hogPen->hogs, $context)];
        $cacheKey = $this->cacheKey($hogPen, $payload, $context);
        $forceRefresh = (bool) ($input['force_refresh'] ?? false);

        if (! $forceRefresh) {
            $cached = PredictionCache::query()
                ->where('cache_key', $cacheKey)
                ->where(function ($query): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first();

            if ($cached instanceof PredictionCache) {
                return [
                    'success' => true,
                    'data' => array_merge($cached->data, ['cache_hit' => true]),
                    'message' => 'Feeding prediction retrieved from cache.',
                ];
            }
        }

        $response = $this->predictionClient->predict($payload);

        if (! ($response['success'] ?? false)) {
            return $response;
        }

        $results = $this->predictionResults($response['data'] ?? null);

        if ($results === []) {
            return [
                'success' => false,
                'message' => 'ML prediction service returned no predictions.',
                'error' => $response['data'] ?? null,
                'status' => 502,
            ];
        }

        $mlModel = MlModels::query()->firstOrCreate(
            [
                'model_name' => 'smarthog_ml_service',
                'version' => $response['data']['model_version'] ?? '1.0.0',
            ],
            ['accuracy_score' => 0]
        );

        $feedTotals = $this->feedTotals($results);
        $weightTrend = $this->weightTrend($results);
        $feedRecommendation = [
            'source' => $response['data']['summary']['model_source'] ?? 'ml_service',
            'pigs' => $results,
        ];

        // Extract warnings and suggestions from per-pig results
        $allWarnings = [];
        $allSuggestions = [];
        foreach ($results as $r) {
            if (!empty($r['warnings'])) {
                $allWarnings = array_merge($allWarnings, $r['warnings']);
            }
        }

        $prediction = FeedingPredictions::query()->create([
            'hog_pen_id' => $hogPen->id,
            'ml_model_id' => $mlModel->id,
            'predicted_feed_amount' => $feedTotals['total_recommended_feed_kg'],
            'confidence_score' => $results[0]['confidence_score'] ?? 0,
            'model_used' => 'smarthog_ml_service:' . ($response['data']['model_version'] ?? '1.0.0'),
            'confidence_level' => $results[0]['confidence_level'] ?? 'medium',
            'confidence_reason' => $this->confidenceReason($results, $response['data']['summary']['model_source'] ?? 'rule_based'),
            'feed_recommendation' => $feedRecommendation,
            'feed_totals' => $feedTotals,
            'weight_trend' => $weightTrend,
            'pen_status' => [
                'hog_pen_id' => $hogPen->id,
                'hog_count' => $hogPen->hogs->count(),
                'model_source' => $response['data']['summary']['model_source'] ?? 'rule_based',
            ],
            'warnings' => array_unique($allWarnings),
            'alerts' => [],
            'suggestions' => $allSuggestions,
            'fastapi_response' => $results,
            'predicted_at' => now(),
        ])->load(['hogPen', 'mlModel']);

        $data = [
            'cache_hit' => false,
            'hog_pen_id' => $hogPen->id,
            'prediction_id' => $prediction->id,
            'ml_model_id' => $mlModel->id,
            'predicted_feed_amount' => (float) $prediction->predicted_feed_amount,
            'feed_recommendation' => $feedRecommendation,
            'feed_totals' => $feedTotals,
            'weight_trend' => $weightTrend,
            'fastapi_response' => $results,
            'prediction' => $prediction,
        ];

        PredictionCache::query()->updateOrCreate(
            ['cache_key' => $cacheKey],
            [
                'prediction_type' => 'feeding',
                'pen_id' => $hogPen->id,
                'data' => Arr::except($data, ['cache_hit', 'prediction']),
                'expires_at' => now()->addMinutes(30),
            ]
        );

        return [
            'success' => true,
            'data' => $data,
            'message' => 'Feeding prediction generated successfully.',
        ];
    }

    private function confidenceReason(array $results, string $source): string
    {
        if ($source === 'ml') {
            return 'Prediction based on trained ML model using farm-specific data.';
        }
        return 'Prediction based on industry-standard rules. Train the model with farm data for improved accuracy.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{feeding_frequency: int, feeding_times: array<int, string>, schedule_type: string}
     */
    private function predictionContext(array $input, ?FeedingSchedule $schedule): array
    {
        $feedingFrequency = (int) ($input['feeding_frequency']
            ?? $schedule?->daily_feeding_count
            ?? 3);

        return [
            'feeding_frequency' => max(2, $feedingFrequency),
            'feeding_times' => $this->feedingTimes($input['feeding_times'] ?? null, $schedule),
            'schedule_type' => (string) ($input['schedule_type'] ?? 'everyday'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function feedingTimes(mixed $override, ?FeedingSchedule $schedule): array
    {
        if (is_array($override) && count($override) === 3) {
            return array_values(array_map('strval', $override));
        }

        if (is_array($schedule?->feeding_times) && count($schedule->feeding_times) >= 3) {
            return array_values(array_map('strval', array_slice($schedule->feeding_times, 0, 3)));
        }

        return self::DEFAULT_FEEDING_TIMES;
    }

    /**
     * @param  Collection<int, \App\Models\Hogs>  $hogs
     * @param  array{feeding_frequency: int, feeding_times: array<int, string>, schedule_type: string}  $context
     * @return array<int, array<string, mixed>>
     */
    private function pigPayloads(Collection $hogs, array $context): array
    {
        return $hogs
            ->sortBy('id')
            ->values()
            ->map(function ($hog) use ($context): array {
                $latestRecord = $hog->dailyRecords
                    ->sortByDesc('recorded_date')
                    ->first();
                $age = (int) $hog->current_age;

                return [
                    'id' => $hog->id,
                    'pig_age_days' => $age,
                    'avg_weight_kg' => (float) ($latestRecord?->weight ?? $hog->weight_current),
                    'feeding_frequency' => $context['feeding_frequency'],
                    'time1' => $context['feeding_times'][0],
                    'time2' => $context['feeding_times'][1],
                    'time3' => $context['feeding_times'][2],
                    'growth_stage' => $this->growthStage($age),
                    'schedule_type' => $context['schedule_type'],
                ];
            })
            ->all();
    }

    private function growthStage(int $age): string
    {
        return match (true) {
            $age <= 50 => 'hog pre-starter',
            $age <= 80 => 'hog starter',
            $age <= 130 => 'hog grower',
            default => 'hog finisher',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function cacheKey(HogPens $hogPen, array $payload, array $context): string
    {
        return 'feeding:' . sha1(json_encode([
            'pen_id' => $hogPen->id,
            'pigs' => $payload['pigs'],
            'context' => $context,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function predictionResults(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // New format: data contains 'predictions' key
        if (isset($data['predictions']) && is_array($data['predictions'])) {
            return array_values(array_filter($data['predictions'], fn($r) => is_array($r)));
        }

        // Legacy format: flat list
        if (array_is_list($data)) {
            return array_values(array_filter($data, fn($r) => is_array($r)));
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function feedTotals(array $results): array
    {
        $total = array_sum(array_map(
            fn(array $result): float => (float) ($result['recommended_feed_kg'] ?? 0),
            $results
        ));
        $count = count($results);

        return [
            'hog_count' => $count,
            'total_recommended_feed_kg' => round($total, 2),
            'average_recommended_feed_kg' => $count > 0 ? round($total / $count, 2) : 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function weightTrend(array $results): array
    {
        $weights = array_values(array_filter(array_map(
            fn(array $result): ?float => isset($result['predicted_weight_kg'])
                ? (float) $result['predicted_weight_kg']
                : null,
            $results
        ), fn(?float $weight): bool => $weight !== null));

        return [
            'average_predicted_weight_kg' => $weights !== []
                ? round(array_sum($weights) / count($weights), 2)
                : null,
            'pigs' => array_map(fn(array $result): array => [
                'pig_id' => $result['pig_id'] ?? null,
                'predicted_weight_kg' => $result['predicted_weight_kg'] ?? null,
                'predicted_growth_stage' => $result['predicted_growth_stage'] ?? null,
            ], $results),
        ];
    }
}
