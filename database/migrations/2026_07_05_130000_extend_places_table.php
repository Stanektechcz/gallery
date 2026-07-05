<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            // Scope places to a gallery space
            $table->foreignId('gallery_space_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            // Place type: country | city | business | restaurant | museum | hotel | home | custom
            $table->string('type', 30)->default('custom')->after('address');

            // Rich content
            $table->text('description')->nullable()->after('type');
            $table->string('website_url', 512)->nullable()->after('description');
            $table->string('osm_id', 50)->nullable()->after('website_url');
            $table->string('osm_type', 20)->nullable()->after('osm_id');

            $table->index('gallery_space_id');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropForeign(['gallery_space_id']);
            $table->dropColumn(['gallery_space_id', 'type', 'description', 'website_url', 'osm_id', 'osm_type']);
        });
    }
};
