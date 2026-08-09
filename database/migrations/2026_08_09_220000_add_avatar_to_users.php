<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A face for each person.
 *
 * Two ways to have one, and a third that needs no storage at all:
 *
 *   avatar_path   an uploaded picture on the private disk, streamed like any other
 *   avatar_preset the name of a built-in illustration, rendered by the client
 *
 * With neither set the initial on a colour derived from the name is used, which is why
 * avatar_colour is kept: a generated face should stay the same every time it is drawn,
 * and deriving it fresh from the name would change it the moment someone is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path', 400)->nullable()->after('last_seen_at');
                $table->string('avatar_preset', 40)->nullable()->after('avatar_path');
                $table->string('avatar_colour', 7)->nullable()->after('avatar_preset');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar_path', 'avatar_preset', 'avatar_colour'] as $column) {
                if (Schema::hasColumn('users', $column)) $table->dropColumn($column);
            }
        });
    }
};
