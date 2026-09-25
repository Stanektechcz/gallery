<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\Media\MazaniFotek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Zrušení účtu po lhůtě.
 *
 * Nastavení slibovalo „Po čtrnácti dnech se smaže profil, deník i zprávy —
 * nevratně", jenže žádná úloha to nikdy neudělala: žádost se jen zapsala do
 * předvoleb a účet žil dál se všemi daty.
 *
 * Smaže se, co člověk sám napsal nebo namluvil (deník, zprávy, hlasovky,
 * jeho reakce), přihlášení všech zařízení a profil. Fotky zůstávají v galerii
 * dvojice — nastavení je nezmiňuje a patří oběma. Řádek účtu se nemaže, jen
 * anonymizuje: drží na něj odkazy fotky a protokol.
 */
class ZruseniUctu
{
    public const LHUTA_DNI = 14;

    /** Disk příloh zpráv, hlasovek a nahraných fotek profilu (viz ChatController, VoiceNoteController, AvatarController). */
    private const DISK = 'local';

    public function __construct(private readonly MazaniFotek $mazani) {}

    /** Proč zrušit teď nejde, nebo `null`. */
    public function prekazka(User $user): ?string
    {
        if (GallerySpace::where('owner_id', $user->id)->exists()) {
            return 'Jste vlastník galerie. Nejdřív předejte vlastnictví druhému z dvojice, pak účet zrušte.';
        }

        return null;
    }

    public function naplanovano(User $user): ?Carbon
    {
        $kdy = is_array($user->preferences) ? ($user->preferences['delete_requested_at'] ?? null) : null;

        return $kdy ? Carbon::parse($kdy) : null;
    }

    /** @return Collection<int, User> */
    public function splatne(?Carbon $ted = null): Collection
    {
        $ted ??= now();

        // Předvolby jsou JSON a účtů je pár: filtr v PHP místo dotazu, který se na MySQL a SQLite chová jinak.
        return User::query()
            ->whereNotNull('preferences')
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => ($kdy = $this->naplanovano($user)) !== null && $kdy->lte($ted))
            ->values();
    }

    /** @return array<string, int> Kolik čeho zmizelo. */
    public function proved(User $user): array
    {
        $soubory = [];
        $pocty = [];

        DB::transaction(function () use ($user, &$soubory, &$pocty) {
            $id = $user->id;

            if (Schema::hasTable('journal_entries')) {
                $pocty['denik'] = JournalEntry::withoutGlobalScopes()->where('created_by', $id)->forceDelete();
            }

            if (Schema::hasTable('chat_messages')) {
                $zpravy = ChatMessage::withoutGlobalScopes()->where('created_by', $id);
                $soubory = array_merge($soubory, (clone $zpravy)->whereNotNull('media_path')->pluck('media_path')->all());
                $pocty['zpravy'] = $zpravy->forceDelete();
            }

            if (Schema::hasTable('chat_reactions')) {
                DB::table('chat_reactions')->where('user_id', $id)->delete();
            }

            if (Schema::hasTable('voice_notes')) {
                $hlasovky = VoiceNote::withoutGlobalScopes()->where('created_by', $id);
                $soubory = array_merge($soubory, (clone $hlasovky)->whereNotNull('path')->pluck('path')->all());
                $pocty['hlasovky'] = $hlasovky->delete();
            }

            $pocty['zarizeni'] = $user->tokens()->delete();

            foreach (['sessions', 'webauthn_credentials', 'push_subscriptions'] as $tabulka) {
                if (Schema::hasTable($tabulka)) {
                    DB::table($tabulka)->where('user_id', $id)->delete();
                }
            }

            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $id)->delete();
            }

            // Před odchodem z prostorů: návrh smazání (fotky i režimu) by po
            // zrušeném účtu zůstal viset a nikdo by ho nemohl stáhnout. Uvnitř
            // transakce — když zrušení padne, návrhy zůstanou jako dřív.
            $pocty['navrhy_mazani'] = $this->mazani->zrusNavrhyUzivatele($user);

            $user->gallerySpaces()->detach();

            if ($user->avatar_path) {
                $soubory[] = $user->avatar_path;
            }

            $user->forceFill([
                'name' => 'Zrušený účet',
                'email' => 'zruseny-'.$id.'@ucet.invalid',
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'is_active' => false,
                'avatar_path' => null,
                'avatar_preset' => null,
                'invitation_token' => null,
                'preferences' => null,
                'last_login_ip' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'app_lock_pin' => null,
                'app_lock_recovery' => null,
                'app_lock_set_at' => null,
            ])->save();

            AuditLog::record('account.deleted', $user, $pocty);
        });

        // Soubory až po zápisu: kdyby transakce padla, nesmí zmizet soubor od zprávy, která zůstala.
        foreach (array_unique(array_filter($soubory)) as $cesta) {
            Storage::disk(self::DISK)->delete($cesta);
        }

        return $pocty;
    }
}
