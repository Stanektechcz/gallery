<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            // Extended media type metadata
            $table->boolean('is_panorama')->default(false)->after('media_type');
            $table->boolean('is_360')->default(false)->after('is_panorama');
            $table->string('panorama_projection', 30)->nullable()->after('is_360'); // equirectangular|cylindrical|spherical
            $table->boolean('is_raw')->default(false)->after('panorama_projection');
            $table->string('raw_format', 20)->nullable()->after('is_raw'); // cr2|nef|arw|dng…

            // Live Photo / Motion Photo support (Apple/Samsung)
            $table->string('live_photo_content_id', 100)->nullable()->after('raw_format');
            $table->enum('live_photo_role', ['main', 'video'])->nullable()->after('live_photo_content_id');
            $table->unsignedBigInteger('live_photo_pair_id')->nullable()->after('live_photo_role');

            $table->index('live_photo_content_id');
            $table->index('is_panorama');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropIndex(['live_photo_content_id']);
            $table->dropIndex(['is_panorama']);
            $table->dropColumn([
                'is_panorama',
                'is_360',
                'panorama_projection',
                'is_raw',
                'raw_format',
                'live_photo_content_id',
                'live_photo_role',
                'live_photo_pair_id',
            ]);
        });
    }
};
