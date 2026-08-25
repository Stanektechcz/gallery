<?php

namespace App\Console\Commands;

use App\Models\MoneyRequest;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Support\Cestina;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Připomene žádost o peníze, na kterou nikdo neodpověděl.
 *
 * Žádost je jediná věc v rozpočtech, kde na odpovědi opravdu záleží — někdo v cizině
 * čeká na nájem. Zapadlá mezi ostatní upozornění po dvou dnech přestane existovat, a
 * druhá strana zatím netuší, že se čeká zrovna na ni.
 *
 * Připomíná se jednou, ne opakovaně. Denně otravovat kvůli tomu, že partner ještě
 * nedošel k bankomatu, by z upozornění udělalo šum, který se vypne.
 */
class MoneyRequestRemindersCommand extends Command
{
    protected $signature = 'gallery:money-request-reminders
                            {--after=2 : Po kolika dnech ticha připomenout}';

    protected $description = 'Připomene nevyřízené žádosti o peníze.';

    public function handle(): int
    {
        $hranice = Carbon::now()->subDays(max(1, (int) $this->option('after')));
        $posláno = 0;

        $zadosti = MoneyRequest::where('status', MoneyRequest::STATUS_PENDING)
            ->where('created_at', '<=', $hranice)
            ->with(['requester:id,name', 'recipient:id,name'])
            ->get();

        foreach ($zadosti as $zadost) {
            $klic = "money-request:reminded:{$zadost->id}";
            if (! \Illuminate\Support\Facades\Cache::add($klic, true, now()->addDays(30))) continue;

            $prijemce = User::find($zadost->to_user_id);
            if (! $prijemce) continue;

            $castka = number_format((float) $zadost->amount, 2, ',', ' ') . ' ' . $zadost->currency;
            $dni = (int) $zadost->created_at->diffInDays(Carbon::now());

            $prijemce->notify(new GalleryNotification(
                'finance.request',
                ($zadost->requester?->name ?? 'Partner') . ' čeká na ' . $castka
                    . ' už ' . $this->dny($dni) . '.',
                '/rozpocty#zadosti',
                '⏳',
                ['money_request_uuid' => $zadost->uuid],
            ));

            $posláno++;
        }

        $this->info($posláno > 0 ? "Připomenuto žádostí: {$posláno}." : 'Žádná žádost nečeká dost dlouho.');

        return self::SUCCESS;
    }

    /** 1 den / 2-4 dny / 5+ dní — pravidlo je jednou v App\Support\Cestina. */
    private function dny(int $count): string
    {
        return Cestina::dny($count);
    }
}
