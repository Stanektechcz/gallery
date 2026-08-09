<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connections that belong to a person rather than to the installation.
 *
 * The existing integration_settings table is the operator's: one row per provider for
 * the whole system, edited in the admin screens. That is the wrong shape here — two
 * people in one space each have their own Notion, and may additionally share a third
 * one between them.
 *
 * So a connection carries both a person and a space, plus how far it reaches:
 *
 *   personal — only the user who connected it ever sees it, even inside their own space
 *   shared   — every member of the space may read through it
 *
 * A user may hold several rows for the same provider, which is what "osobní i sdílený
 * Notion" means in practice: one personal, one shared, both live at once.
 *
 * Credentials are encrypted at rest with the app key, never returned by any endpoint,
 * and only ever leave this table inside a server-to-server request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_integrations')) {
            Schema::create('user_integrations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 30);                 // notion, discord, affine
                $table->string('visibility', 10)->default('personal');
                $table->string('label', 120)->nullable();       // what the owner calls it

                $table->text('encrypted_credentials')->nullable();

                // Who we are connected as, so the screen can say more than "connected".
                $table->string('account_id', 190)->nullable();
                $table->string('account_name', 190)->nullable();
                $table->string('account_avatar', 400)->nullable();

                $table->string('status', 20)->default('active');    // active, error, revoked
                $table->text('last_error')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();        // for tokens that do
                $table->timestamps();

                // The read the app makes on every page that touches an integration.
                $table->index(['gallery_space_id', 'provider', 'visibility'], 'user_integrations_lookup_idx');
                $table->index(['user_id', 'provider'], 'user_integrations_owner_idx');
            });
        }

        // What a space has pulled in from a provider, so a page stays readable when the
        // provider is slow or unreachable, and so a search does not hit their API.
        if (! Schema::hasTable('integration_documents')) {
            Schema::create('integration_documents', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_integration_id')->constrained()->cascadeOnDelete();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 30);
                $table->string('external_id', 190);
                $table->string('kind', 20)->default('page');     // page, database, doc
                $table->string('title', 400)->nullable();
                $table->string('url', 800)->nullable();
                $table->string('icon', 190)->nullable();
                $table->longText('excerpt')->nullable();          // plain text, for search
                $table->timestamp('external_updated_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                // A document belongs to exactly one connection; re-syncing updates in place.
                $table->unique(['user_integration_id', 'external_id'], 'integration_documents_unique');
                $table->index(['gallery_space_id', 'provider'], 'integration_documents_space_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_documents');
        Schema::dropIfExists('user_integrations');
    }
};
