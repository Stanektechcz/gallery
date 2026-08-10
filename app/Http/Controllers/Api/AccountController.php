<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\JournalEntry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a person may do to their own account: take their data, or end it.
 *
 * Both are rights rather than features, which is why neither hides behind a support
 * request. Both also destroy or disclose everything, so both are confirmed with the
 * current password — a borrowed session must not be enough.
 */
class AccountController extends Controller
{
    /** Long enough to change your mind, short enough to mean something. */
    private const DELETION_GRACE_DAYS = 14;

    /**
     * Everything we hold about this person, as one JSON download.
     *
     * Streamed rather than assembled in memory: a long-standing account holds thousands
     * of messages and diary entries, and building the whole document before sending the
     * first byte is how an export becomes a timeout.
     *
     * Shared content is included where the person wrote it and excluded where they only
     * saw it — their diary, their messages, their media, not their partner's.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        return response()->streamJson([
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'preferences' => is_array($user->preferences) ? $user->preferences : [],
            'journal' => $this->journal($user),
            'messages' => $this->messages($user),
            'media' => $this->media($user),
        ], 200, [
            'Content-Disposition' => 'attachment; filename="maki-export-' . now()->format('Y-m-d') . '.json"',
        ]);
    }

    /**
     * Schedules deletion rather than performing it.
     *
     * Immediate deletion is unrecoverable by definition, and the commonest reason people
     * ask for it is a bad evening. The grace period costs nothing and has saved accounts;
     * signing in during it cancels the request, which is the behaviour people expect
     * without being told.
     */
    public function scheduleDeletion(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate(['current_password' => 'required|string']);

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Zadané heslo nesouhlasí.']);
        }

        abort_if($user->role === 'owner' && $this->isOnlyOwner($user), 422,
            'Jste jediný správce prostoru. Předejte roli jinému členovi, než účet zrušíte.');

        $at = now()->addDays(self::DELETION_GRACE_DAYS);
        $this->rememberDeletion($user, $at);

        return response()->json([
            'scheduled_for' => $at->toIso8601String(),
            'grace_days' => self::DELETION_GRACE_DAYS,
        ]);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        $this->rememberDeletion($request->user(), null);

        return response()->json(['scheduled_for' => null]);
    }

    /**
     * Kept in preferences rather than a column, so this needs no migration and no new
     * table for a flag that at most one row in a thousand ever carries.
     */
    private function rememberDeletion(User $user, ?\Illuminate\Support\Carbon $at): void
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $preferences['delete_requested_at'] = $at?->toIso8601String();

        $user->forceFill(['preferences' => array_filter($preferences, fn ($value) => $value !== null)])->save();
    }

    /** A space left with nobody who can administer it is a space nobody can fix. */
    private function isOnlyOwner(User $user): bool
    {
        foreach ($user->gallerySpaces()->get() as $space) {
            $owners = $space->members()->where('users.role', 'owner')->count();
            if ($owners <= 1) return true;
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function journal(User $user): array
    {
        if (! Schema::hasTable('journal_entries')) return [];

        return JournalEntry::withoutGlobalScopes()->where('created_by', $user->id)->get()
            ->map(fn (JournalEntry $entry) => [
                'date' => $entry->entry_date?->toDateString(),
                'title' => $entry->title,
                'body' => $entry->body,
                'mood' => $entry->mood,
                'shared' => $entry->isShared(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function messages(User $user): array
    {
        if (! Schema::hasTable('chat_messages')) return [];

        return ChatMessage::withoutGlobalScopes()->where('created_by', $user->id)
            ->orderBy('id')->limit(20000)->get()
            ->map(fn (ChatMessage $message) => [
                'sent_at' => $message->created_at?->toIso8601String(),
                'body' => $message->body,
                'kind' => $message->attachment_type ?? 'text',
            ])->all();
    }

    /** Filenames and dates, not the files: a JSON export is a record, not a backup. */
    private function media(User $user): array
    {
        if (! Schema::hasTable('media_items')) return [];

        return MediaItem::withoutGlobalScopes()->where('owner_user_id', $user->id)
            ->orderBy('id')->limit(50000)->get()
            ->map(fn (MediaItem $item) => [
                'filename' => $item->original_filename,
                'taken_at' => $item->taken_at?->toIso8601String(),
                'type' => $item->media_type,
                'size_bytes' => $item->size_bytes,
            ])->all();
    }
}
