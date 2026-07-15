<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('relationship_milestones')) {
            return;
        }

        if (! Schema::hasColumn('relationship_milestones', 'kind')) {
            Schema::table('relationship_milestones', function (Blueprint $table) {
                $table->string('kind', 24)->default('milestone')->after('title')->index();
            });
        }
        if (! Schema::hasColumn('relationship_milestones', 'person_name')) {
            Schema::table('relationship_milestones', function (Blueprint $table) {
                $table->string('person_name', 120)->nullable()->after('kind');
            });
        }
        if (! Schema::hasColumn('relationship_milestones', 'relationship')) {
            Schema::table('relationship_milestones', function (Blueprint $table) {
                $table->string('relationship', 32)->nullable()->after('person_name')->index();
            });
        }
        if (! Schema::hasColumn('relationship_milestones', 'is_highlighted')) {
            Schema::table('relationship_milestones', function (Blueprint $table) {
                $table->boolean('is_highlighted')->default(false)->after('relationship');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('relationship_milestones')) {
            return;
        }

        $columns = array_values(array_filter(
            ['kind', 'person_name', 'relationship', 'is_highlighted'],
            fn (string $column) => Schema::hasColumn('relationship_milestones', $column),
        ));
        if ($columns !== []) {
            Schema::table('relationship_milestones', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
