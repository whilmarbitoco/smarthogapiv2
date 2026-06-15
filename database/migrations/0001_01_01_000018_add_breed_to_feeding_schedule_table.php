<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeding_schedule', function (Blueprint $table) {
            $table->string('breed', 255)->default('Large White')->after('feed_type');
        });
    }

    public function down(): void
    {
        Schema::table('feeding_schedule', function (Blueprint $table) {
            $table->dropColumn('breed');
        });
    }
};
