<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_schedule', function (Blueprint $table): void {
            $table->string('frequency')->default('everyday')->after('mode');
            $table->json('custom_days')->nullable()->after('frequency');
            $table->boolean('is_active')->default(true)->after('custom_days');
            $table->timestamp('last_dispatched_at', 0)->nullable()->after('daily_feeding_count');

            $table->index(['is_active', 'frequency']);
        });

        Schema::table('feeding_logs', function (Blueprint $table): void {
            $table->foreignId('feeding_schedule_id')->nullable()->after('id')->constrained('feeding_schedule')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->after('feeding_schedule_id')->constrained('iot_devices')->nullOnDelete();
            $table->date('feeding_date')->nullable()->after('feed_amount_given');
            $table->time('feeding_time', 0)->nullable()->after('feeding_date');
            $table->string('status')->default('success')->after('feeding_time');
            $table->string('trigger_source')->default('manual')->after('status');
            $table->text('error_message')->nullable()->after('trigger_source');

            $table->unique(['feeding_schedule_id', 'feeding_date', 'feeding_time'], 'feeding_logs_schedule_date_time_unique');
            $table->index(['status', 'feeding_date']);
            $table->index(['trigger_source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('feeding_logs', function (Blueprint $table): void {
            $table->dropUnique('feeding_logs_schedule_date_time_unique');
            $table->dropIndex(['status', 'feeding_date']);
            $table->dropIndex(['trigger_source', 'created_at']);
            $table->dropConstrainedForeignId('feeding_schedule_id');
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn([
                'feeding_date',
                'feeding_time',
                'status',
                'trigger_source',
                'error_message',
            ]);
        });

        Schema::table('feeding_schedule', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'frequency']);
            $table->dropColumn([
                'frequency',
                'custom_days',
                'is_active',
                'last_dispatched_at',
            ]);
        });
    }
};
