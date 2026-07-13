<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trip_packing_items') || Schema::hasColumn('trip_packing_items', 'packed_by')) {
            return;
        }

        Schema::table('trip_packing_items', function (Blueprint $table) {
            $table->foreignId('packed_by')->nullable()->after('packed_at')->constrained('users')->nullOnDelete();
            $table->index(['trip_id', 'assigned_to', 'is_packed'], 'trip_packing_assignment_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('trip_packing_items') || ! Schema::hasColumn('trip_packing_items', 'packed_by')) {
            return;
        }

        Schema::table('trip_packing_items', function (Blueprint $table) {
            $table->dropForeign(['packed_by']);
            $table->dropIndex('trip_packing_assignment_idx');
            $table->dropColumn('packed_by');
        });
    }
};
