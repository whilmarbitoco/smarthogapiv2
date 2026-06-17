<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrainingDataRequest;
use App\Http\Responses\ApiResponse;
use App\Models\FeedingLogs;
use App\Models\HogDailyRecords;
use App\Models\HogPens;
use App\Models\Hogs;
use App\Models\MlModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MlTrainingController extends Controller
{
    /**
     * Collect training data from the database and send to FastAPI for training.
     */
    public function train(TrainingDataRequest $request): JsonResponse
    {
        $data = $request->validated();
        $modelType = $data['model_type'] ?? 'feed_regression';

        // Collect training records from our database
        $records = $this->collectTrainingRecords($modelType);

        if (empty($records)) {
            return ApiResponse::error(
                'No training data available. Ensure feeding logs and pig records exist.',
                null,
                422
            );
        }

        // Send to FastAPI for training
        $fastapiUrl = rtrim(config('services.fastapi.url'), '/');
        $apiKey = config('services.fastapi.api_key');

        $headers = ['Content-Type' => 'application/json'];
        if ($apiKey) {
            $headers['x-api-key'] = $apiKey;
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders($headers)
                ->post("{$fastapiUrl}/train", [
                    'model_type' => $modelType,
                    'records' => $records,
                ]);

            if ($response->failed()) {
                return ApiResponse::error(
                    'ML training service returned an error.',
                    ['status' => $response->status(), 'body' => $response->json()],
                    502
                );
            }

            $result = $response->json();

            // Update or create ML model record
            if ($result['success'] ?? false) {
                MlModels::query()->updateOrCreate(
                    [
                        'model_name' => 'smarthog_ml_service',
                        'version' => $result['model_version'] ?? '1.0.0',
                    ],
                    [
                        'accuracy_score' => $result['metrics']['r2_score']
                            ?? $result['metrics']['accuracy']
                            ?? 0,
                    ]
                );
            }

            return ApiResponse::success($result, 'Model trained successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error(
                'Failed to connect to ML training service: ' . $e->getMessage(),
                null,
                502
            );
        }
    }

    /**
     * Seed models with industry standard data.
     */
    public function seed(): JsonResponse
    {
        $fastapiUrl = rtrim(config('services.fastapi.url'), '/');
        $apiKey = config('services.fastapi.api_key');

        $headers = ['Content-Type' => 'application/json'];
        if ($apiKey) {
            $headers['x-api-key'] = $apiKey;
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders($headers)
                ->post("{$fastapiUrl}/seed");

            if ($response->failed()) {
                return ApiResponse::error(
                    'ML service returned an error.',
                    ['status' => $response->status()],
                    502
                );
            }

            $result = $response->json();

            // Create ML model records for seeded models
            foreach ($result['results'] ?? [] as $r) {
                MlModels::query()->updateOrCreate(
                    [
                        'model_name' => 'smarthog_ml_service',
                        'version' => $r['model_version'] ?? '1.0.0',
                    ],
                    [
                        'accuracy_score' => $r['metrics']['r2_score']
                            ?? $r['metrics']['accuracy']
                            ?? 0,
                    ]
                );
            }

            return ApiResponse::success($result, 'Models seeded successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error(
                'Failed to connect to ML service: ' . $e->getMessage(),
                null,
                502
            );
        }
    }

    /**
     * Check ML service health and model status.
     */
    public function health(): JsonResponse
    {
        $fastapiUrl = rtrim(config('services.fastapi.url'), '/');

        try {
            $response = Http::timeout(10)->get("{$fastapiUrl}/health");
            $models = $response->successful() ? ($response->json()['models_loaded'] ?? []) : [];
        } catch (\Exception) {
            $models = [];
        }

        $localModels = MlModels::query()
            ->orderByDesc('created_at')
            ->get(['model_name', 'version', 'accuracy_score', 'created_at']);

        return ApiResponse::success([
            'service_healthy' => !empty($models),
            'models_loaded' => $models,
            'local_model_records' => $localModels,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectTrainingRecords(string $modelType): array
    {
        $records = [];

        if ($modelType === 'feed_regression' || $modelType === 'trend_analysis') {
            // Join feeding logs with pig daily records to get feed_amount + weight_gain
            $query = DB::table('feeding_logs')
                ->join('hog_daily_records', function ($join) {
                    $join->on('feeding_logs.pen_id', '=', 'hog_daily_records.hog_pen_id')
                        ->whereRaw('DATE(feeding_logs.created_at) = hog_daily_records.recorded_date');
                })
                ->join('hogs', 'hog_daily_records.hog_id', '=', 'hogs.id')
                ->join('feeding_schedules', 'feeding_logs.feeding_schedule_id', '=', 'feeding_schedules.id')
                ->select([
                    'hogs.current_age as pig_age_days',
                    'hog_daily_records.weight as avg_weight_kg',
                    'feeding_logs.feed_amount_given as feed_amount_kg',
                    'hog_daily_records.feed_consumed',
                    'feeding_schedules.daily_feeding_count as feeding_frequency',
                    'hog_daily_records.recorded_date',
                ])
                ->where('feeding_logs.status', 'success')
                ->orderByDesc('feeding_logs.created_at')
                ->limit(500);

            foreach ($query->get() as $row) {
                $records[] = [
                    'pig_age_days' => (int) $row->pig_age_days,
                    'avg_weight_kg' => (float) $row->avg_weight_kg,
                    'feed_amount_kg' => (float) $row->feed_amount_kg,
                    'weight_gain_kg' => 0,
                    'feeding_frequency' => (int) $row->feeding_frequency,
                    'growth_stage' => $this->classifyStage((int) $row->pig_age_days),
                    'feed_conversion_ratio' => $row->feed_amount_kg > 0
                        ? round($row->feed_amount_kg / max($row->feed_consumed, 0.01), 2)
                        : 0,
                ];
            }
        } elseif ($modelType === 'growth_classification') {
            // Use pig daily records for growth stage classification
            $query = DB::table('hog_daily_records')
                ->join('hogs', 'hog_daily_records.hog_id', '=', 'hogs.id')
                ->select([
                    'hogs.current_age as pig_age_days',
                    'hog_daily_records.weight as avg_weight_kg',
                ])
                ->orderByDesc('hog_daily_records.recorded_date')
                ->limit(500);

            foreach ($query->get() as $row) {
                $records[] = [
                    'pig_age_days' => (int) $row->pig_age_days,
                    'avg_weight_kg' => (float) $row->avg_weight_kg,
                    'feed_amount_kg' => 0,
                    'weight_gain_kg' => 0,
                    'feeding_frequency' => 3,
                    'growth_stage' => $this->classifyStage((int) $row->pig_age_days),
                    'feed_conversion_ratio' => 0,
                ];
            }
        }

        return $records;
    }

    private function classifyStage(int $age): string
    {
        return match (true) {
            $age <= 50 => 'hog pre-starter',
            $age <= 80 => 'hog starter',
            $age <= 130 => 'hog grower',
            default => 'hog finisher',
        };
    }
}
