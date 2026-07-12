<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_vehicle_costs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title', 255);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('CZK');
            $table->decimal('liters', 8, 3)->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->date('occurred_on');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['trip_id', 'occurred_on']);
            $table->index(['trip_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_vehicle_costs');
    }
};
