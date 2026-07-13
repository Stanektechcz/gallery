<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('travel_wishlist_items') || Schema::hasColumn('travel_wishlist_items', 'place_id')) {
            return;
        }

        Schema::table('travel_wishlist_items', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->after('calendar_event_id')->constrained()->nullOnDelete();
            $table->index(['place_id', 'status'], 'wishlist_place_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('travel_wishlist_items') || ! Schema::hasColumn('travel_wishlist_items', 'place_id')) {
            return;
        }

        Schema::table('travel_wishlist_items', function (Blueprint $table) {
            $table->dropForeign(['place_id']);
            $table->dropIndex('wishlist_place_status_idx');
            $table->dropColumn('place_id');
        });
    }
};
