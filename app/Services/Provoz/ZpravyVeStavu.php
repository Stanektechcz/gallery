<?php

namespace App\Services\Provoz;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Zpravy;
use App\Support\SpaceContext;
use Illuminate\Support\Str;

/**
 * Zprávy, které přišly jako změna stavu.
 *
 * „Zpráva odeslána," řekl prototyp — a nikam ji neodeslal. Bublina se objevila
 * v prohlížeči odesílatele, druhý z dvojice o ní nevěděl a po zavření
 * záložky zmizela. Totéž u výsledku rozhodovacího kolečka a týdenního
 * shrnutí, které se „posílaly do chatu".
 *
 * `chatOf()` navíc vrací `state.chat || AMSG`, takže první odeslaná zpráva
 * zastínila celý skutečný hovor.
 */
class ZpravyVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['chat', 'msgList'];

    /** Jak dlouho zpátky se stejná věta od téhož člověka považuje za tutéž. */
    private const DUPLICITA_MINUT = 5;

    public function __construct(private readonly Zpravy $obsah) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('chat', $patch) || array_key_exists('msgList', $patch);
    }

    /** @return array<string, mixed> */
    public function bezZprav(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Odešle nové repliky a vrátí hovor tak, jak ho zná server.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if ($uzivatel === null) {
            return [];
        }

        /*
         * Nová je jen ta replika, kterou prototyp právě vyrobil.
         *
         * Repliky ze serveru dostávají `m0`, `m1`, … a v celém chatu uuid;
         * nově napsané mají v identifikátoru pomlčku (`m-n7`, `g-n3`). Bez
         * toho rozdílu by se celý hovor při každém odeslání uložil znovu.
         */
        foreach ((array) ($patch['chat'] ?? []) as $m) {
            $this->zRepliky((array) $m, 'm-', 'a', $prostor, $uzivatel);
        }

        foreach ((array) ($patch['msgList'] ?? []) as $m) {
            $this->zRepliky((array) $m, 'g-n', 'A', $prostor, $uzivatel);
        }

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'chat' => $this->doHovoru($obsah['AMSG'] ?? []),
            'msgList' => $this->doVlakna($obsah['MSGS'] ?? []),
        ], fn ($v) => $v !== []);
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  string  $predpona  jak vypadá identifikátor nově napsané repliky
     * @param  string  $ja  která strana je „moje" v téhle kolekci
     */
    private function zRepliky(array $m, string $predpona, string $ja, GallerySpace $prostor, User $uzivatel): void
    {
        if (! str_starts_with((string) ($m['id'] ?? ''), $predpona)) {
            return;
        }

        // Bublinu za druhého nikdo nenapíše — ani ukázková odpověď, kterou si
        // prototyp bez serveru dopisoval sám.
        if (($m['who'] ?? $ja) !== $ja) {
            return;
        }

        $text = trim((string) ($m['text'] ?? ''));

        if ($text === '') {
            return;
        }

        $this->posli($text, $prostor, $uzivatel);
    }

    private function posli(string $text, GallerySpace $prostor, User $uzivatel): void
    {
        // Dvakrát odeslaná táž věta během pěti minut je jedna věta: patch se
        // opakuje po výpadku sítě a hovor by z toho koktal.
        $uz = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('created_by', $uzivatel->id)
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICITA_MINUT))
            ->get()
            ->contains(fn (ChatMessage $m) => trim((string) $m->body) === $text);

        if ($uz) {
            return;
        }

        ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $uzivatel->id,
            'body' => $text,
        ]);
    }

    /**
     * Hovor do tvaru, ve kterém ho prototyp drží ve stavu.
     *
     * Identifikátory schválně bez pomlčky — příště se tedy neodešlou znovu.
     *
     * @param  list<array<int, string>>  $amsg
     * @return list<array<string, string>>
     */
    private function doHovoru(array $amsg): array
    {
        $hovor = [];

        foreach ($amsg as $i => $r) {
            $hovor[] = ['who' => $r[0], 'text' => $r[1], 'meta' => $r[2], 'id' => 'm'.$i];
        }

        return $hovor;
    }

    /**
     * Celé vlákno do tvaru, ve kterém ho drží obrazovka Zpráv.
     *
     * @param  list<array<int, mixed>>  $msgs
     * @return list<array<string, mixed>>
     */
    private function doVlakna(array $msgs): array
    {
        $vlakno = [];

        foreach ($msgs as $i => $m) {
            $vlakno[] = [
                'id' => $m[0], 'who' => $m[1], 'day' => $m[2], 'time' => $m[3],
                'type' => $m[4], 'text' => $m[5], 'extra' => $m[6], 'n' => $i * 5,
            ];
        }

        return $vlakno;
    }
}
