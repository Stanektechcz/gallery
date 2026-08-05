<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates the VAPID key pair that Web Push needs. The keys are free to create and
 * belong to this deployment — they identify the sender to the push services, so they are
 * generated once and then left alone: replacing them invalidates every existing
 * subscription and members would have to allow notifications again.
 */
class GeneratePushKeysCommand extends Command
{
    protected $signature = 'gallery:push-keys {--force : Print a new pair even when keys are already configured}';

    protected $description = 'Vygeneruje VAPID klíče pro push notifikace';

    public function handle(): int
    {
        if (config('push.public_key') && ! $this->option('force')) {
            $this->warn('Klíče už jsou nastavené.');
            $this->line('Nové vygenerujete přepínačem --force, ale tím se zruší všechny stávající odběry.');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();

        $this->info('Vygenerováno. Doplňte do .env na serveru:');
        $this->newLine();
        $this->line('VAPID_SUBJECT=mailto:' . (config('mail.from.address') ?: 'vas@email.cz'));
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->newLine();
        $this->comment('Soukromý klíč nikam necommitujte. Po doplnění spusťte php artisan config:cache.');

        return self::SUCCESS;
    }
}
