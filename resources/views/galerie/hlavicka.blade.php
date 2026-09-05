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
    } catch (e) {}
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

  function nacti() {
    var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (window.GALERIE_API_TOKEN) h['Authorization'] = 'Bearer ' + window.GALERIE_API_TOKEN;

    return fetch('/api/admin', { headers: h, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (b) {
        if (! b || ! b.data) return false;
        zeServeru = b.data;
        return true;
      })
      // Když se to nepovede, zůstanou data prototypu. Prázdná administrace
      // by vypadala jako rozbitá aplikace.
      .catch(function () { return false; });
  }

  window.GalerieAdminObnov = nacti;

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
