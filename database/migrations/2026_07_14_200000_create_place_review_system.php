<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('places', 'review_album_id')) {
            Schema::table('places', function (Blueprint $table) {
                $table->unsignedBigInteger('review_album_id')->nullable()->after('created_by');
                $table->foreign('review_album_id', 'places_review_album_fk')->references('id')->on('albums')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('place_reviews')) {
            Schema::create('place_reviews', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('place_id')->constrained()->cascadeOnDelete();
                $table->foreignId('place_plan_id')->nullable()->constrained('place_plans')->nullOnDelete();
                $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 20)->default('published');
                $table->dateTime('visited_at')->nullable();
                $table->string('visit_context', 32)->nullable();
                $table->unsignedTinyInteger('party_size')->nullable();
                foreach (['overall', 'service', 'staff_friendliness', 'food', 'food_quality', 'drink', 'speed', 'menu', 'atmosphere', 'cleanliness', 'value'] as $criterion) {
                    $table->decimal($criterion . '_rating', 2, 1)->nullable();
                }
                $table->unsignedSmallInteger('wait_minutes')->nullable();
                $table->decimal('total_amount', 12, 2)->nullable();
                $table->string('currency', 3)->default('CZK');
                $table->boolean('would_return')->nullable();
                $table->boolean('recommends')->nullable();
                $table->text('positives')->nullable();
                $table->text('improvements')->nullable();
                $table->text('notes')->nullable();
                $table->text('next_time_note')->nullable();
                $table->timestamps();
                $table->index(['place_id', 'status', 'visited_at'], 'place_reviews_place_status_visit_idx');
                $table->index(['author_user_id', 'visited_at'], 'place_reviews_author_visit_idx');
                $table->unique(['place_plan_id', 'author_user_id'], 'place_reviews_plan_author_uq');
            });
        }

        if (! Schema::hasTable('place_review_items')) {
            Schema::create('place_review_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('place_review_id')->constrained('place_reviews')->cascadeOnDelete();
                $table->string('category', 24);
                $table->string('name', 160);
                $table->decimal('quantity', 8, 2)->default(1);
                foreach (['overall', 'quality', 'presentation', 'portion', 'value'] as $criterion) {
                    $table->decimal($criterion . '_rating', 2, 1)->nullable();
                }
                $table->decimal('price', 12, 2)->nullable();
                $table->string('currency', 3)->default('CZK');
                $table->boolean('would_order_again')->nullable();
                $table->text('note')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['place_review_id', 'category', 'sort_order'], 'place_review_items_review_sort_idx');
            });
        }

        if (! Schema::hasTable('place_review_media')) {
            Schema::create('place_review_media', function (Blueprint $table) {
                $table->foreignId('place_review_id')->constrained('place_reviews')->cascadeOnDelete();
                $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('place_review_item_id')->nullable()->constrained('place_review_items')->nullOnDelete();
                $table->string('subject', 24)->default('overall');
                $table->string('caption', 500)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->primary(['place_review_id', 'media_item_id']);
                $table->index(['place_review_id', 'sort_order'], 'place_review_media_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('place_review_media');
        Schema::dropIfExists('place_review_items');
        Schema::dropIfExists('place_reviews');
        if (Schema::hasColumn('places', 'review_album_id')) {
            Schema::table('places', function (Blueprint $table) {
                $table->dropForeign('places_review_album_fk');
                $table->dropColumn('review_album_id');
            });
        }
    }
};
