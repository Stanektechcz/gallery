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
        Schema::table('itinerary_places', function (Blueprint $table) {
            if (! Schema::hasColumn('itinerary_places', 'planned_date')) {
                $table->date('planned_date')->nullable()->after('visited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_places', function (Blueprint $table) {
            $table->dropColumnIfExists('planned_date');
        });
    }
};
