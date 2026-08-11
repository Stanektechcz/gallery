<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an add-on carry capacity as well as features.
 *
 * Extra space was the one thing customers could be sold that the entitlement engine had no
 * way to grant: the quota read the plan's limit and nothing else, so a purchased expansion
 * would have taken the money and changed nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_modules')) return;
        if (Schema::hasColumn('billing_modules', 'storage_bonus_mb')) return;

        Schema::table('billing_modules', function (Blueprint $table) {
            $table->unsignedInteger('storage_bonus_mb')->default(0)->after('price_monthly');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('billing_modules', 'storage_bonus_mb')) return;

        Schema::table('billing_modules', function (Blueprint $table) {
            $table->dropColumn('storage_bonus_mb');
        });
    }
};
