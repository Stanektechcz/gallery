{{-- Napojení prototypu na backend. Vkládá se do dokumentu při odeslání, aby
     soubory v resources/galerie zůstaly přesně takové, jaké přišly ze ZIPu. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="manifest" href="/manifest.webmanifest">
{{-- Runtime prototypu si React a Babel bere z unpkg. Navázat spojení předem
     ušetří na mobilní síti dvě až tři stovky milisekund z prvního vykreslení. --}}
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
{{-- Autorské soubory načítá runtime až po zpracování dokumentu. Napovědět
     prohlížeči, ať je stáhne rovnou, je nejlevnější zrychlení, které se dá
     udělat, aniž by se do prototypu sáhlo. --}}
<link rel="preload" as="script" href="/galerie-data.js">
<link rel="preload" as="script" href="/galerie-mechanismy-logika.js">
<link rel="preload" as="script" href="/image-slot.js">
<link rel="preload" as="style" href="/_ds/broadsheet-a4da30e6-ea56-42b3-88f2-00edc07c2f31/styles.css">
<script>
(function () {
  // Bez téhle adresy jede galerie-api.js v režimu „local" a všechno zůstane
  // v prohlížeči jednoho zařízení. Musí být nastavená dřív, než se ten soubor
  // načte — proto je hlavička v <head> a ne až na konci těla.
  window.GALERIE_API_BASE = '/api';
  window.GALERIE_TOKEN_URL = '/sanctum/token';
  window.GALERIE_VAPID_KEY = @json(config('push.public_key'));

  // Kdo je přihlášený. Prototyp si zámek řeší sám, tohle je jen pro případ,
  // že by chtěl vědět, čí je to sezení.
  window.GALERIE_USER = @json($ucet ?? null);

  // Service worker drží skořápku offline a doručuje zápisy, které vznikly bez
  // signálu. Dosah „/" je podmínka, ne volba: ve scope /galerie/ by neviděl
  // /api/ a fronta zápisů by nefungovala.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
    });
  }

  /*
   * Aby druhý viděl, co první napsal.
   *
   * Klient stav načte jednou při startu a pak už jen posílá vlastní změny —
   * co mezitím napsal partner, se objeví teprve po obnovení stránky. Kontrakt
   * na to má nepovinný SSE proud, jenže ten by znamenal držet PHP proces pro
   * každou otevřenou kartu, a klient pro něj stejně nemá kód.
   *
   * Dotaz jednou za dvacet vteřin je levnější a stačí: stav je jeden dokument
   * a odpověď na nezměněný jde z paměti service workera. Neptá se, když je
   * karta schovaná (nikdo se nedívá) ani když čeká vlastní zápis (přišel by
   * o něj, než se stihne odeslat).
   */
  setInterval(function () {
    var api = window.GalerieApi;
    if (! api || api.mode !== 'http' || ! window.GALERIE_API_TOKEN) return;
    if (document.hidden) return;
    if ((api.status() || {}).pending) return;

    api.load();
  }, 20000);

  window.addEventListener('visibilitychange', function () {
    var api = window.GalerieApi;
    if (! document.hidden && api && api.mode === 'http' && window.GALERIE_API_TOKEN) api.load();
  });
})();
</script>
{{-- Data administrace ze serveru. Prototyp je má staticky v galerie-data.js;
     tohle je přepíše skutečnými účty, úlohami, klíči a tarify dřív, než se
     obrazovka poprvé vykreslí. Když endpoint selže, zůstanou původní — prázdná
     administrace by vypadala jako rozbitá aplikace. --}}
<script>
(function () {
  /*
   * Data ze serveru se **nepřiřazují**, přimíchávají se v okamžiku čtení.
   *
   * Prostý zápis do `GalerieData.ADMIN` vydržel jen do chvíle, než runtime
   * prototypu načetl `galerie-data.js` znovu a přepsal ho zpátky na ukázková
   * data. Bylo to o to zákeřnější, že se to podle načasování někdy povedlo:
   * administrace pak jednou ukazovala skutečné účty a jindy vymyšlené.
   *
   * Vlastnost `ADMIN` je proto přístupová: prototyp si do ní může zapisovat, co
   * chce, a čtenář dostane jeho hodnotu doplněnou o to, co ví server.
   */
  var zeServeru = null;
  var uloziste = null;
  // Kolekce obsahu ze serveru (finance, knihovna, …). Klíč = jméno kolekce,
  // kterou prototyp kreslí; hodnota = skutečné řádky z databáze.
  var obsah = {};
  // Kolekce, které server dodává celé — u nich se smí smazat i to, co neposlal.
  var uplne = {};
  // Totéž pro úzké rozvržení: telefon kreslí knihovnu z vlastních, mnohem
  // menších kolekcí, které nejsou v `GalerieData`.
  var mobil = {};

  /*
   * Obsah do kolekcí telefonu.
   *
   * Volá se z obou stran, protože se nedá spolehnout na pořadí: data ze serveru
   * můžou dorazit dřív, než runtime prototypu dokument vůbec přeloží, i později.
   * Kolekce se proto — stejně jako u `GalerieData` — přepisují **na místě**.
   */
  function doMobilu() {
    var cil = window.GalerieMobil;
    if (! cil) return;

    Object.keys(mobil).forEach(function (klic) { navlec(cil, klic, mobil[klic], false); });
    if (window.GalerieObnovObrazovku) window.GalerieObnovObrazovku();
  }

  window.GalerieObsahMobil = doMobilu;

  function obal(data) {
    if (! data || data.__galerieObaleno) return;

    var vlastni = data.ADMIN;

    try {
      Object.defineProperty(data, '__galerieObaleno', { value: true });
      Object.defineProperty(data, 'ADMIN', {
        configurable: true,
        enumerable: true,
        get: function () { return zeServeru ? Object.assign({}, vlastni, zeServeru) : vlastni; },
        set: function (v) { vlastni = v; }
      });
      // Čísla postranního panelu musí přežít totéž: bez přístupové vlastnosti
      // by je nové `GalerieData` shodilo a panel by se vrátil k ukázkovým.
      Object.defineProperty(data, 'STORAGE', {
        configurable: true,
        enumerable: true,
        get: function () { return uloziste; },
        set: function (v) { uloziste = v; }
      });
    } catch (e) {}

    // A znovu vyměnit kolekce, které už ze serveru dorazily — runtime načítá
    // `galerie-data.js` znovu a s ním se vrátí i ukázková data.
    Object.keys(obsah).forEach(function (klic) { navlec(data, klic, obsah[klic], !!uplne[klic]); });
  }

  /*
   * Kolekce se přepisuje **na místě**, ne přiřazením.
   *
   * Dokument prototypu si při načtení rozebere všech 180 kolekcí do konstant
   * (`const { TX, BUD, ... } = window.GalerieData`). Pozdější `GalerieData.TX = …`
   * proto nikam nedojde — obrazovka drží původní pole. Když se ale obsah toho
   * pole vymění, konstanta ukazuje pořád na ně a při dalším překreslení se objeví
   * skutečná data.
   *
   * Skalární kolekce (číslo, řetězec) takhle vyměnit nejdou — u těch zůstává
   * hodnota z načtení a jsou proto vypsané v `docs/galerie-obsah.md`.
   */
  function navlec(data, klic, hodnota, cela) {
    if (! data) return false;

    var cil = data[klic];

    try {
      if (Array.isArray(cil) && Array.isArray(hodnota)) {
        cil.length = 0;
        Array.prototype.push.apply(cil, hodnota);
        return true;
      }

      if (cil && typeof cil === 'object' && hodnota && typeof hodnota === 'object') {
        /*
         * Klíče se **přepisují, nemažou** — pokud server neřekne, že kolekci
         * dodává celou.
         *
         * `FIN` má vedle účtů ještě `upcoming`, `alerts`, `rules` a `imports`.
         * Když se objekt vyprázdnil a naplnil jen tím, co server posílá, zbytek
         * zmizel a obrazovka spadla na `undefined.filter`. Co server nedodá,
         * zůstává ukázkové.
         *
         * `PERSONS` je opačný případ: seznam lidí přichází celý a nechat vedle
         * skutečných tváří ukázkovou Kláru znamená ukazovat dvojici někoho,
         * kdo neexistuje.
         */
        if (cela) {
          Object.keys(cil).forEach(function (k) {
            if (! Object.prototype.hasOwnProperty.call(hodnota, k)) delete cil[k];
          });
        }

        Object.keys(hodnota).forEach(function (k) { cil[k] = hodnota[k]; });
        return true;
      }

      // Kolekce, kterou dokument ještě nezná, jde přiřadit normálně.
      if (cil === undefined) { data[klic] = hodnota; return true; }
    } catch (e) {}

    return false;
  }

  // `galerie-data.js` přiřazuje celé `window.GalerieData`, takže se hlídá i ono —
  // jinak by nový objekt obal ztratil.
  var data = window.GalerieData;
  obal(data);
  try {
    Object.defineProperty(window, 'GalerieData', {
      configurable: true,
      get: function () { return data; },
      set: function (v) { data = v; obal(v); }
    });
  } catch (e) {}

  function hlavicky() {
    var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (window.GALERIE_API_TOKEN) h['Authorization'] = 'Bearer ' + window.GALERIE_API_TOKEN;
    return h;
  }

  function nacti() {
    /*
     * Dvě věci najednou: administrace a čísla postranního panelu.
     *
     * Panel je na každé obrazovce a jeho čísla vidí každý; administrace je jen
     * pro vlastníka a správce. Kdyby se čekalo na obojí, panel by se u hosta
     * nenačetl nikdy — proto se výsledky vyhodnocují zvlášť.
     */
    var admin = fetch('/api/admin', { headers: hlavicky(), credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (b) {
        if (! b || ! b.data) return false;
        zeServeru = b.data;
        return true;
      })
      // Když se to nepovede, zůstanou data prototypu. Prázdná administrace
      // by vypadala jako rozbitá aplikace.
      .catch(function () { return false; });

    var panel = fetch('/api/storage', { headers: hlavicky(), credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (b) {
        if (! b || ! b.data) return false;
        // Do proměnné, ne na objekt: `galerie-data.js` se načítá znovu a nový
        // objekt by čísla shodil.
        uloziste = b.data;
        return true;
      })
      .catch(function () { return false; });

    return Promise.all([admin, panel].concat(skupiny())).then(function (v) { return v[0] || v[1]; });
  }

  /*
   * Obsah obrazovek ze skutečné databáze, po skupinách.
   *
   * Po skupinách, ne jednou odpovědí: obrazovka financí nemá čekat, až se spočítá
   * kuchařka. Každá skupina se navlékne, jakmile dorazí — na pořadí nezáleží.
   */
  var SKUPINY = ['finance', 'knihovna', 'planovani', 'domacnost'];

  function skupiny() {
    return SKUPINY.map(function (jmeno) {
      return fetch('/api/data/' + jmeno, { headers: hlavicky(), credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (b) {
          if (! b || ! b.data) return false;

          var neslo = [];

          (b.uplne || []).forEach(function (klic) { uplne[klic] = true; });

          Object.keys(b.data).forEach(function (klic) {
            // Úzké rozvržení má vlastní, mnohem menší tvar týchž fotek a drží
            // si ho stranou od `GalerieData`.
            if (klic === 'MOBIL') { Object.assign(mobil, b.data[klic]); doMobilu(); return; }

            obsah[klic] = b.data[klic];
            if (! navlec(window.GalerieData, klic, b.data[klic], !!uplne[klic])) neslo.push(klic);
          });

          // Aplikace už běží; překreslit, ať se data objeví bez čekání na klik.
          if (window.GalerieObnovObrazovku) window.GalerieObnovObrazovku();

          if (neslo.length) console.info('Galerie: skalární kolekce se projeví až po obnovení stránky —', neslo.join(', '));

          return true;
        })
        .catch(function () { return false; });
    });
  }

  window.GalerieAdminObnov = nacti;

  /*
   * Šťouchnutí k překreslení.
   *
   * Vyměněná kolekce se sama neprojeví — aplikace překresluje na změnu stavu.
   * Šířku okna si drží ve stavu, takže událost `resize` je nejlevnější způsob,
   * jak ji požádat o překreslení, aniž bych sahal na její komponentu. Bez toho
   * by obrazovka financí ukazovala ukázková data, dokud na ni někdo neklikne.
   */
  window.GalerieObnovObrazovku = function () {
    try { window.dispatchEvent(new Event('resize')); } catch (e) {}
  };

  /*
   * Administrace si po každém zásahu bere odpověď serveru sama (galerie-admin.js).
   * Musí ji podat sem, ne psát rovnou do `GalerieData.ADMIN` — getter výš totiž
   * míchá `zeServeru` až nakonec, takže by čerstvá data hned přebila ta z načtení
   * stránky a obrazovka by po pozastavení úlohy ukazovala, že běží dál.
   */
  window.GalerieAdminZeServeru = function (data) { if (data) zeServeru = data; };

  /*
   * Po zásahu v administraci si vyžádat skutečnost.
   *
   * Prototyp posílá administraci jako změnu stavu; server ji provede a v odpovědi
   * vrátí, jak to dopadlo. Klient si ale odpověď na `PATCH` jen uloží a nikomu
   * o ní neřekne (`notify()` volá až `load()`), takže by obrazovka do dalšího
   * dotazu ukazovala, co si přál uživatel, ne co server udělal.
   */
  var cekaObnova = null;

  function hlidejSpravu(api) {
    if (! api || api.__galerieHlidano) return;
    api.__galerieHlidano = true;

    var puvodni = api.save.bind(api);

    api.save = function (patch) {
      var vysledek = puvodni(patch);

      if (patch && Object.keys(patch).some(function (k) { return k.indexOf('adm') === 0; })) {
        clearTimeout(cekaObnova);
        cekaObnova = setTimeout(function () { api.load(); nacti(); }, 1200);
      }

      return vysledek;
    };
  }

  // Runtime prototypu své skripty načítá znovu, takže `GalerieApi` může být
  // v průběhu nahrazen novým objektem. Hlídá se proto samotná vlastnost —
  // s jednorázovým čekáním by se obal po prvním překreslení tiše ztratil.
  var api = window.GalerieApi;
  hlidejSpravu(api);
  try {
    Object.defineProperty(window, 'GalerieApi', {
      configurable: true,
      get: function () { return api; },
      set: function (v) { api = v; hlidejSpravu(v); }
    });
  } catch (e) {}

  var hotovo = false;
  var bezi = false;
  var pokusu = 0;

  function zkus() {
    if (hotovo || bezi || pokusu > 12) return;
    bezi = true;
    pokusu++;
    nacti().then(function (ok) { bezi = false; hotovo = ok; });
  }

  // Napoprvé hned (sezení může být přihlášené cookie), pak už jen když se objeví
  // token — kdo sedí na zámku, ten se serveru neptá vůbec.
  document.addEventListener('DOMContentLoaded', zkus);

  var token = window.GALERIE_API_TOKEN || null;
  try {
    Object.defineProperty(window, 'GALERIE_API_TOKEN', {
      configurable: true,
      get: function () { return token; },
      set: function (v) { token = v; if (v) { hotovo = false; pokusu = 0; setTimeout(zkus, 0); } }
    });
  } catch (e) {}
})();
</script>
