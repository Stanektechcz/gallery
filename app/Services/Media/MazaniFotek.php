<?php

namespace App\Services\Media;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Auth\PotvrzeniZamkem;
use App\Services\Auth\PristupDoGalerie;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mazat fotky mohou jen po společném schválení — pokud si dvojice po
 * vzájemném souhlasu nenastaví jinak.
 *
 * V režimu `spolecne` „Do koše" fotku jen navrhne; do koše ji přesune až
 * souhlas druhého z dvojice (nebo jeho vlastní „Do koše" na tutéž fotku).
 * V režimu `kazdy` maže každý sám jako dřív. Sám smí mazat i ten, kdo je
 * v prostoru jediný z dvojice — deaktivovaný partner se ale počítá, jinak by
 * stačilo mu přístup odebrat a smazat všechno.
 *
 * Odmítnutí jsou výjimky s kódem odpovědi (`abort()` — 403 cizí/host/jen
 * pro čtení/vlastní návrh, 409 nic k dohodě, 422 neznámý režim). Neúspěšné
 * ověření zámku při potvrzení režimu je `HttpResponseException` s hotovou
 * odpovědí z `PotvrzeniZamkem` (422/429). Řadič je nechá propadnout.
 *
 * Každý zápis je podmíněný (jako schválení nahrávky hosta) a hlásí se jen
 * to, co tenhle požadavek opravdu změnil — dvojklik ani souběh dvou
 * zařízení tak nevytvoří druhý záznam v protokolu.
 *
 * Dotazy obcházejí `SpaceContext` a omezují se na prostor výslovně, aby
 * fungovaly stejně z příkazu, fronty i testu.
 */
class MazaniFotek
{
    public const SPOLECNE = 'spolecne';

    public const KAZDY = 'kazdy';

    public const REZIMY = [self::SPOLECNE, self::KAZDY];

    /** Víc uuid v jednom `whereIn` MySQL zvládne, ale transakce se zbytečně táhne. */
    private const DAVKA = 500;

    public function rezim(GallerySpace $prostor): string
    {
        // Z databáze, ne z modelu — ten může být starší než potvrzení druhého.
        $rezim = GallerySpace::query()->whereKey($prostor->id)->value('media_delete_mode');

        return $rezim === self::KAZDY ? self::KAZDY : self::SPOLECNE;
    }

    /**
     * Kolik lidí tvoří dvojici — i s deaktivovanými a těmi jen pro čtení,
     * aby odebráním přístupu nešlo dohodu obejít.
     */
    public function pocetDvojice(GallerySpace $prostor): int
    {
        return DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('role', PristupDoGalerie::ROLE_DVOJICE)
            ->pluck('user_id')
            ->push($prostor->owner_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();
    }

    public function muzeSam(GallerySpace $prostor, User $kdo): bool
    {
        return $this->rezim($prostor) === self::KAZDY || $this->pocetDvojice($prostor) <= 1;
    }

    /**
     * Smí navrhovat, schvalovat a měnit režim? Rozhoduje role v TOMHLE
     * prostoru (ne `users.role`, to má `owner` každý účet, a ne první prostor
     * účtu jako `PristupDoGalerie::proc()`).
     */
    public function jeClenDvojice(GallerySpace $prostor, User $kdo): bool
    {
        // `null` je čerstvý účet, kterému výchozí hodnotu doplnila databáze.
        if ($kdo->is_active === false || $kdo->read_only_mode) {
            return false;
        }

        if ((int) $prostor->owner_id === (int) $kdo->id) {
            return true;
        }

        $role = DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->where('user_id', $kdo->id)
            ->value('role');

        return in_array((string) $role, PristupDoGalerie::ROLE_DVOJICE, true);
    }

    /**
     * Smí fotky z koše **trvale** smazat (jednu, vysypat koš, vysypat z panelu
     * rizik)?
     *
     * Aktivní člen dvojice, který je v tomhle prostoru vlastníkem nebo
     * správcem (`owner`/`admin` v členství). Běžný člen dvojice (`editor`)
     * maže do koše, ne z něj — nevratný krok zůstává vlastníkovi a správci.
     * Účet jen pro čtení nebo deaktivovaný nevratně nemaže nikdy.
     *
     * Jediné místo s tímhle pravidlem: dřív ho měl každý kontrolér po svém
     * (`users.role`, `isAdmin()`, `jeSpravce` i s editorem) a každý jinak.
     */
    public function smiTrvaleMazat(GallerySpace $prostor, User $kdo): bool
    {
        if (! $this->jeClenDvojice($prostor, $kdo)) {
            return false;
        }

        if ((int) $prostor->owner_id === (int) $kdo->id) {
            return true;
        }

        $role = DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->where('user_id', $kdo->id)
            ->value('role');

        return in_array((string) $role, ['owner', 'admin'], true);
    }

    /**
     * „Do koše": přesune, nebo navrhne. Na fotku, kterou už navrhl druhý,
     * je to souhlas a fotka jde do koše.
     *
     * @param  iterable<string>  $uuids
     * @param  string  $odkud  obrazovka pro protokol (knihovna, detail, trezor…)
     * @param  bool  $trezor  `Trezor::odemcen()` — zamčený trezor skryté fotky mine
     */
    public function doKose(GallerySpace $prostor, User $kdo, iterable $uuids, string $odkud, bool $trezor): VysledekMazani
    {
        $this->overClena($prostor, $kdo);
        $sam = $this->muzeSam($prostor, $kdo);
        $rezim = $this->rezim($prostor);
        $vysledek = new VysledekMazani;

        foreach ($this->davky($uuids) as $davka) {
            $vysledek = $vysledek->spoj(DB::transaction(function () use ($prostor, $kdo, $davka, $odkud, $trezor, $sam, $rezim) {
                [$skryte, $polozky] = $this->rozdelTrezor($this->nacti($prostor, $davka), $trezor);

                $castecny = $sam
                    ? $this->presunRovnou($polozky, $kdo, $rezim, $odkud)
                    : $this->navrhni($polozky, $kdo, $odkud);

                return $castecny->spoj(new VysledekMazani(preskoceno: $skryte));
            }));
        }

        return $vysledek;
    }

    /**
     * Souhlas s návrhem druhého. Vlastní návrh schválit nejde (403, když
     * v dávce není nic jiného).
     *
     * @param  iterable<string>  $uuids
     */
    public function schval(GallerySpace $prostor, User $kdo, iterable $uuids, bool $trezor): VysledekMazani
    {
        $this->overClena($prostor, $kdo);
        $vysledek = new VysledekMazani;

        foreach ($this->davky($uuids) as $davka) {
            $vysledek = $vysledek->spoj(DB::transaction(function () use ($prostor, $kdo, $davka, $trezor) {
                $nactene = $this->nacti($prostor, $davka)->whereNotNull('trash_requested_by');
                [$skryte, $polozky] = $this->rozdelTrezor($nactene, $trezor);
                $schvaleno = [];
                $vlastni = [];

                foreach ($polozky as $m) {
                    if ((int) $m->trash_requested_by === (int) $kdo->id) {
                        $vlastni[] = $m->uuid;
                    } elseif ($this->schvalJednu($m, (int) $m->trash_requested_by, $kdo, null)) {
                        $schvaleno[] = $m->uuid;
                    }
                }

                return new VysledekMazani(uzNavrzeno: $vlastni, schvaleno: $schvaleno, preskoceno: $skryte);
            }));
        }

        abort_if(
            $vysledek->schvaleno === [] && $vysledek->uzNavrzeno !== [] && $vysledek->preskoceno === [],
            403,
            'Vlastní návrh schválit nemůžete — musí ho potvrdit druhý z vás.'
        );

        return $vysledek;
    }

    /**
     * „Ponechat": zruší návrh. Navrhující ho tím stahuje, druhý odmítá.
     *
     * @param  iterable<string>  $uuids
     */
    public function ponechat(GallerySpace $prostor, User $kdo, iterable $uuids, bool $trezor): VysledekMazani
    {
        $this->overClena($prostor, $kdo);
        $vysledek = new VysledekMazani;

        foreach ($this->davky($uuids) as $davka) {
            $vysledek = $vysledek->spoj(DB::transaction(function () use ($prostor, $kdo, $davka, $trezor) {
                $nactene = $this->nacti($prostor, $davka)->whereNotNull('trash_requested_at');
                [$skryte, $polozky] = $this->rozdelTrezor($nactene, $trezor);
                $ponechano = [];

                foreach ($polozky as $m) {
                    $navrhl = $m->trash_requested_by === null ? null : (int) $m->trash_requested_by;
                    $zmeneno = $this->fotky()->whereKey($m->id)
                        ->whereNull('trashed_at')
                        ->whereNotNull('trash_requested_at')
                        ->when($navrhl === null, fn (Builder $q) => $q->whereNull('trash_requested_by'), fn (Builder $q) => $q->where('trash_requested_by', $navrhl))
                        ->update(['trash_requested_by' => null, 'trash_requested_at' => null]);

                    if ($zmeneno === 1) {
                        $akce = $navrhl === (int) $kdo->id ? 'media.trash_withdrawn' : 'media.trash_rejected';
                        AuditLog::record($akce, $m, ['navrhl' => $navrhl, 'ponechal' => (int) $kdo->id] + $this->soubor($m));
                        $ponechano[] = $m->uuid;
                    }
                }

                return new VysledekMazani(preskoceno: $skryte, ponechano: $ponechano);
            }));
        }

        return $vysledek;
    }

    /**
     * Návrh režimu. `spolecne` platí hned (zpřísnění smí každý z dvojice),
     * `kazdy` čeká na potvrzení druhého. Když ho druhý už navrhl, je tohle
     * potvrzení — a chce kód zámku nebo heslo.
     *
     * @return string navrzeno | potvrzeno | zprisneno | zruseno | beze_zmeny
     */
    public function navrhniRezim(GallerySpace $prostor, User $kdo, string $rezim, ?string $kod, ?string $heslo): string
    {
        $this->overClena($prostor, $kdo);
        abort_unless(in_array($rezim, self::REZIMY, true), 422, 'Neznámý režim mazání.');

        return $rezim === self::SPOLECNE
            ? $this->zprisni($prostor, $kdo)
            : $this->navrhniKazdy($prostor, $kdo, $kod, $heslo);
    }

    /**
     * Potvrzení návrhu druhého — „každý maže sám".
     *
     * @return string potvrzeno
     *
     * @throws HttpResponseException když kód zámku / heslo nesedí (422/429)
     */
    public function potvrdRezim(GallerySpace $prostor, User $kdo, ?string $kod, ?string $heslo): string
    {
        $this->overClena($prostor, $kdo);
        $stav = $this->stav($prostor);
        $navrhl = $stav->media_delete_mode_requested_by;

        abort_unless($stav->media_delete_mode_requested === self::KAZDY && $navrhl !== null, 409, 'Žádný návrh na změnu mazání nečeká.');
        // Dřív než heslo — navrhující by si jinak zbytečně spotřeboval pokus.
        abort_if((int) $navrhl === (int) $kdo->id, 403, 'Vlastní návrh potvrdit nemůžete — musí ho potvrdit druhý z vás.');

        $chyba = PotvrzeniZamkem::over($kdo, $kod, $heslo, 'gallery.delete_mode_confirm_failed', 'Kód zámku nesedí.');
        if ($chyba !== null) {
            throw new HttpResponseException($chyba);
        }

        $zmeneno = $this->prostor($prostor)
            ->where('media_delete_mode', self::SPOLECNE)
            ->where('media_delete_mode_requested', self::KAZDY)
            ->where('media_delete_mode_requested_by', $navrhl)
            ->update(['media_delete_mode' => self::KAZDY] + $this->bezNavrhuRezimu());
        abort_if($zmeneno === 0, 409, 'Návrh se mezitím změnil. Načtěte nastavení znovu.');

        AuditLog::record('gallery.delete_mode_confirmed', $prostor, ['navrhl' => (int) $navrhl, 'potvrdil' => (int) $kdo->id]);

        return 'potvrzeno';
    }

    /** Zruší čekající návrh režimu — smí kdokoli z dvojice. `false`, když nic nečekalo. */
    public function zrusNavrhRezimu(GallerySpace $prostor, User $kdo): bool
    {
        $this->overClena($prostor, $kdo);
        $navrhl = $this->stav($prostor)->media_delete_mode_requested_by;

        $zmeneno = $this->prostor($prostor)->whereNotNull('media_delete_mode_requested')->update($this->bezNavrhuRezimu());
        if ($zmeneno === 0) {
            return false;
        }

        AuditLog::record('gallery.delete_mode_cancelled', $prostor, ['navrhl' => $navrhl, 'zrusil' => (int) $kdo->id]);

        return true;
    }

    /**
     * Režim a čekající návrh pro obrazovku.
     *
     * @return array{rezim: string, navrh: array{rezim: string, navrhl: int|null, kdy: string|null}|null, pocetDvojice: int}
     */
    public function stavRezimu(GallerySpace $prostor): array
    {
        $stav = $this->stav($prostor);

        return [
            'rezim' => $stav->media_delete_mode === self::KAZDY ? self::KAZDY : self::SPOLECNE,
            'navrh' => $stav->media_delete_mode_requested === null ? null : [
                'rezim' => (string) $stav->media_delete_mode_requested,
                'navrhl' => $stav->media_delete_mode_requested_by,
                'kdy' => $stav->media_delete_mode_requested_at?->toIso8601String(),
            ],
            'pocetDvojice' => $this->pocetDvojice($prostor),
        ];
    }

    /**
     * Zruší všechny návrhy účtu — fotek i režimu (před smazáním účtu, aby po
     * něm nezůstal návrh, který nikdo nemůže stáhnout).
     *
     * @return int počet fotek, jejichž návrh zmizel
     */
    public function zrusNavrhyUzivatele(User $kdo): int
    {
        $pocet = $this->fotky()->withTrashed()
            ->where('trash_requested_by', $kdo->id)
            ->update(['trash_requested_by' => null, 'trash_requested_at' => null]);

        GallerySpace::query()->withTrashed()
            ->where('media_delete_mode_requested_by', $kdo->id)
            ->update($this->bezNavrhuRezimu());

        return $pocet;
    }

    private function zprisni(GallerySpace $prostor, User $kdo): string
    {
        $stav = $this->stav($prostor);

        $zmeneno = $this->prostor($prostor)
            ->where(fn (Builder $q) => $q->where('media_delete_mode', '!=', self::SPOLECNE)->orWhereNotNull('media_delete_mode_requested'))
            ->update(['media_delete_mode' => self::SPOLECNE] + $this->bezNavrhuRezimu());

        if ($zmeneno === 0) {
            return 'beze_zmeny';
        }

        if ($stav->media_delete_mode === self::KAZDY) {
            AuditLog::record('gallery.delete_mode_tightened', $prostor, ['kdo' => (int) $kdo->id]);

            return 'zprisneno';
        }

        AuditLog::record('gallery.delete_mode_cancelled', $prostor, ['navrhl' => $stav->media_delete_mode_requested_by, 'zrusil' => (int) $kdo->id]);

        return 'zruseno';
    }

    private function navrhniKazdy(GallerySpace $prostor, User $kdo, ?string $kod, ?string $heslo): string
    {
        abort_if($this->pocetDvojice($prostor) <= 1, 409, 'V galerii není nikdo další, s kým by se to dalo dohodnout.');

        $odpoved = $this->kazdyPodleStavu($prostor, $kdo, $kod, $heslo);
        if ($odpoved !== null) {
            return $odpoved;
        }

        // Návrh bez navrhujícího (smazaný účet) se smí přepsat.
        $zmeneno = $this->prostor($prostor)
            ->where('media_delete_mode', self::SPOLECNE)
            ->where(fn (Builder $q) => $q->whereNull('media_delete_mode_requested')->orWhereNull('media_delete_mode_requested_by'))
            ->update([
                'media_delete_mode_requested' => self::KAZDY,
                'media_delete_mode_requested_by' => $kdo->id,
                'media_delete_mode_requested_at' => now(),
            ]);

        if ($zmeneno === 0) {
            // Souběh: druhý mezitím navrhl nebo potvrdil — rozhodne se podle nového stavu.
            return $this->kazdyPodleStavu($prostor, $kdo, $kod, $heslo)
                ?? abort(409, 'Nastavení se mezitím změnilo. Načtěte ho znovu.');
        }

        AuditLog::record('gallery.delete_mode_proposed', $prostor, ['rezim' => self::KAZDY, 'navrhl' => (int) $kdo->id]);

        return 'navrzeno';
    }

    /** Výsledek, když o „každý sám" už rozhoduje stávající stav; `null` = navrhnout. */
    private function kazdyPodleStavu(GallerySpace $prostor, User $kdo, ?string $kod, ?string $heslo): ?string
    {
        $stav = $this->stav($prostor);

        if ($stav->media_delete_mode === self::KAZDY) {
            return 'beze_zmeny';
        }

        if ($stav->media_delete_mode_requested !== self::KAZDY || $stav->media_delete_mode_requested_by === null) {
            return null;
        }

        return (int) $stav->media_delete_mode_requested_by === (int) $kdo->id
            ? 'beze_zmeny'
            : $this->potvrdRezim($prostor, $kdo, $kod, $heslo);
    }

    /** @param  Collection<int, MediaItem>  $polozky */
    private function presunRovnou(Collection $polozky, User $kdo, string $rezim, string $odkud): VysledekMazani
    {
        $presunuto = [];

        foreach ($polozky as $m) {
            $zmeneno = $this->fotky()->whereKey($m->id)->whereNull('trashed_at')->update($this->doKoseHodnoty($kdo));

            if ($zmeneno === 1) {
                AuditLog::record('media.trash', $m, ['rezim' => $rezim, 'odkud' => $odkud, 'smazal' => (int) $kdo->id] + $this->soubor($m));
                $presunuto[] = $m->uuid;
            }
        }

        return new VysledekMazani(presunuto: $presunuto);
    }

    /** @param  Collection<int, MediaItem>  $polozky */
    private function navrhni(Collection $polozky, User $kdo, string $odkud): VysledekMazani
    {
        $navrzeno = [];
        $uz = [];
        $schvaleno = [];

        foreach ($polozky as $m) {
            $navrhl = $m->trash_requested_by === null ? null : (int) $m->trash_requested_by;

            if ($navrhl === null) {
                // Návrh bez navrhujícího (smazaný účet) se přepíše novým.
                $zmeneno = $this->fotky()->whereKey($m->id)->whereNull('trashed_at')->whereNull('trash_requested_by')
                    ->update(['trash_requested_by' => $kdo->id, 'trash_requested_at' => now()]);

                if ($zmeneno === 1) {
                    AuditLog::record('media.trash_proposed', $m, ['navrhl' => (int) $kdo->id, 'odkud' => $odkud] + $this->soubor($m));
                    $navrzeno[] = $m->uuid;

                    continue;
                }

                // Souběh: mezitím ho navrhl někdo jiný (nebo já z druhého okna).
                $navrhl = $this->fotky()->whereKey($m->id)->whereNull('trashed_at')->value('trash_requested_by');
                $navrhl = $navrhl === null ? null : (int) $navrhl;
            }

            if ($navrhl === (int) $kdo->id) {
                $uz[] = $m->uuid;
            } elseif ($navrhl !== null && $this->schvalJednu($m, $navrhl, $kdo, $odkud)) {
                $schvaleno[] = $m->uuid;
            }
        }

        return new VysledekMazani(navrzeno: $navrzeno, uzNavrzeno: $uz, schvaleno: $schvaleno);
    }

    /** Do koše, jen pokud návrh pořád platí a podal ho ten, s kým souhlasím. */
    private function schvalJednu(MediaItem $m, int $navrhl, User $kdo, ?string $odkud): bool
    {
        $zmeneno = $this->fotky()->whereKey($m->id)
            ->whereNull('trashed_at')
            ->where('trash_requested_by', $navrhl)
            ->where('trash_requested_by', '!=', $kdo->id)
            ->update($this->doKoseHodnoty($kdo));

        if ($zmeneno !== 1) {
            return false;
        }

        AuditLog::record('media.trash_approved', $m, array_filter([
            'navrhl' => $navrhl,
            'schvalil' => (int) $kdo->id,
            'odkud' => $odkud,
        ], fn ($hodnota) => $hodnota !== null) + $this->soubor($m));

        return true;
    }

    /** @return array<string, mixed> */
    private function doKoseHodnoty(User $kdo): array
    {
        return [
            'trashed_at' => now(),
            'purge_after' => now()->addDays((int) config('gallery.trash_retention_days', 30)),
            'trashed_by' => $kdo->id,
            'trash_requested_by' => null,
            'trash_requested_at' => null,
        ];
    }

    /** @return array<string, null> */
    private function bezNavrhuRezimu(): array
    {
        return [
            'media_delete_mode_requested' => null,
            'media_delete_mode_requested_by' => null,
            'media_delete_mode_requested_at' => null,
        ];
    }

    /**
     * Jméno souboru jen mimo trezor — přehled „Dnes" jména z protokolu vypisuje.
     *
     * @return array<string, string>
     */
    private function soubor(MediaItem $m): array
    {
        return $m->is_hidden ? [] : ['filename' => (string) $m->original_filename];
    }

    private function overClena(GallerySpace $prostor, User $kdo): void
    {
        abort_if($kdo->read_only_mode, 403, 'Účet je v režimu jen pro čtení.');
        abort_unless($this->jeClenDvojice($prostor, $kdo), 403, 'Mazat fotky a měnit pravidla mazání může jen dvojice galerie.');
    }

    /** @return list<list<string>> */
    private function davky(iterable $uuids): array
    {
        return collect($uuids)
            ->filter(fn ($uuid) => is_string($uuid) && $uuid !== '')
            ->unique()
            ->values()
            ->chunk(self::DAVKA)
            ->map(fn (Collection $davka) => $davka->values()->all())
            ->all();
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, MediaItem>
     */
    private function nacti(GallerySpace $prostor, array $uuids): Collection
    {
        return $this->fotky()
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('uuid', $uuids)
            ->whereNull('trashed_at')
            ->get(['id', 'uuid', 'gallery_space_id', 'is_hidden', 'original_filename', 'trash_requested_by', 'trash_requested_at']);
    }

    /**
     * Zamčený trezor: skryté fotky se přeskočí.
     *
     * @param  Collection<int, MediaItem>  $polozky
     * @return array{0: list<string>, 1: Collection<int, MediaItem>}
     */
    private function rozdelTrezor(Collection $polozky, bool $trezor): array
    {
        [$skryte, $ostatni] = $polozky->partition(fn (MediaItem $m) => $m->is_hidden && ! $trezor);

        return [$skryte->pluck('uuid')->values()->all(), $ostatni->values()];
    }

    /** @return Builder<MediaItem> */
    private function fotky(): Builder
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE);
    }

    /** @return Builder<GallerySpace> */
    private function prostor(GallerySpace $prostor): Builder
    {
        return GallerySpace::query()->whereKey($prostor->id);
    }

    private function stav(GallerySpace $prostor): GallerySpace
    {
        return GallerySpace::query()->whereKey($prostor->id)->firstOrFail([
            'id', 'media_delete_mode', 'media_delete_mode_requested',
            'media_delete_mode_requested_by', 'media_delete_mode_requested_at',
        ]);
    }
}
