<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            // Event mode flag
            $table->boolean('event_mode')->default(false)->after('story_mode');
            // Precise start/end datetimes (event_date_start/end remain for backward compat)
            $table->dateTime('event_start_at')->nullable()->after('event_mode');
            $table->dateTime('event_end_at')->nullable()->after('event_start_at');
            // Event location details
            $table->string('event_place_name', 255)->nullable()->after('event_end_at');
            $table->decimal('event_latitude', 10, 7)->nullable()->after('event_place_name');
            $table->decimal('event_longitude', 10, 7)->nullable()->after('event_latitude');
            // GPS radius for media collection (meters, default 500)
            $table->unsignedInteger('event_gps_radius')->default(500)->after('event_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn([
                'event_mode',
                'event_start_at',
                'event_end_at',
                'event_place_name',
                'event_latitude',
                'event_longitude',
                'event_gps_radius'
            ]);
        });
    }
};
