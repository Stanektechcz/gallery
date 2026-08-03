<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_ideas', function (Blueprint $table) {
            $table->string('visibility', 12)->default('shared')->after('status');
            $table->foreignId('private_to_user_id')->nullable()->after('visibility')->constrained('users')->nullOnDelete();
            $table->index(['gallery_space_id', 'visibility', 'private_to_user_id'], 'gift_ideas_visibility_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('gift_ideas', function (Blueprint $table) {
            $table->dropIndex('gift_ideas_visibility_lookup');
            $table->dropForeign(['private_to_user_id']);
            $table->dropColumn(['visibility', 'private_to_user_id']);
        });
    }
};