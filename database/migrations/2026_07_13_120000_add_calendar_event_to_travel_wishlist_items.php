<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('travel_wishlist_items') || Schema::hasColumn('travel_wishlist_items', 'calendar_event_id')) {
            return;
        }

        Schema::table('travel_wishlist_items', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->nullable()->after('wishlist_id')->unique('wishlist_item_calendar_unique')->constrained('calendar_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('travel_wishlist_items') || ! Schema::hasColumn('travel_wishlist_items', 'calendar_event_id')) {
            return;
        }

        Schema::table('travel_wishlist_items', function (Blueprint $table) {
            $table->dropUnique('wishlist_item_calendar_unique');
            $table->dropForeign(['calendar_event_id']);
            $table->dropColumn('calendar_event_id');
        });
    }
};
