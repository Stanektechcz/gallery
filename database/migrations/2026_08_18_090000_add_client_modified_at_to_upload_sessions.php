<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a photograph carries no date of its own.
 *
 * The archive is ordered by when a picture was taken, which works while EXIF survives.
 * It often does not: screenshots never had any, and anything that has been through a
 * chat app arrives stripped. Those pictures were landing in a "Bez data" heap at the
 * very end, which is the one place somebody will never look for them.
 *
 * The file's own modification time is the last remaining evidence, and only the browser
 * can see it — by the time the server has the bytes they are a freshly assembled chunk
 * file stamped with today. So the client sends it, and it is kept here until the media
 * row is made. It is the last resort: real EXIF and a dated filename both outrank it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->timestamp('client_modified_at')->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn('client_modified_at');
        });
    }
};
