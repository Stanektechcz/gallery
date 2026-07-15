<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['travel_inbox_items', 'trip_document_checks', 'gift_ideas'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'assigned_to')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                });
            }
        }

        if (! Schema::hasTable('partner_check_ins')) {
            Schema::create('partner_check_ins', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->date('check_in_on');
                $table->string('mood', 20)->nullable();
                $table->unsignedTinyInteger('energy')->nullable();
                $table->string('capacity', 16)->default('normal');
                $table->string('focus', 255)->nullable();
                $table->text('note')->nullable();
                $table->boolean('is_shared')->default(true);
                $table->timestamps();
                $table->unique(['gallery_space_id', 'user_id', 'check_in_on'], 'partner_check_space_user_day_uq');
                $table->index(['gallery_space_id', 'check_in_on'], 'partner_check_space_day_idx');
            });
        }

        if (! Schema::hasTable('coordination_action_states')) {
            Schema::create('coordination_action_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('source_type', 24);
                $table->string('source_key', 64);
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamps();
                $table->unique(['gallery_space_id', 'user_id', 'source_type', 'source_key'], 'coord_state_space_user_source_uq');
                $table->index(['user_id', 'snoozed_until'], 'coord_state_user_snooze_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_action_states');
        Schema::dropIfExists('partner_check_ins');
        foreach (['gift_ideas', 'trip_document_checks', 'travel_inbox_items'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'assigned_to')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['assigned_to']);
                    $table->dropColumn('assigned_to');
                });
            }
        }
    }
};
