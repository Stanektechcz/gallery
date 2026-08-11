<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep a section's own subtitle.
 *
 * The built-in sections carry one and the sidebar draws it, so a person who renames a
 * section could change the heading but was stuck with a description written for the name
 * it used to have.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_navigation_items')) return;
        if (Schema::hasColumn('user_navigation_items', 'description')) return;

        Schema::table('user_navigation_items', function (Blueprint $table) {
            $table->string('description', 160)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_navigation_items', 'description')) return;

        Schema::table('user_navigation_items', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
