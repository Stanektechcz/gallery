<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives each space its own storage.
 *
 * The table held one row per provider for the whole installation, which was true when this
 * was one couple's gallery and became wrong the moment a second space existed: one
 * customer connecting their Drive would have redirected everybody's photographs into it.
 *
 * Existing rows are attached to their owner's space rather than deleted. A live connection
 * holds the tokens the gallery's files are reached with, and dropping it to tidy a schema
 * would take the library offline.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storage_connections')) return;

        if (! Schema::hasColumn('storage_connections', 'gallery_space_id')) {
            Schema::table('storage_connections', function (Blueprint $table) {
                $table->foreignId('gallery_space_id')->nullable()->after('provider')
                    ->constrained()->cascadeOnDelete();
            });
        }

        // Each existing connection belongs to whichever space its owner is in. Null stays
        // null when the owner is in none — the resolver reads that as "not this space's",
        // which is the safe answer.
        foreach (DB::table('storage_connections')->whereNull('gallery_space_id')->get() as $row) {
            $spaceId = DB::table('gallery_space_user')
                ->where('user_id', $row->owner_user_id)
                ->value('gallery_space_id');

            if ($spaceId) {
                DB::table('storage_connections')->where('id', $row->id)->update(['gallery_space_id' => $spaceId]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('storage_connections', 'gallery_space_id')) return;

        Schema::table('storage_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gallery_space_id');
        });
    }
};
