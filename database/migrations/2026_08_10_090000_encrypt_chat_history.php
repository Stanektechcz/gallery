<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts message text at rest, and keeps every edit.
 *
 * What this protects against, stated plainly so nobody expects more of it: a stolen
 * database, a leaked backup, a curious look at the table. Ciphertext is bound to the
 * application key, which lives in .env and not in the database, so a dump on its own
 * reads as noise.
 *
 * What it is not: end-to-end encryption. The server encrypts and decrypts, so anyone
 * holding both the database and the application key can read a conversation. Real
 * end-to-end would mean keys that never leave the participants' devices, which also
 * means no history on a new device and no server-side search — a different product
 * decision, not a bigger version of this one.
 *
 * The column becomes text because ciphertext is several times longer than its input.
 *
 * chat_message_revisions keeps what a message said before each edit. Editing is allowed
 * and so is deleting, but neither destroys the record: a delete is a soft delete and an
 * edit leaves the previous wording behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_messages')) {
            // Widen first: encrypting into a shorter column would silently truncate.
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->longText('body')->nullable()->change();
            });

            $this->encryptExisting();
        }

        if (! Schema::hasTable('chat_message_revisions')) {
            Schema::create('chat_message_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
                $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->longText('body')->nullable();          // encrypted, as above
                $table->timestamp('replaced_at');
                $table->timestamps();
                $table->index('chat_message_id', 'chat_revisions_message_idx');
            });
        }
    }

    /**
     * Encrypts rows written before this migration.
     *
     * Chunked rather than loaded whole: a long-running couple's history is not something
     * to pull into memory in one go. Each row is checked first, so running the migration
     * twice cannot double-encrypt.
     */
    private function encryptExisting(): void
    {
        DB::table('chat_messages')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($row->body === null || $row->body === '') continue;

                // Already ciphertext? Decrypting succeeds only if it is.
                try {
                    Crypt::decryptString($row->body);
                    continue;
                } catch (\Throwable) {
                    // Plain text, as expected for anything written before now.
                }

                DB::table('chat_messages')->where('id', $row->id)
                    ->update(['body' => Crypt::encryptString($row->body)]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_revisions');

        // Deliberately not decrypting back: losing the key mid-rollback would destroy
        // history, and this migration is not worth that risk.
    }
};
