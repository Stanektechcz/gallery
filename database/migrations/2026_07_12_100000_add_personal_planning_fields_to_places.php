<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            if (!Schema::hasColumn('places', 'is_rain_friendly')) $table->boolean('is_rain_friendly')->default(false)->after('description');
            if (!Schema::hasColumn('places', 'is_accessible')) $table->boolean('is_accessible')->default(false)->after('is_rain_friendly');
            if (!Schema::hasColumn('places', 'is_photogenic')) $table->boolean('is_photogenic')->default(false)->after('is_accessible');
            if (!Schema::hasColumn('places', 'opens_early')) $table->boolean('opens_early')->default(false)->after('is_photogenic');
            if (!Schema::hasColumn('places', 'price_level')) $table->unsignedTinyInteger('price_level')->nullable()->after('opens_early');
            if (!Schema::hasColumn('places', 'estimated_visit_minutes')) $table->unsignedSmallInteger('estimated_visit_minutes')->nullable()->after('price_level');
            if (!Schema::hasColumn('places', 'personal_rating')) $table->unsignedTinyInteger('personal_rating')->nullable()->after('estimated_visit_minutes');
            if (!Schema::hasColumn('places', 'next_time_note')) $table->text('next_time_note')->nullable()->after('personal_rating');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $columns = array_filter(['is_rain_friendly', 'is_accessible', 'is_photogenic', 'opens_early', 'price_level', 'estimated_visit_minutes', 'personal_rating', 'next_time_note'], fn ($column) => Schema::hasColumn('places', $column));
            if ($columns) $table->dropColumn($columns);
        });
    }
};
