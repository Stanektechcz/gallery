<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Správa účtu z příkazové řádky.
 *
 * Aplikace heslo nikde nenastavuje: přihlašovací obrazovka ho chce, formulář na
 * jeho změnu je až za přihlášením a `PUT /api/profil/heslo` navíc vyžaduje to
 * dosavadní. Účet, který heslo nemá — nebo ho nikdo nezná — se tím zamkne sám
 * a zvenčí se s tím nedá nic dělat.
 *
 * Jméno je tu ze stejného důvodu: aplikace ho bere z `users.name` a ukazuje ho
 * všude, kde mluví o dvojici. Přejmenovat se dá jen v profilu, do kterého se
 * bez hesla nikdo nedostane.
 *
 * Tohle je tedy cesta pro toho, kdo má server.
 */
class NastavHesloCommand extends Command
{
    protected $signature = 'gallery:ucet
        {email? : E-mail účtu; bez něj se vypíší všechny}
        {--heslo : Nastavit heslo (zadává se skrytě)}
        {--jmeno= : Nové jméno, které aplikace ukazuje}';

    protected $description = 'Vypíše účty, nastaví heslo nebo přejmenuje účet';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if ($email === '') {
            return $this->vypis();
        }

        $ucet = User::where('email', $email)->first();

        if (! $ucet) {
            $this->error("Účet {$email} v databázi není.");

            return self::FAILURE;
        }

        $jmeno = $this->option('jmeno');

        if ($jmeno !== null) {
            if (mb_strlen(trim((string) $jmeno)) < 2) {
                $this->error('Jméno musí mít aspoň dva znaky.');

                return self::FAILURE;
            }

            $stare = $ucet->name;
            $ucet->forceFill(['name' => trim((string) $jmeno)])->save();
            $this->info("Přejmenováno: {$stare} → {$ucet->name}");
        }

        if ($this->option('heslo')) {
            return $this->heslo($ucet);
        }

        if ($jmeno === null) {
            $this->line('Nic k udělání. Přidejte --heslo nebo --jmeno="Jméno Příjmení".');
        }

        return self::SUCCESS;
    }

    private function vypis(): int
    {
        $this->info('Účty v aplikaci:');

        foreach (User::orderBy('id')->get(['id', 'name', 'email', 'password']) as $ucet) {
            $this->line(sprintf(
                '  %-4s %-24s %-34s %s',
                $ucet->id,
                $ucet->name,
                $ucet->email,
                $ucet->password ? 'heslo nastavené' : 'BEZ HESLA — přihlásit se nedá',
            ));
        }

        $this->newLine();
        $this->line('  php artisan gallery:ucet adresa@example.cz --heslo');
        $this->line('  php artisan gallery:ucet adresa@example.cz --jmeno="Markéta Kubíčková"');

        return self::SUCCESS;
    }

    private function heslo(User $ucet): int
    {
        $heslo = (string) $this->secret("Nové heslo pro {$ucet->name} ({$ucet->email})");

        /*
         * Deset znaků: stejný strop jako u změny hesla v aplikaci. Kdyby tudy
         * šlo nastavit slabší heslo než formulářem, byl by tenhle příkaz
         * obchvatem vlastního pravidla.
         */
        if (mb_strlen($heslo) < 10) {
            $this->error('Heslo musí mít aspoň deset znaků.');

            return self::FAILURE;
        }

        if ($heslo !== (string) $this->secret('Ještě jednou pro kontrolu')) {
            $this->error('Hesla se neshodují — nic se nezměnilo.');

            return self::FAILURE;
        }

        $ucet->forceFill(['password' => Hash::make($heslo)])->save();

        /*
         * Vydané přihlašovací klíče zůstávají.
         *
         * Kdo si mění heslo, protože o něj přišel, nechce zároveň odhlásit
         * telefon, na kterém je zrovna přihlášený. Odhlášení ostatních zařízení
         * je v aplikaci vlastní tlačítko a má být vědomé.
         */
        $this->info("Heslo pro {$ucet->email} nastaveno. Přihlášená zařízení zůstávají přihlášená.");

        return self::SUCCESS;
    }
}
