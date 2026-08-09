<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Categories, channels and tags — the shape Discord settled on, for the same reasons.
 *
 * A space already had two kinds of conversation: direct, between two people, and group,
 * an explicit list. Channels are the third, and they differ in the one way that matters:
 *
 *   group    membership is a list; you are in it because someone put you there
 *   channel  membership is the space; you are in it because you are here
 *
 * That is why a channel needs no invitations to be useful, and why a private channel is
 * the exception rather than the rule. `visibility` says which: 'open' means every member
 * of the space may read and write, 'invite' falls back to the participant list.
 *
 * Categories only group channels for display. They carry no permissions, because a
 * category that could restrict access would be a second, invisible place to look when
 * somebody cannot see something.
 *
 * Tags cross-cut: a channel and a direct conversation can both be tagged "dovolená",
 * which a category cannot express — one category per conversation, many tags.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversation_categories')) {
            Schema::create('conversation_categories', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 80);
                $table->string('icon', 16)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->index(['gallery_space_id', 'position'], 'conversation_categories_order_idx');
            });
        }

        if (! Schema::hasTable('conversation_tags')) {
            Schema::create('conversation_tags', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->string('name', 40);
                $table->string('colour', 7)->default('#7c5cff');
                $table->timestamps();
                // Two tags of the same name in one space would be a mistake, not a choice.
                $table->unique(['gallery_space_id', 'name'], 'conversation_tags_unique');
            });
        }

        if (! Schema::hasTable('conversation_tag')) {
            Schema::create('conversation_tag', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('conversation_tag_id')->constrained('conversation_tags')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['conversation_id', 'conversation_tag_id'], 'conversation_tag_unique');
            });
        }

        if (! Schema::hasColumn('conversations', 'conversation_category_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->foreignId('conversation_category_id')->nullable()->after('gallery_space_id')
                    ->constrained('conversation_categories')->nullOnDelete();
                $table->string('visibility', 10)->default('invite')->after('kind');
                $table->string('topic', 190)->nullable()->after('title');
                $table->unsignedSmallInteger('position')->default(0)->after('topic');
                // The one a space opens on, and the one that cannot be deleted.
                $table->boolean('is_default')->default(false)->after('position');
                $table->boolean('is_archived')->default(false)->after('is_default');
            });
        }

        $this->seedMainChannel();
    }

    /**
     * Every space gets a main channel, and whatever it was already talking in becomes it.
     *
     * Without this a space that has been chatting for weeks would open on an empty
     * channel list with its history apparently gone. The history is fine either way, but
     * "where did it go" is not a question an upgrade should raise.
     */
    private function seedMainChannel(): void
    {
        foreach (DB::table('gallery_spaces')->pluck('id') as $spaceId) {
            $category = DB::table('conversation_categories')
                ->where('gallery_space_id', $spaceId)->where('name', 'Obecné')->value('id');

            $category ??= DB::table('conversation_categories')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $spaceId,
                'name' => 'Obecné',
                'icon' => '💬',
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (DB::table('conversations')->where('gallery_space_id', $spaceId)->where('is_default', true)->exists()) {
                continue;
            }

            // The busiest existing group becomes the main channel, rather than a new
            // empty one appearing beside a conversation people are already using.
            $existing = DB::table('conversations')
                ->where('gallery_space_id', $spaceId)->where('kind', 'group')
                ->orderByDesc('last_message_at')->first();

            if ($existing) {
                DB::table('conversations')->where('id', $existing->id)->update([
                    'kind' => 'channel',
                    'visibility' => 'open',
                    'conversation_category_id' => $category,
                    'title' => $existing->title ?: 'hlavní',
                    'is_default' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $id = DB::table('conversations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $spaceId,
                'conversation_category_id' => $category,
                'kind' => 'channel',
                'visibility' => 'open',
                'title' => 'hlavní',
                'icon' => '💬',
                'is_default' => true,
                'last_message_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('gallery_space_user')->where('gallery_space_id', $spaceId)->pluck('user_id') as $userId) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $id,
                    'user_id' => $userId,
                    'role' => 'member',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            foreach (['topic', 'position', 'is_default', 'is_archived', 'visibility'] as $column) {
                if (Schema::hasColumn('conversations', $column)) $table->dropColumn($column);
            }
            if (Schema::hasColumn('conversations', 'conversation_category_id')) {
                $table->dropForeign(['conversation_category_id']);
                $table->dropColumn('conversation_category_id');
            }
        });

        Schema::dropIfExists('conversation_tag');
        Schema::dropIfExists('conversation_tags');
        Schema::dropIfExists('conversation_categories');
    }
};
