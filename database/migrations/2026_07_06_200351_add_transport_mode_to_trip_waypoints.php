<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trip_waypoints', function (Blueprint $table) {
            if (! Schema::hasColumn('trip_waypoints', 'transport_mode')) {
                $table->string('transport_mode', 20)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('trip_waypoints', 'duration_override')) {
                $table->unsignedSmallInteger('duration_override')->nullable()->comment('manual travel time in minutes')->after('transport_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trip_waypoints', function (Blueprint $table) {
            $table->dropColumnIfExists('transport_mode');
            $table->dropColumnIfExists('duration_override');
        });
    }
};
