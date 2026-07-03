<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 100)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('radius_meters')->nullable();
            $table->string('source', 50)->default('manual');
            $table->string('external_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
            $table->index('country_code');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('materialized_path', 1024)->default('');
            $table->string('color', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['gallery_space_id', 'slug']);
            $table->index(['gallery_space_id', 'parent_id']);
        });

        Schema::create('tag_closure', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('tags')->cascadeOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->primary(['ancestor_id', 'descendant_id']);
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('nickname')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('gallery_space_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_closure');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('people');
        Schema::dropIfExists('places');
    }
};
