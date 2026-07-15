<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_connections')) {
            Schema::create('bank_connections', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('provider', 40)->default('gocardless');
                $table->string('institution_id', 160)->nullable();
                $table->string('institution_name', 160)->nullable();
                $table->string('requisition_id', 160)->nullable();
                $table->string('agreement_id', 160)->nullable();
                $table->char('oauth_state_hash', 64)->nullable();
                $table->string('status', 24)->default('pending');
                $table->boolean('sync_enabled')->default(true);
                $table->timestamp('consent_expires_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->text('last_error')->nullable();
                $table->longText('encrypted_metadata')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['gallery_space_id', 'status'], 'bank_conn_space_status_ix');
                $table->unique(['provider', 'requisition_id'], 'bank_conn_provider_req_uq');
            });
        }

        if (! Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
                $table->char('external_id_hash', 64);
                $table->text('encrypted_external_id');
                $table->string('name', 160)->nullable();
                $table->text('owner_name')->nullable();
                $table->string('iban_last4', 4)->nullable();
                $table->string('currency', 3);
                $table->string('account_type', 60)->nullable();
                $table->boolean('is_joint')->default(false);
                $table->boolean('is_enabled')->default(true);
                $table->decimal('current_balance', 18, 4)->nullable();
                $table->decimal('available_balance', 18, 4)->nullable();
                $table->timestamp('balance_updated_at')->nullable();
                $table->date('history_available_from')->nullable();
                $table->timestamps();
                $table->unique(['bank_connection_id', 'external_id_hash'], 'bank_acc_conn_ext_uq');
                $table->index(['bank_connection_id', 'is_enabled'], 'bank_acc_conn_enabled_ix');
            });
        }

        if (! Schema::hasTable('bank_balance_snapshots')) {
            Schema::create('bank_balance_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
                $table->decimal('booked_balance', 18, 4)->nullable();
                $table->decimal('available_balance', 18, 4)->nullable();
                $table->string('currency', 3);
                $table->timestamp('captured_at');
                $table->string('source', 32)->default('api');
                $table->char('snapshot_key', 64);
                $table->timestamps();
                $table->unique(['bank_account_id', 'snapshot_key'], 'bank_bal_acc_key_uq');
                $table->index(['bank_account_id', 'captured_at'], 'bank_bal_acc_time_ix');
            });
        }

        if (! Schema::hasTable('bank_imports')) {
            Schema::create('bank_imports', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_connection_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('original_filename', 512);
                $table->char('file_sha256', 64);
                $table->string('format', 12);
                $table->string('status', 24)->default('processing');
                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('rows_imported')->default(0);
                $table->unsignedInteger('rows_duplicate')->default(0);
                $table->unsignedInteger('rows_failed')->default(0);
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->text('error_summary')->nullable();
                $table->longText('encrypted_metadata')->nullable();
                $table->timestamps();
                $table->unique(['gallery_space_id', 'file_sha256'], 'bank_imp_space_sha_uq');
                $table->index(['gallery_space_id', 'created_at'], 'bank_imp_space_time_ix');
            });
        }

        if (! Schema::hasTable('bank_transactions')) {
            Schema::create('bank_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_import_id')->nullable()->constrained()->nullOnDelete();
                $table->char('external_id_hash', 64);
                $table->text('encrypted_external_id')->nullable();
                $table->string('status', 20)->default('booked');
                $table->string('direction', 12);
                $table->decimal('amount', 18, 4);
                $table->string('currency', 3);
                $table->decimal('original_amount', 18, 4)->nullable();
                $table->string('original_currency', 3)->nullable();
                $table->decimal('fee_amount', 18, 4)->nullable();
                $table->decimal('balance_after', 18, 4)->nullable();
                $table->dateTime('booked_at');
                $table->date('value_date')->nullable();
                $table->text('merchant_name')->nullable();
                $table->text('counterparty_name')->nullable();
                $table->text('description')->nullable();
                $table->text('bank_transaction_code')->nullable();
                $table->text('transaction_type')->nullable();
                $table->string('category', 32)->default('other');
                $table->string('trip_action', 20)->default('suggest');
                $table->boolean('category_is_manual')->default(false);
                $table->boolean('is_internal_transfer')->default(false);
                $table->boolean('is_refund')->default(false);
                $table->boolean('is_fee')->default(false);
                $table->boolean('is_cash_withdrawal')->default(false);
                $table->longText('provider_payload')->nullable();
                $table->timestamps();
                $table->unique(['bank_account_id', 'external_id_hash'], 'bank_tx_acc_ext_uq');
                $table->index(['bank_account_id', 'booked_at'], 'bank_tx_acc_booked_ix');
                $table->index(['category', 'booked_at'], 'bank_tx_cat_booked_ix');
            });
        }

        if (! Schema::hasTable('trip_bank_transactions')) {
            Schema::create('trip_bank_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
                $table->foreignId('trip_expense_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('trip_activity_id')->nullable()->constrained('trip_activities')->nullOnDelete();
                $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 20)->default('suggested');
                $table->unsignedTinyInteger('confidence')->default(0);
                $table->string('reason', 500)->nullable();
                $table->decimal('allocated_amount', 18, 4)->nullable();
                $table->string('category', 32)->nullable();
                $table->string('timing', 16)->default('during');
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['trip_id', 'bank_transaction_id'], 'trip_bank_trip_tx_uq');
                $table->index(['trip_id', 'status'], 'trip_bank_trip_status_ix');
            });
        }

        if (! Schema::hasTable('bank_category_rules')) {
            Schema::create('bank_category_rules', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('field', 32)->default('description');
                $table->string('operator', 20)->default('contains');
                $table->string('pattern', 255);
                $table->string('category', 32);
                $table->string('trip_action', 20)->default('suggest');
                $table->unsignedSmallInteger('priority')->default(100);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();
                $table->index(['gallery_space_id', 'is_enabled', 'priority'], 'bank_rule_space_active_ix');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_category_rules');
        Schema::dropIfExists('trip_bank_transactions');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_imports');
        Schema::dropIfExists('bank_balance_snapshots');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('bank_connections');
    }
};
