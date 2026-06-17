<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('feeding_logs', 'execution_key')) {
                $table->string('execution_key')->nullable();
            }

            if (Schema::hasColumn('feeding_logs', 'feeding_schedule_id')
                && Schema::hasColumn('feeding_logs', 'feeding_date')
                && Schema::hasColumn('feeding_logs', 'feeding_time')) {
                $table->dropUnique('feeding_logs_schedule_date_time_unique');
            }
        });

        Schema::table('feeding_logs', function (Blueprint $table): void {
            $table->unique('execution_key', 'feeding_logs_execution_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('feeding_logs', function (Blueprint $table): void {
            $table->dropUnique('feeding_logs_execution_key_unique');
            $table->dropColumn('execution_key');

            if (Schema::hasColumn('feeding_logs', 'feeding_schedule_id')
                && Schema::hasColumn('feeding_logs', 'feeding_date')
                && Schema::hasColumn('feeding_logs', 'feeding_time')) {
                $table->unique(
                    ['feeding_schedule_id', 'feeding_date', 'feeding_time'],
                    'feeding_logs_schedule_date_time_unique'
                );
            }
        });
    }
};
