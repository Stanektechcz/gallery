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
        // One guard per column. Guarding all three behind a check on the first meant an
        // installation that already had avatar_path gained none of them, while the
        // migrations table still recorded success — see the repair migration.
        foreach ([
            'avatar_path' => fn (Blueprint $table) => $table->string('avatar_path', 400)->nullable(),
            'avatar_preset' => fn (Blueprint $table) => $table->string('avatar_preset', 40)->nullable(),
            'avatar_colour' => fn (Blueprint $table) => $table->string('avatar_colour', 7)->nullable(),
        ] as $name => $define) {
            if (Schema::hasColumn('users', $name)) continue;

            Schema::table('users', fn (Blueprint $table) => $define($table));
        }
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
