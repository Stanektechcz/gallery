<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // `invited_by` existed as a boolean in the original schema. Keeping it
            // preserves old installations; this FK stores the actual inviter safely.
            $table->foreignId('invited_by_user_id')->nullable()->after('invited_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_user_id');
        });
    }
};
