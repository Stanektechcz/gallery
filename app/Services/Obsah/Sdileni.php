<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sdílení a systém ve tvaru, ve kterém je kreslí prototyp.
 *
 * Odkazy, hosté i časové kapsle mají v aplikaci vlastní tabulky; prototyp
 * z nich neukazoval nic — pět napsaných odkazů na doménu, která nikam nevede,
 * a dvě fotky od babičky, které nikdo nenahrál.
 *
 * Trezor odsud **nechodí**. Skládal se tu ze skrytých položek knihovny a
 * posílal se na každé načtení stránky bez ohledu na zámek, takže obsah byl
 * v prohlížeči dřív, než si obrazovka řekla o heslo. Dodává ho `System`, a jen
 * s odemčeným trezorem.
 */
class Sdileni implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function skupina(): string
    {
        return 'sdileni';
    }

    /**
     * Odkazy se posílají celé.
     *
     * Zneplatněný odkaz musí z obrazovky zmizet hned. Bez toho by tam zůstal
     * viset řádek s adresou, která už nefunguje — a dvojice by ji někomu
     * poslala.
     */
    public function uplne(): array
    {
        return ['SHARES'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'SHARES' => $this->odkazy($prostor),
            'GUEST_Q' => $this->hoste($prostor),
            'KAPS' => $this->kapsle($prostor),
            /*
             * `VAULT_ITEMS` odsud **nechodí** — dodává je `System`, a jen se
             * skutečně odemčeným trezorem.
             *
             * Tenhle poskytovatel je posílal na každé načtení stránky bez
             * ohledu na zámek, takže obsah trezoru byl venku pořád: obrazovka
             * si sice řekla o heslo, ale to, co za tou zdí je, měl prohlížeč
             * dávno v paměti. Zámek, který se dá obejít otevřením konzole,
             * není zámek.
             *
             * A ještě jedna věc: dva poskytovatelé téže kolekce se přetahovali
             * o to, který dorazí později. Podle časování obrazovka ukazovala
             * jednou zamčené prázdno a jindy obsah.
             */
            'OFFPACKS' => $this->baliky($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Sdílené odkazy: `{ name, url, content, created, expires, expTag, protection, views, … }`.
     *
     * @return list<array<string, mixed>>
     */
    private function odkazy(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('shared_links')) {
            return [];
        }

        $dnes = CarbonImmutable::now();

        return DB::table('shared_links')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(function (object $o) use ($dnes) {
                $do = $o->expires_at ? CarbonImmutable::parse($o->expires_at) : null;
                $vyprselo = $do && $do->lt($dnes);

                return array_filter([
                    // Číslo řádku v tabulce. Obrazovka podle něj pozná odkaz,
                    // který má upravit nebo zneplatnit — podle názvu to nešlo,
                    // dva odkazy se můžou jmenovat stejně.
                    'id' => (int) $o->id,
                    'name' => $o->name ?: 'Sdílený odkaz',
                    // Zkrácená podoba, jakou prototyp kreslí do tabulky.
                    'url' => rtrim(preg_replace('~^https?://~', '', config('app.url')), '/')
                        .'/s/'.substr($o->token, 0, 4).'…',
                    'content' => $this->obsahOdkazu($o),
                    'created' => CarbonImmutable::parse($o->created_at)->format('j. n. Y'),
                    'expires' => match (true) {
                        ! $do => 'Bez expirace',
                        $vyprselo => 'Expirovalo',
                        default => 'Za '.$this->pocet((int) ceil($dnes->diffInDays($do, false)), 'den', 'dny', 'dní'),
                    },
                    'expTag' => $vyprselo ? 'tag-neutral' : 'tag-accent',
                    'protection' => $o->password_hash ? 'Heslo' : 'Bez hesla',
                    'views' => (string) $o->use_count,
                    'n' => (int) $o->id,
                    // Přepínač vzkazů. Dosud se ukládal jen do stavu
                    // v prohlížeči, takže po odhlášení platilo něco jiného,
                    // než co dvojice nastavila.
                    'comments' => (bool) $o->allow_comments,
                    'guest' => (bool) $o->allow_guest_upload,
                    'download' => (bool) $o->allow_download,
                    'meta' => (bool) $o->show_metadata,
                    'msg' => $o->description ?: null,
                ], fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    private function obsahOdkazu(object $o): string
    {
        if ($o->target_type === 'album' && $o->target_id && Schema::hasTable('albums')) {
            $nazev = DB::table('albums')->where('id', $o->target_id)->value('title');

            return $nazev ? 'Album '.$nazev : 'Album';
        }

        return match ($o->target_type) {
            'media' => 'Jedna položka',
            'selection' => 'Výběr položek',
            default => 'Sdílený obsah',
        };
    }

    /**
     * Fronta od hostů: `{ id, who, whom, what, when, share, n }`.
     *
     * Jen to, co ještě nikdo neprošel — schválené fotky už jsou v knihovně
     * a v téhle frontě by jen strašily.
     *
     * @return list<array<string, mixed>>
     */
    private function hoste(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('guest_uploads')) {
            return [];
        }

        return DB::table('guest_uploads as n')
            ->join('shared_links as o', 'o.id', '=', 'n.shared_link_id')
            ->where('o.gallery_space_id', $prostor->id)
            ->where('n.status', 'pending')
            ->orderByDesc('n.created_at')
            ->limit(30)
            ->get(['n.uuid', 'n.contributor_name', 'n.created_at', 'n.id', 'o.name AS odkaz'])
            ->groupBy('contributor_name')
            ->map(function (Collection $davka) {
                $prvni = $davka->first();
                $kdo = $prvni->contributor_name ?: 'Host';

                return [
                    'id' => $prvni->uuid,
                    'who' => $kdo,
                    // Druhý pád do věty „fotky od Kláry"; bez skloňování se
                    // nedá poznat, jestli je to jméno, nebo vztah.
                    'whom' => $this->druhyPad($kdo),
                    'what' => $this->pocet($davka->count(), 'fotka', 'fotky', 'fotek'),
                    'when' => $this->kdy(CarbonImmutable::parse($prvni->created_at)),
                    'share' => $prvni->odkaz ?: 'Sdílený odkaz',
                    'n' => (int) $prvni->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Časové kapsle: `{ id, kind, from, open, trig, title, body }`.
     *
     * Zapečetěná kapsle se **neposílá s textem**. Celý smysl je, že se otevře
     * v den, na který se čeká — a obsah leží v prohlížeči, kde se dá přečíst.
     *
     * @return list<array<string, mixed>>
     */
    private function kapsle(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('time_capsules')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();
        $dnes = CarbonImmutable::now();

        return DB::table('time_capsules')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('deliver_at')
            ->limit(40)
            ->get()
            ->map(function (object $k) use ($jmena, $dnes) {
                $kdy = CarbonImmutable::parse($k->deliver_at);
                $otevrena = $k->status === 'delivered' || $kdy->lte($dnes);

                return [
                    'id' => $k->uuid,
                    'kind' => $k->media_item_id ? 'fotka' : 'vzkaz',
                    'from' => $jmena[$k->created_by] ?? 'oba',
                    'open' => $kdy->format('Y-m-d'),
                    'trig' => $k->event_id ? 'k události' : null,
                    'title' => $k->title,
                    // Text až po otevření; do té doby ho nemá kdo číst.
                    'body' => $otevrena ? (string) ($k->message ?? '') : '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Balíčky do offline: `[klíč, název, popis, velikost v MB, ikona, vybráno]`.
     *
     * Počítají se z knihovny, ne z katalogu: „4 218 fotek letos" má být pravda
     * i pro dvojici, která začala fotit loni.
     *
     * @return list<array<int, mixed>>
     */
    private function baliky(GallerySpace $prostor): array
    {
        $zaklad = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false);

        $letos = (clone $zaklad())->where('taken_at', '>=', CarbonImmutable::now()->startOfYear());
        $letosPocet = (clone $letos)->count();
        $oblibene = (clone $zaklad())->where('is_favorite', true);
        $oblibenePocet = (clone $oblibene)->count();

        if (! $letosPocet && ! $oblibenePocet) {
            return [];
        }

        // Náhledová kvalita je zhruba desetina originálu; plná je originál.
        $mb = fn (int $bajtu) => max(1, (int) round($bajtu / 1_048_576));

        return array_values(array_filter([
            $letosPocet ? [
                'letos',
                'Letos ('.CarbonImmutable::now()->year.')',
                $this->pocet($letosPocet, 'položka', 'položky', 'položek').' v náhledové kvalitě',
                $mb((int) ((clone $letos)->sum('size_bytes') / 10)),
                'ph-images',
                true,
            ] : null,
            $oblibenePocet ? [
                'fav',
                'Oblíbené',
                $this->pocet($oblibenePocet, 'položka', 'položky', 'položek').' v plné kvalitě',
                $mb((int) (clone $oblibene)->sum('size_bytes')),
                'ph-heart-straight',
                true,
            ] : null,
        ]));
    }

    // ——— formát ———

    private function kdy(CarbonImmutable $kdy): string
    {
        $dni = (int) $kdy->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

        return match (true) {
            $dni === 0 => 'dnes '.$kdy->format('G:i'),
            $dni === 1 => 'včera',
            default => $kdy->day.'. '.self::MESICE[$kdy->month],
        };
    }

    /**
     * Jméno do druhého pádu.
     *
     * Prototyp píše „3 fotky od Kláry", takže tvar musí sedět. Čeština se
     * z tabulky vyčerpat nedá — tohle pokrývá běžná ženská a mužská jména
     * a zbytek nechává být, což je pořád lepší než „od Klára".
     */
    private function druhyPad(string $jmeno): string
    {
        return match (true) {
            str_ends_with($jmeno, 'a') => mb_substr($jmeno, 0, -1).'y',
            str_ends_with($jmeno, 'e') => mb_substr($jmeno, 0, -1).'e',
            preg_match('/[bcdfghjklmnprstvzš]$/ui', $jmeno) === 1 => $jmeno.'a',
            default => $jmeno,
        };
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return number_format($kolik, 0, ',', ' ').' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
