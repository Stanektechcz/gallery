/**
 * Je na obrazovce místo, aby se rozbalený panel vyplatil?
 *
 * Panely, které se umějí sbalit, se dosud otevíraly rovnou. Na monitoru je to správně —
 * vedle sebe se vejdou dva sloupce a člověk vidí celý rozpočet bez klikání. Na telefonu
 * se ale tentýž panel roztáhne přes celou šířku a naroste do výšky: plán cesty měřil
 * 5820 bodů, sedm obrazovek posouvání, a polovinu z toho zabíraly tři panely otevřené
 * dokola i u cesty, kde nebyl zapsaný jediný výdaj.
 *
 * Výchozí stav se proto řídí šířkou. Není to rozvržení, které by patřilo do stylů —
 * obsah se totiž vůbec nevykresluje, dokud je panel sbalený, takže `hidden` by nepomohl
 * a stránka by se stejně tahala celá. Rozhoduje se jednou při prvním vykreslení; kdo si
 * panel otevře nebo zavře, má přednost a nic mu to nepřepíše.
 *
 * Hranice je stejná jako `lg` v Tailwindu, protože právě od ní se panely na této stránce
 * lámou do dvou sloupců.
 */
export function naSirokeObrazovce(): boolean {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return true;
    }

    return window.matchMedia('(min-width: 1024px)').matches;
}
