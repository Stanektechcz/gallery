<?php

namespace App\Console\Commands;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Services\Notifications\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Co musí platit, než se aplikace pustí ven.
 *
 * `.env.example` má správné produkční hodnoty, jenže tím nikdo nezaručí, že
 * se na serveru použily. „Před nasazením zkontrolovat" je věta, na kterou se
 * jednou zapomene — a `APP_DEBUG=true` na veřejném serveru ukáže při první
 * chybě celý zásobník volání, cesty na disku i připojovací údaje.
 *
 * Tohle je proto příkaz, ne poznámka: pustí se v nasazovacím skriptu a když
 * něco nesedí, skončí nenulově a nasazení se zastaví.
 *
 * Mimo produkci nic nekontroluje — na vývoji jsou ty hodnoty správně jiné.
 */
class PredNasazenimCommand extends Command
{
    protected $signature = 'galerie:pred-nasazenim {--vzdy : Zkontrolovat i mimo produkci}';

    protected $description = 'Ověří nastavení, které musí platit na produkci — a zastaví nasazení, když ne.';

    public function handle(): int
    {
        if (config('app.env') !== 'production' && ! $this->option('vzdy')) {
            $this->line('Prostředí není produkce ('.config('app.env').') — přeskočeno. `--vzdy` to vynutí.');

            return self::SUCCESS;
        }

        $chyby = [];
        $varovani = [];

        foreach ($this->pravidla() as [$popis, $plati, $proc, $vazne]) {
            if ($plati) {
                $this->line('  <fg=green>✓</> '.$popis);

                continue;
            }

            $this->line('  <fg='.($vazne ? 'red' : 'yellow').'>×</> '.$popis.' — '.$proc);
            $vazne ? $chyby[] = $popis : $varovani[] = $popis;
        }

        $this->newLine();

        if ($chyby !== []) {
            $this->error(count($chyby).' × nastavení, se kterým se nasazovat nemá.');

            return self::FAILURE;
        }

        if ($varovani !== []) {
            $this->warn(count($varovani).' × k rozmyšlení. Nasazení to nebrání.');

            return self::SUCCESS;
        }

        $this->info('Nastavení je připravené na produkci.');

        return self::SUCCESS;
    }

    /**
     * Co se kontroluje.
     *
     * `[popis, platí, proč to vadí, je to vážné]`. Vážné zastaví nasazení;
     * ostatní se jen vypíšou.
     *
     * @return list<array{0: string, 1: bool, 2: string, 3: bool}>
     */
    private function pravidla(): array
    {
        $url = (string) config('app.url');

        return [
            [
                'APP_DEBUG=false',
                config('app.debug') === false,
                'při první chybě by se návštěvníkovi ukázal zásobník volání, cesty na disku i připojovací údaje',
                true,
            ],
            [
                'APP_KEY nastavený',
                ! empty(config('app.key')),
                'bez něj se nedá rozšifrovat stav páru ani sezení',
                true,
            ],
            [
                'APP_URL přes HTTPS',
                str_starts_with($url, 'https://'),
                'podepsané adresy náhledů i odkaz na sdílení by mířily na http',
                true,
            ],
            [
                'Sezení jen přes HTTPS',
                config('session.secure') === true,
                'cookie sezení by šla po nešifrovaném spojení',
                true,
            ],
            [
                'Sezení zašifrované',
                config('session.encrypt') === true,
                'obsah sezení leží v databázi čitelný',
                false,
            ],
            [
                'Sezení mimo soubory',
                config('session.driver') !== 'file',
                'na víc než jednom procesu se sezení rozpadne',
                false,
            ],
            [
                'Fronta mimo `sync`',
                config('queue.default') !== 'sync',
                'zpracování fotek by běželo v požadavku a nahrávání by trvalo minuty',
                true,
            ],
            [
                'Log neukládá ladicí výpisy',
                ! in_array((string) config('logging.channels.stack.level', config('log_level', 'debug')), ['debug', ''], true),
                'ladicí výpisy zaplní disk a bývá v nich obsah požadavků',
                false,
            ],
            [
                'Zkompilovaný soubor prototypu je na místě',
                File::exists(rtrim((string) config('galerie.prototyp_path', resource_path('galerie')), '/\\').DIRECTORY_SEPARATOR.'galerie-desktop.dc.html'),
                'aplikace by na `/` vrátila 503',
                true,
            ],
            [
                'Sestavené soubory rozhraní jsou na místě',
                File::exists(public_path('build/manifest.json')),
                'stránka sdíleného odkazu by se hostovi nevykreslila',
                true,
            ],
            [
                /*
                 * Přesný opak toho, co tu stálo dřív.
                 *
                 * Pravidlo znělo „odkaz existuje" — jenže `deploy.sh` ho schválně
                 * odstraňuje: vydává originály fotek (i z trezoru) přímo webovým
                 * serverem, bez přihlášení, mimo `/files`, kde se ověřuje podpis
                 * nebo členství. Hlasovky hostů (`HostKomentarController`) na disk
                 * `public` sice ukládá, ale nikde se z nich nedělá `Storage::url`
                 * ani jiná veřejná adresa — přehrávají se přes `/files/hlasovky/…`,
                 * což `MediaFileController::smi()` pustí jen členovi prostoru.
                 * Odkaz tedy nesmí existovat vůbec.
                 */
                'Odkaz na úložiště (public/storage) neexistuje',
                ! File::exists(public_path('storage')),
                'vydával by originály fotek i z trezoru bez přihlášení — mimo /files, kde se to hlídá',
                true,
            ],
            [
                'proc_open dostupné',
                function_exists('proc_open')
                    && ! in_array('proc_open', $this->zakazaneFunkce(), true),
                'plánovač spouští příkazy přes proc_open a stejně tak zpracování videa a EXIF dat — bez něj obojí tiše neudělá nic',
                false,
            ],
            [
                'MAIL_MAILER posílá poštu ('.config('mail.default').')',
                PasswordResetController::emailyChodi(),
                'obnova hesla a pozvánky by nikam nedorazily',
                false,
            ],
            [
                'Klíče VAPID nastavené (push notifikace)',
                app(WebPushService::class)->configured(),
                'push upozornění by se nikdy neodeslala',
                false,
            ],
            [
                'Mezipaměť mimo `array`',
                config('cache.default') !== 'array',
                'omezení počtu pokusů i stav trezoru by se ztrácely s každým požadavkem',
                true,
            ],
            [
                'Jazyk aplikace cs',
                config('app.locale') === 'cs',
                'rozhraní i systémové zprávy by byly anglicky',
                false,
            ],
            [
                'E-mail vlastníka nastavený',
                ! empty(config('gallery.owner_email')),
                'systémová upozornění nemají kam chodit',
                false,
            ],
            [
                'Registrace zavřená',
                config('gallery.registration_open') === false,
                'kdokoli se znalostí adresy by si mohl založit účet',
                false,
            ],
        ];
    }

    /** Jména z `disable_functions`, oříznutá o mezery kolem čárek. */
    private function zakazaneFunkce(): array
    {
        return array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    }
}
