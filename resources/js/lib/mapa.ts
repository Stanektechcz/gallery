/**
 * Načtení mapy — z vlastního balíčku, ne z unpkg.com.
 *
 * Sedm stránek si Leaflet dosud vkládalo do hlavičky jako `<script>` z cizí CDN, přestože
 * je stejná verze mezi závislostmi projektu. Znamenalo to tři věci: galerie, která jinak
 * nikam ven nemluví, hlásila při každé mapě IP adresu cizímu serveru; bez internetu nebo
 * při výpadku unpkg se mapa nenačetla vůbec (a jako aplikace na ploše má fungovat i offline);
 * a co se do stránky vloží, záleželo na někom cizím.
 *
 * Výsledek je zabalený vedle ostatního kódu a stahuje se až při prvním použití, takže
 * stránky bez mapy si ho nestáhnou. Načítá se jednou za celou návštěvu — druhá mapa
 * dostane tentýž slib, ne druhé stahování.
 *
 * `window.L` se nastavuje schválně: stránky s mapou na něj sahají a tenhle způsob je
 * nechává beze změny.
 */

let nacitani: Promise<void> | null = null;

export function nactiMapu(): Promise<void> {
    if (typeof window === 'undefined') {
        return Promise.resolve();
    }

    if ((window as unknown as { L?: unknown }).L) {
        return Promise.resolve();
    }

    nacitani ??= import('./mapaJadro').then((modul) => {
        (window as unknown as { L?: unknown }).L = modul.default;
    });

    return nacitani;
}
