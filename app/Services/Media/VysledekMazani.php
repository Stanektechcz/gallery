<?php

namespace App\Services\Media;

/**
 * Co se s vybranými fotkami stalo — seznamy uuid.
 *
 * Uuid, která v prostoru nejsou (cizí, smazaná, už v koši), se nehlásí
 * nikde: druhé kliknutí na hotovou věc je prázdný výsledek, ne chyba.
 * `preskoceno` jsou jen fotky z trezoru, když je zamčený — o jejich
 * existenci volající ví, protože je sám poslal.
 */
final readonly class VysledekMazani
{
    /**
     * @param  list<string>  $presunuto  do koše rovnou (každý sám, nebo jediný z dvojice)
     * @param  list<string>  $navrzeno  nový návrh ke smazání
     * @param  list<string>  $uzNavrzeno  můj návrh už čeká (dvojklik, vlastní návrh ke schválení)
     * @param  list<string>  $schvaleno  do koše souhlasem s návrhem druhého
     * @param  list<string>  $preskoceno  skryté v zamčeném trezoru
     * @param  list<string>  $ponechano  návrh zrušen, fotka zůstává
     */
    public function __construct(
        public array $presunuto = [],
        public array $navrzeno = [],
        public array $uzNavrzeno = [],
        public array $schvaleno = [],
        public array $preskoceno = [],
        public array $ponechano = [],
    ) {}

    public function spoj(self $dalsi): self
    {
        return new self(
            [...$this->presunuto, ...$dalsi->presunuto],
            [...$this->navrzeno, ...$dalsi->navrzeno],
            [...$this->uzNavrzeno, ...$dalsi->uzNavrzeno],
            [...$this->schvaleno, ...$dalsi->schvaleno],
            [...$this->preskoceno, ...$dalsi->preskoceno],
            [...$this->ponechano, ...$dalsi->ponechano],
        );
    }

    /** Co je teď v koši — rovnou i souhlasem. @return list<string> */
    public function vKosi(): array
    {
        return [...$this->presunuto, ...$this->schvaleno];
    }

    public function jePrazdny(): bool
    {
        return $this->presunuto === [] && $this->navrzeno === [] && $this->uzNavrzeno === []
            && $this->schvaleno === [] && $this->preskoceno === [] && $this->ponechano === [];
    }

    /** Hláška pro člověka, např. „Do koše 2 položky · Čeká na souhlas partnera 1 položka". */
    public function zprava(): string
    {
        $casti = array_filter([
            $this->kus('Do koše', count($this->vKosi())),
            $this->kus('Čeká na souhlas partnera', count($this->navrzeno) + count($this->uzNavrzeno)),
            $this->kus('Ponecháno', count($this->ponechano)),
            $this->kus('Přeskočeno v zamčeném trezoru', count($this->preskoceno)),
        ]);

        return $casti === [] ? 'Nic se nezměnilo.' : implode(' · ', $casti);
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return [
            'presunuto' => $this->presunuto,
            'navrzeno' => $this->navrzeno,
            'uzNavrzeno' => $this->uzNavrzeno,
            'schvaleno' => $this->schvaleno,
            'preskoceno' => $this->preskoceno,
            'ponechano' => $this->ponechano,
        ];
    }

    private function kus(string $co, int $pocet): ?string
    {
        if ($pocet === 0) {
            return null;
        }

        $slovo = $pocet === 1 ? 'položka' : ($pocet <= 4 ? 'položky' : 'položek');

        return $co.' '.$pocet.' '.$slovo;
    }
}
