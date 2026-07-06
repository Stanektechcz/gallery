<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // journey_events — add GPS, source, display name, itinerary link
        Schema::table('journey_events', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('media_uuids');
            $table->decimal('latitude', 10, 7)->nullable()->after('source');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedBigInteger('linked_itinerary_id')->nullable()->after('longitude');
            $table->string('place_display_name', 512)->nullable()->after('linked_itinerary_id');
        });

        // itinerary_places — add description, website, OSM metadata, planned date
        Schema::table('itinerary_places', function (Blueprint $table) {
            $table->text('description')->nullable()->after('notes');
            $table->string('website_url', 512)->nullable()->after('description');
            $table->string('osm_id', 50)->nullable()->after('website_url');
            $table->string('osm_type', 20)->nullable()->after('osm_id');
            $table->json('address_json')->nullable()->after('osm_type');
            $table->date('planned_date')->nullable()->after('visited_at');
        });
    }

    public function down(): void
    {
        Schema::table('journey_events', function (Blueprint $table) {
            $table->dropColumn(['source', 'latitude', 'longitude', 'linked_itinerary_id', 'place_display_name']);
        });

        Schema::table('itinerary_places', function (Blueprint $table) {
            $table->dropColumn(['description', 'website_url', 'osm_id', 'osm_type', 'address_json', 'planned_date']);
        });
    }
};
