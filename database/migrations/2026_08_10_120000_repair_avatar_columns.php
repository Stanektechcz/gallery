<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs the avatar columns, which an earlier migration recorded as run without adding.
 *
 * That migration guarded all three columns behind one check on avatar_path. Installations
 * where avatar_path already existed — production did, from an older migration — took the
 * false branch and gained none of them, while the migrations table recorded success. Any
 * query naming avatar_preset then failed with "Unknown column", which took the chat down
 * with it: the poll 500'd, so the roster came back empty, "0 z 0 online" was the honest
 * report of an empty list, and opening the full chat showed Server Error.
 *
 * The lesson is in the shape of this file rather than in a comment: one guard per column,
 * because a guard that speaks for columns it does not name will eventually speak wrongly.
 */
return new class extends Migration
{
    /** @var array<string, callable(Blueprint): void> */
    private array $columns;

    public function __construct()
    {
        $this->columns = [
            'avatar_path' => fn (Blueprint $table) => $table->string('avatar_path', 400)->nullable(),
            'avatar_preset' => fn (Blueprint $table) => $table->string('avatar_preset', 40)->nullable(),
            'avatar_colour' => fn (Blueprint $table) => $table->string('avatar_colour', 7)->nullable(),
            'last_seen_at' => fn (Blueprint $table) => $table->timestamp('last_seen_at')->nullable(),
        ];
    }

    public function up(): void
    {
        foreach ($this->columns as $name => $define) {
            if (Schema::hasColumn('users', $name)) continue;

            Schema::table('users', fn (Blueprint $table) => $define($table));
        }
    }

    public function down(): void
    {
        // Nothing: these columns are what the application expects to exist, and dropping
        // them would recreate the fault this migration was written to repair.
    }
};
