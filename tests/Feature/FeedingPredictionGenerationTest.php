<?php

namespace Tests\Feature;

use App\Models\Farms;
use App\Models\FeedingPredictions;
use App\Models\FeedingSchedule;
use App\Models\HogDailyRecords;
use App\Models\Hogs;
use App\Models\HogPens;
use App\Models\PredictionCache;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeedingPredictionGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.fastapi.url', 'http://ml.test');
    }

    public function test_guest_cannot_generate_feeding_prediction(): void
    {
        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_user_cannot_generate_prediction_for_another_users_hog_pen(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $hogPen = $this->createHogPen($otherUser);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => $hogPen->id,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_owned_pen_generates_and_stores_feeding_prediction(): void
    {
        Http::fake([
            'http://ml.test/predict' => Http::response($this->mlResponse(), 200),
        ]);

        $user = User::factory()->create();
        $hogPen = $this->createHogPen($user);
        $hog = $this->createHog($hogPen, [
            'current_age' => 90,
            'weight_current' => 40,
        ]);

        HogDailyRecords::query()->create([
            'hog_id' => $hog->id,
            'hog_pen_id' => $hogPen->id,
            'weight' => 43.2,
            'feed_consumed' => 3,
            'health_status' => 'healthy',
            'temperature' => 38.5,
            'activity_level' => 'normal',
            'notes' => 'ok',
            'recorded_date' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => $hogPen->id,
            'feeding_frequency' => 3,
            'feeding_times' => ['6:00 am', '12:00 pm', '18:00 pm'],
            'schedule_type' => 'everyday',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cache_hit', false)
            ->assertJsonPath('data.feed_totals.total_recommended_feed_kg', 4.5)
            ->assertJsonPath('data.weight_trend.average_predicted_weight_kg', 45.8);

        $this->assertDatabaseCount('feeding_predictions', 1);
        $this->assertDatabaseCount('prediction_cache', 1);
        $this->assertDatabaseHas('feeding_predictions', [
            'hog_pen_id' => $hogPen->id,
            'predicted_feed_amount' => 4.5,
            'model_used' => 'smarthog_flask_service:v1',
        ]);

        Http::assertSent(function ($request) use ($hog): bool {
            return $request->url() === 'http://ml.test/predict'
                && $request->method() === 'POST'
                && $request['pigs'][0]['id'] === $hog->id
                && $request['pigs'][0]['avg_weight_kg'] === 43.2
                && $request['pigs'][0]['growth_stage'] === 'hog grower'
                && ! isset($request['pigs'][0]['feed_amount_kg']);
        });
    }

    public function test_repeated_prediction_uses_cache_without_calling_ml_service(): void
    {
        Http::fake([
            'http://ml.test/predict' => Http::response($this->mlResponse(), 200),
        ]);

        $user = User::factory()->create();
        $hogPen = $this->createHogPen($user);
        $this->createHog($hogPen);

        Sanctum::actingAs($user);

        $payload = ['hog_pen_id' => $hogPen->id];

        $this->postJson('/api/v1/feeding-predictions/generate', $payload)
            ->assertOk()
            ->assertJsonPath('data.cache_hit', false);

        $this->postJson('/api/v1/feeding-predictions/generate', $payload)
            ->assertOk()
            ->assertJsonPath('data.cache_hit', true);

        $this->assertDatabaseCount('feeding_predictions', 1);
        $this->assertDatabaseCount('prediction_cache', 1);
        Http::assertSentCount(1);
    }

    public function test_force_refresh_bypasses_cache(): void
    {
        Http::fake([
            'http://ml.test/predict' => Http::sequence()
                ->push($this->mlResponse(4.5, 45.8), 200)
                ->push($this->mlResponse(5.1, 46.2), 200),
        ]);

        $user = User::factory()->create();
        $hogPen = $this->createHogPen($user);
        $this->createHog($hogPen);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => $hogPen->id,
        ])->assertOk();

        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => $hogPen->id,
            'force_refresh' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.cache_hit', false)
            ->assertJsonPath('data.feed_totals.total_recommended_feed_kg', 5.1);

        $this->assertDatabaseCount('feeding_predictions', 2);
        $this->assertDatabaseCount('prediction_cache', 1);
        Http::assertSentCount(2);
    }

    public function test_ml_service_error_returns_api_error_without_creating_prediction(): void
    {
        Http::fake([
            'http://ml.test/predict' => Http::response([
                'error' => 'Prediction failed.',
            ], 500),
        ]);

        $user = User::factory()->create();
        $hogPen = $this->createHogPen($user);
        $this->createHog($hogPen);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => $hogPen->id,
        ])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Prediction failed.');

        $this->assertDatabaseCount('feeding_predictions', 0);
        $this->assertDatabaseCount('prediction_cache', 0);
    }

    public function test_empty_hog_pen_returns_validation_error(): void
    {
        $user = User::factory()->create();
        $hogPen = $this->createHogPen($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/feeding-predictions/generate', [
            'hog_pen_id' => $hogPen->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected hog pen does not have any hogs to predict.');
    }

    private function createHogPen(User $user): HogPens
    {
        $farm = Farms::query()->create([
            'user_id' => $user->id,
            'location' => 'Farm1',
            'timezone' => 'Asia/Manila',
        ]);

        $hogPen = HogPens::query()->create([
            'farm_id' => $farm->id,
            'name' => 'Small Cage',
            'capacity' => 2,
            'status' => 1,
        ]);

        FeedingSchedule::query()->create([
            'hog_pen_id' => $hogPen->id,
            'time' => now(),
            'feed_amount' => 3,
            'feed_type' => 'hog grower',
            'mode' => 'auto',
            'feeding_times' => ['6:00 am', '12:00 pm', '18:00 pm'],
            'daily_feeding_count' => 3,
        ]);

        return $hogPen;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createHog(HogPens $hogPen, array $attributes = []): Hogs
    {
        return Hogs::query()->create(array_merge([
            'hog_pen_id' => $hogPen->id,
            'ear_tag_id' => 'HOG-001',
            'breed' => 'Large White',
            'gender' => 'female',
            'current_age' => 90,
            'weight_current' => 42.5,
        ], $attributes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mlResponse(float $feed = 4.5, float $weight = 45.8): array
    {
        return [
            [
                'pig_id' => 1,
                'recommended_feed_kg' => $feed,
                'predicted_growth_stage' => 'hog grower',
                'predicted_weight_kg' => $weight,
            ],
        ];
    }
}
