<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $ratings = [
        'story_rating' => 'rating',
        'acting_rating' => 'story_rating',
        'visual_rating' => 'acting_rating',
        'sound_rating' => 'visual_rating',
        'emotion_rating' => 'sound_rating',
        'pace_rating' => 'emotion_rating',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('entertainment_reviews')) {
            return;
        }
        foreach ($this->ratings as $column => $after) {
            if (! Schema::hasColumn('entertainment_reviews', $column)) {
                Schema::table('entertainment_reviews', function (Blueprint $table) use ($column, $after) {
                    $table->decimal($column, 3, 1)->nullable()->after($after);
                });
            }
        }
        if (! Schema::hasColumn('entertainment_reviews', 'recommendation')) {
            Schema::table('entertainment_reviews', function (Blueprint $table) {
                $table->string('recommendation', 16)->nullable()->after('pace_rating');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('entertainment_reviews')) {
            return;
        }
        $columns = array_values(array_filter(
            [...array_keys($this->ratings), 'recommendation'],
            fn (string $column) => Schema::hasColumn('entertainment_reviews', $column)
        ));
        if ($columns !== []) {
            Schema::table('entertainment_reviews', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
