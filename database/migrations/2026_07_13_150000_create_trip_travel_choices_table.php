<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_travel_choices')) return;
        Schema::create('trip_travel_choices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trip_route_variant_id')->nullable()->constrained('trip_route_variants')->nullOnDelete();
            $table->foreignId('trip_activity_id')->nullable()->constrained('trip_activities')->nullOnDelete();
            $table->foreignId('trip_expense_id')->nullable()->constrained('trip_expenses')->nullOnDelete();
            $table->string('kind', 24);
            $table->string('provider', 80)->nullable();
            $table->string('title', 255);
            $table->string('source_url', 2048)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('CZK');
            $table->boolean('is_selected')->default(false);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'kind', 'is_selected'], 'trip_travel_choice_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_travel_choices');
    }
};
