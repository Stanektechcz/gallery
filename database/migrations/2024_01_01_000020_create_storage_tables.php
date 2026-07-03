<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('google_drive');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('google_subject_id')->nullable();
            $table->string('account_email')->nullable();
            $table->text('encrypted_access_token')->nullable();
            $table->text('encrypted_refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('granted_scopes_json')->nullable();
            $table->string('connection_status', 50)->default('disconnected');
            $table->timestamp('last_successful_request_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('root_folder_id')->nullable();
            $table->string('root_folder_name')->nullable();
            $table->string('scope_mode', 50)->default('managed');
            $table->bigInteger('quota_total')->nullable()->unsigned();
            $table->bigInteger('quota_used')->nullable()->unsigned();
            $table->timestamp('quota_refreshed_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('connection_status');
            $table->index('owner_user_id');
        });

        Schema::create('storage_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('storage_connection_id')->constrained()->cascadeOnDelete();
            $table->string('operation_type', 60);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('remote_file_id')->nullable();
            $table->string('idempotency_key', 128)->unique()->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->json('request_summary_json')->nullable();
            $table->json('response_summary_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('drive_change_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_connection_id')->constrained()->cascadeOnDelete();
            $table->string('channel_id')->unique();
            $table->string('resource_id')->nullable();
            $table->string('channel_token', 256)->nullable();
            $table->string('page_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('renewed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('drive_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_connection_id')->constrained()->cascadeOnDelete();
            $table->string('change_type', 30);
            $table->string('file_id')->nullable();
            $table->string('file_name')->nullable();
            $table->boolean('removed')->default(false);
            $table->boolean('trashed')->default(false);
            $table->json('change_payload')->nullable();
            $table->string('processed_status', 20)->default('pending');
            $table->timestamp('change_time')->nullable();
            $table->timestamps();

            $table->index(['processed_status', 'created_at']);
        });

        Schema::create('drive_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_connection_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('drive_file_id')->nullable();
            $table->string('conflict_type', 50);
            $table->json('app_state')->nullable();
            $table->json('drive_state')->nullable();
            $table->string('resolution', 30)->default('unresolved');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('resolution');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_conflicts');
        Schema::dropIfExists('drive_changes');
        Schema::dropIfExists('drive_change_channels');
        Schema::dropIfExists('storage_operations');
        Schema::dropIfExists('storage_connections');
    }
};
