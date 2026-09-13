<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poznámka u transakce.
 *
 * Detail transakce v galerii poznámku ukazuje („Poznámka: dárek pro mamku"),
 * ale kniha pro ni sloupec neměla — posílalo se místo, kde se platilo,
 * a „Upravit poznámku" hlásilo „zatím neumíme". Popis (`description`) to být
 * nemůže: je to jméno transakce, podle kterého ji člověk v seznamu pozná.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'note')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'note')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }
};
