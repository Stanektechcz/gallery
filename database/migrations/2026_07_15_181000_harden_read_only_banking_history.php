<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This follow-up also repairs servers where the preceding migration was
        // deployed from an earlier revision before trip_action was introduced.
        if (Schema::hasTable('bank_transactions') && ! Schema::hasColumn('bank_transactions', 'trip_action')) {
            Schema::table('bank_transactions', fn (Blueprint $table) => $table->string('trip_action', 20)->default('suggest')->after('category'));
        }

        // Encrypted strings are longer than their plaintext values. MySQL needs
        // TEXT here so long merchant/counterparty metadata cannot be truncated.
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('bank_transactions')) {
            DB::statement('ALTER TABLE `bank_transactions` MODIFY `merchant_name` TEXT NULL, MODIFY `counterparty_name` TEXT NULL, MODIFY `bank_transaction_code` TEXT NULL, MODIFY `transaction_type` TEXT NULL');
        }
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('bank_accounts')) {
            DB::statement('ALTER TABLE `bank_accounts` MODIFY `owner_name` TEXT NULL');
        }
    }

    public function down(): void
    {
        // Security hardening and compatibility repair are intentionally kept.
    }
};
