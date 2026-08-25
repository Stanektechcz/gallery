<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Ověří, že podepisování push zpráv funguje — bez odesílání.
 *
 * Push notifikace jsou páteří celé aplikace: chodí přes ně připomínky cyklu, hlídání
 * rozpočtu i žádosti o peníze. Jenže kdyby se podepisování rozbilo, poznalo by se to
 * jediným způsobem — tím, že nikomu nic nepřijde. A to je ta nejhorší zpráva, jakou
 * může člověk dostat se čtrnáctidenním zpožděním.
 *
 * Nic neodesílá. Vyrobí VAPID hlavičku pro smyšlenou adresu, což je přesně ta cesta,
 * která uvnitř knihovny sahá na kryptografii a na balíčky web-token. Když projde,
 * projde i skutečné odeslání.
 *
 * Vypisuje i verze balíčků, aby se dal výstup před změnou a po ní porovnat řádek po
 * řádku. Vzniklo to kvůli výměně web-token za novější řadu, ale smysl to má i potom:
 * po každém `composer update` je to půl vteřiny, která odpoví na otázku, jestli
 * upozornění pořád fungují.
 */
class PushSelfTestCommand extends Command
{
    protected $signature = 'gallery:push-selftest';

    protected $description = 'Ověří podepisování push zpráv (VAPID) — nic neodesílá.';

    public function handle(): int
    {
        $this->line('');
        $this->line('  Balíčky');

        foreach (['minishlink/web-push', 'web-token/jwt-signature', 'web-token/jwt-core', 'thecodingmachine/safe'] as $balicek) {
            $verze = \Composer\InstalledVersions::isInstalled($balicek)
                ? \Composer\InstalledVersions::getPrettyVersion($balicek)
                : 'není nainstalován';

            $this->line(sprintf('    %-28s %s', $balicek, $verze));
        }

        $this->line('');
        $this->line('  Podpis');

        // Klíče z konfigurace, ne vygenerované: ověřuje se tím i to, že ty uložené
        // jsou použitelné. Vygenerovaný pár by prošel i tehdy, kdyby byl v .env nesmysl.
        $verejny = (string) config('push.public_key');
        $soukromy = (string) config('push.private_key');

        if ($verejny === '' || $soukromy === '') {
            $this->error('    VAPID klíče nejsou nastavené — spusťte gallery:push-keys.');

            return self::FAILURE;
        }

        try {
            $hlavicky = VAPID::getVapidHeaders(
                'https://fcm.googleapis.com',
                (string) config('push.subject'),
                $verejny,
                $soukromy,
                'aes128gcm',
            );
        } catch (\Throwable $problem) {
            $this->error('    Podepsání selhalo: ' . $problem->getMessage());
            $this->line('');
            $this->line('    Upozornění by v tomhle stavu nikomu nedorazila.');

            return self::FAILURE;
        }

        $token = trim(preg_replace('/^vapid\s+t=/i', '', $hlavicky['Authorization'] ?? ''));
        $token = explode(',', $token)[0];
        $casti = explode('.', $token);

        if (count($casti) !== 3 || strlen(end($casti)) < 20) {
            $this->error('    Hlavička nevypadá jako podepsaný token.');

            return self::FAILURE;
        }

        $this->line('    hlavička:  ' . substr($token, 0, 48) . '…');
        $this->line('    částí:     ' . count($casti) . ' (hlavička, obsah, podpis)');
        $this->line('    délka podpisu: ' . strlen(end($casti)) . ' znaků');
        $this->line('');
        $this->info('  Podepisování funguje — upozornění se odesílat dají.');
        $this->line('');

        return self::SUCCESS;
    }
}
