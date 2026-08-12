<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a pinned voice note came from.
 *
 * A note pinned out of a conversation is the same recording in two places, and without a
 * record of that, pinning it twice makes two copies of one moment. Unique, so the database
 * refuses the second attempt rather than relying on the button being disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_notes')) return;
        if (Schema::hasColumn('voice_notes', 'source_message_uuid')) return;

        Schema::table('voice_notes', function (Blueprint $table) {
            $table->uuid('source_message_uuid')->nullable()->unique()->after('created_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voice_notes', 'source_message_uuid')) return;

        Schema::table('voice_notes', function (Blueprint $table) {
            $table->dropUnique(['source_message_uuid']);
            $table->dropColumn('source_message_uuid');
        });
    }
};
