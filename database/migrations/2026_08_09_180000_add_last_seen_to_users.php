<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each person was last doing anything in the app.
 *
 * The chat already tracks who is looking at the conversation, but that only knows about
 * people on the chat page — a partner reading the calendar looked permanently offline.
 * This column is written by TrackLastSeen on any authenticated request, so "naposledy
 * aktivní" means the whole app rather than one screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_seen_at')) $table->dropColumn('last_seen_at');
        });
    }
};
