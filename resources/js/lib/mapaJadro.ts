import * as leaflet from 'leaflet';
import 'leaflet/dist/leaflet.css';
import ikona2x from 'leaflet/dist/images/marker-icon-2x.png';
import ikona from 'leaflet/dist/images/marker-icon.png';
import stin from 'leaflet/dist/images/marker-shadow.png';

/**
 * Leaflet ze závislostí projektu, ne z cizího serveru.
 *
 * Tenhle soubor se nikdy nenačítá přímo — sahá se na něj přes `nactiMapu()`
 * v `mapa.ts`, aby se stáhl až ve chvíli, kdy je mapa opravdu na obrazovce.
 * Kdyby ho stránky importovaly rovnou, přibalil by se Leaflet ke každé z nich.
 */

// Balíček je ještě v CommonJS, takže podle způsobu zabalení vyjde buď rovnou
// jmenný prostor, nebo se schová pod `default`.
const L = ((leaflet as unknown as { default?: typeof leaflet }).default ?? leaflet) as typeof leaflet;

/**
 * Výchozí značka ukazuje na obrázky vedle sebe. Leaflet si adresu odvozuje z toho,
 * odkud se načetl jeho vlastní styl — což při zabalení do balíčku nesedí a značka
 * zmizí. Adresy se proto nastaví natvrdo z importovaných souborů.
 *
 * `_getIconUrl` se musí odstranit, jinak si Leaflet cestu odvodí znovu a nastavení přebije.
 */
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl;

L.Icon.Default.mergeOptions({
    iconUrl: ikona,
    iconRetinaUrl: ikona2x,
    shadowUrl: stin,
});

export default L;
