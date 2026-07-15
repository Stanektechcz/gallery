<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shared_todo_lists')) {
            Schema::create('shared_todo_lists', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 120);
                $table->text('description')->nullable();
                $table->string('kind', 24)->default('general');
                $table->string('color', 16)->default('#14b8a6');
                $table->string('icon', 16)->default('✅');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
                $table->index(['gallery_space_id', 'archived_at'], 'todo_lists_space_archive_idx');
            });
        }

        if (! Schema::hasTable('shared_todos')) {
            Schema::create('shared_todos', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('series_uuid')->nullable();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('list_id')->nullable()->constrained('shared_todo_lists')->nullOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('shared_todos')->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
                $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('status', 24)->default('open');
                $table->string('priority', 16)->default('normal');
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('due_at')->nullable();
                $table->dateTime('remind_at')->nullable();
                $table->timestamp('last_reminded_at')->nullable();
                $table->unsignedInteger('estimate_minutes')->nullable();
                $table->string('location', 255)->nullable();
                $table->json('recurrence')->nullable();
                $table->json('tags')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['gallery_space_id', 'status', 'due_at'], 'todos_space_status_due_idx');
                $table->index(['assigned_to', 'status', 'due_at'], 'todos_assignee_status_due_idx');
                $table->index(['series_uuid', 'due_at'], 'todos_series_due_idx');
            });
        }

        if (! Schema::hasTable('shared_todo_dependencies')) {
            Schema::create('shared_todo_dependencies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('todo_id')->constrained('shared_todos')->cascadeOnDelete();
                $table->foreignId('depends_on_id')->constrained('shared_todos')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['todo_id', 'depends_on_id'], 'todo_dependency_unique');
            });
        }

        if (! Schema::hasTable('shared_todo_comments')) {
            Schema::create('shared_todo_comments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('todo_id')->constrained('shared_todos')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->index(['todo_id', 'created_at'], 'todo_comments_todo_created_idx');
            });
        }

        if (! Schema::hasTable('entertainment_titles')) {
            Schema::create('entertainment_titles', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('added_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
                $table->string('media_type', 16);
                $table->string('title', 255);
                $table->string('original_title', 255)->nullable();
                $table->string('external_source', 24)->default('manual');
                $table->string('external_id', 64)->nullable();
                $table->date('release_date')->nullable();
                $table->unsignedSmallInteger('release_year')->nullable();
                $table->unsignedSmallInteger('runtime_minutes')->nullable();
                $table->unsignedSmallInteger('seasons_count')->nullable();
                $table->text('overview')->nullable();
                $table->string('poster_url', 2048)->nullable();
                $table->string('backdrop_url', 2048)->nullable();
                $table->string('trailer_url', 2048)->nullable();
                $table->string('original_language', 12)->nullable();
                $table->json('genres')->nullable();
                $table->string('status', 24)->default('proposed');
                $table->string('priority', 16)->default('normal');
                $table->string('watch_provider', 120)->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('watched_at')->nullable();
                $table->timestamps();
                $table->unique(['gallery_space_id', 'external_source', 'external_id'], 'entertainment_external_unique');
                $table->index(['gallery_space_id', 'status', 'media_type'], 'entertainment_space_status_type_idx');
            });
        }

        if (! Schema::hasTable('entertainment_votes')) {
            Schema::create('entertainment_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entertainment_title_id')->constrained('entertainment_titles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('interest');
                $table->boolean('cinema_preferred')->default(false);
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['entertainment_title_id', 'user_id'], 'entertainment_vote_user_unique');
            });
        }

        if (! Schema::hasTable('cinema_showings')) {
            Schema::create('cinema_showings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('entertainment_title_id')->nullable()->constrained('entertainment_titles')->nullOnDelete();
                $table->string('provider', 32)->default('cinema_city');
                $table->string('cinema_code', 32);
                $table->string('cinema_name', 160);
                $table->string('external_event_id', 80);
                $table->string('external_film_id', 80)->nullable();
                $table->string('title', 255);
                $table->unsignedSmallInteger('release_year')->nullable();
                $table->unsignedSmallInteger('runtime_minutes')->nullable();
                $table->string('poster_url', 2048)->nullable();
                $table->dateTime('starts_at');
                $table->string('auditorium', 80)->nullable();
                $table->string('format', 80)->nullable();
                $table->string('original_language', 16)->nullable();
                $table->string('dubbed_language', 16)->nullable();
                $table->string('subtitles_language', 16)->nullable();
                $table->boolean('sold_out')->default(false);
                $table->decimal('availability_ratio', 5, 4)->nullable();
                $table->string('booking_url', 2048)->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->json('attributes')->nullable();
                $table->timestamp('fetched_at');
                $table->timestamps();
                $table->unique(['provider', 'cinema_code', 'external_event_id'], 'cinema_showing_external_unique');
                $table->index(['cinema_code', 'starts_at'], 'cinema_showing_cinema_start_idx');
            });
        }

        if (! Schema::hasTable('cinema_sync_runs')) {
            Schema::create('cinema_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('cinema_code', 32);
                $table->date('from_date');
                $table->date('to_date');
                $table->string('status', 24);
                $table->unsignedInteger('showings_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['provider', 'cinema_code', 'created_at'], 'cinema_sync_provider_created_idx');
            });
        }

        if (! Schema::hasTable('viewing_date_proposals')) {
            Schema::create('viewing_date_proposals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('entertainment_title_id')->constrained('entertainment_titles')->cascadeOnDelete();
                $table->foreignId('proposed_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('cinema_showing_id')->nullable()->constrained('cinema_showings')->nullOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
                $table->dateTime('starts_at');
                $table->string('venue', 16)->default('home');
                $table->string('place_name', 255)->nullable();
                $table->text('note')->nullable();
                $table->string('status', 24)->default('proposed');
                $table->timestamps();
                $table->index(['entertainment_title_id', 'status', 'starts_at'], 'view_proposal_title_status_start_idx');
            });
        }

        if (! Schema::hasTable('viewing_proposal_votes')) {
            Schema::create('viewing_proposal_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('viewing_date_proposal_id')->constrained('viewing_date_proposals')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('response', 16);
                $table->timestamps();
                $table->unique(['viewing_date_proposal_id', 'user_id'], 'view_proposal_vote_user_unique');
            });
        }

        if (! Schema::hasTable('viewing_sessions')) {
            Schema::create('viewing_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('entertainment_title_id')->constrained('entertainment_titles')->cascadeOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
                $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
                $table->dateTime('watched_at');
                $table->string('venue', 16)->default('home');
                $table->unsignedSmallInteger('season_number')->nullable();
                $table->unsignedSmallInteger('episode_from')->nullable();
                $table->unsignedSmallInteger('episode_to')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->index(['entertainment_title_id', 'watched_at'], 'view_sessions_title_watched_idx');
            });
        }

        if (! Schema::hasTable('entertainment_reviews')) {
            Schema::create('entertainment_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entertainment_title_id')->constrained('entertainment_titles')->cascadeOnDelete();
                $table->foreignId('viewing_session_id')->nullable()->constrained('viewing_sessions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->decimal('rating', 3, 1);
                $table->text('review')->nullable();
                $table->string('favorite_moment', 500)->nullable();
                $table->boolean('watch_again')->default(false);
                $table->timestamps();
                $table->unique(['entertainment_title_id', 'viewing_session_id', 'user_id'], 'entertainment_review_session_user_uq');
            });
        }

        if (! Schema::hasTable('entertainment_progress')) {
            Schema::create('entertainment_progress', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entertainment_title_id')->constrained('entertainment_titles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('season_number')->default(1);
                $table->unsignedSmallInteger('episode_number')->default(0);
                $table->timestamps();
                $table->unique(['entertainment_title_id', 'user_id'], 'entertainment_progress_user_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['entertainment_progress', 'entertainment_reviews', 'viewing_sessions', 'viewing_proposal_votes', 'viewing_date_proposals', 'cinema_sync_runs', 'cinema_showings', 'entertainment_votes', 'entertainment_titles', 'shared_todo_comments', 'shared_todo_dependencies', 'shared_todos', 'shared_todo_lists'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
