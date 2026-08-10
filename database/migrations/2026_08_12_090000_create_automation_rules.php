<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules people write themselves: when this happens, and these things are true, do that.
 *
 * The three built-in automations stay where they are. They are switches on behaviour the
 * app performs anyway; this is a different thing — authored, per space, and able to be
 * wrong in ways a switch cannot be. Keeping them apart means a mistake here cannot break
 * the maintenance that runs nightly for everybody.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('automation_rules')) return;

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 60);
            $table->json('conditions')->nullable();
            $table->string('action', 60);
            $table->json('action_config')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            // The commonest read by far: every rule for one trigger in one space.
            $table->index(['gallery_space_id', 'trigger', 'is_enabled']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
