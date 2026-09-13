<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Druhý z dvojice je správce, ne host.
 *
 * API galerie od téhle verze pouští dovnitř jen role vlastník, správce
 * (`owner`, `admin`, `editor`) — host má podle administrace vidět jen sdílené
 * odkazy. Sloupec `gallery_space_user.role` má ale výchozí hodnotu `viewer`,
 * takže člen přidaný bez výslovné role by se po nasazení do galerie nedostal.
 *
 * Opravuje se jen jednoznačný případ: výchozí prostor, v něm vlastník a právě
 * jeden další člen. U třetího člena (skutečný host, rodina) se nic nemění —
 * tam o roli rozhodl člověk v administraci.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gallery_spaces') || ! Schema::hasTable('gallery_space_user')) {
            return;
        }

        $prostor = DB::table('gallery_spaces')->orderByDesc('is_default')->orderBy('id')->first(['id', 'owner_id']);

        if (! $prostor || ! $prostor->owner_id) {
            return;
        }

        $ostatni = DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->where('user_id', '!=', $prostor->owner_id)
            ->get(['user_id', 'role']);

        if ($ostatni->count() !== 1) {
            return;
        }

        $clen = $ostatni->first();

        if (in_array($clen->role, ['owner', 'admin', 'editor'], true)) {
            return;
        }

        DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->where('user_id', $clen->user_id)
            ->update(['role' => 'editor', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Původní role byla výchozí hodnota sloupce, ne rozhodnutí — není co vracet.
    }
};
