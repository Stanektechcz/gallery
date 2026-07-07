<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_stacks', function (Blueprint $table) {
            $table->string('stack_type', 30)->default('manual')->after('name');
            $table->decimal('confidence', 5, 4)->nullable()->after('stack_type');
            $table->boolean('is_automatic')->default(false)->after('confidence');
        });

        Schema::create('guest_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shared_link_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename', 512);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path', 1024);
            $table->string('contributor_name', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('media_item_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['shared_link_id', 'status']);
        });

        Schema::create('share_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_link_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_family', 80)->nullable();
            $table->unsignedBigInteger('media_item_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['shared_link_id', 'created_at']);
        });

        Schema::create('legacy_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 20)->default('disabled');
            $table->unsignedSmallInteger('inactivity_months')->default(12);
            $table->json('scope')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('travel_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('note');
            $table->text('content')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['trip_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_journal_entries');
        Schema::dropIfExists('legacy_plans');
        Schema::dropIfExists('share_access_logs');
        Schema::dropIfExists('guest_uploads');
        Schema::table('media_stacks', function (Blueprint $table) {
            $table->dropColumn(['stack_type', 'confidence', 'is_automatic']);
        });
    }
};

