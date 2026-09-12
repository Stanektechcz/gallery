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
{{-- `image-slot.js` se tu předstahoval, dokud ho dokument načítal. Komponentu
     jsme odstranili (kreslila přes fotky svůj popisek a nabízela nahrát jinou),
     ale nápověda zůstala: prohlížeč stahoval sto kilobajtů, které nikdo
     nepoužil, a psal o tom do konzole. --}}
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

  // Jen ano/ne: jestli už je do čeho se přihlásit. Podle toho zmizí „První
  // spuštění" i před přihlášením — data o dvojici chodí až po něm.
  window.GALERIE_UCTY_EXISTUJI = @json((bool) ($uctyExistuji ?? false));

  // Service worker drží skořápku offline a doručuje zápisy, které vznikly bez
  // signálu. Dosah „/" je podmínka, ne volba: ve scope /galerie/ by neviděl
  // /api/ a fronta zápisů by nefungovala.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (reg) {
        if (! reg) return;

        /*
         * Nová verze se převezme sama.
         *
         * Bez tohohle drží stránku ten worker, který ji načetl, dokud se
         * nezavřou všechny karty aplikace — a to se u aplikace, kterou má
         * dvojice pořád otevřenou na druhém monitoru, nestane celé dny. Po
         * nasazení pak jeden z nich viděl starou verzi a druhý novou.
         *
         * Ptát se každou hodinu stačí: dvojice nasazuje jednou za čas, ne
         * každou minutu.
         */
        setInterval(function () { reg.update().catch(function () {}); }, 3600000);

        /*
         * Načíst znovu, ale jen když šlo o **výměnu**.
         *
         * Při první návštěvě stránku nikdo neřídí a nový worker ji převezme
         * hned po instalaci — načítat kvůli tomu znovu by znamenalo, že se
         * aplikace při každém prvním otevření sama restartuje.
         */
        var rizena = !! navigator.serviceWorker.controller;
        var prebiral = false;

        navigator.serviceWorker.addEventListener('controllerchange', function () {
          // Jednou. Bez pojistky by se stránka po převzetí načítala dokola.
          if (! rizena || prebiral) return;
          prebiral = true;
          window.location.reload();
        });
      }).catch(function () {});
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

  /*
   * Nádoby kolekcí zůstávají tytéž napříč načteními `galerie-data.js`.
   *
   * Runtime prototypu ten soubor spouští opakovaně a pokaždé přiřadí **nový**
   * objekt s **novými** poli. Dokument si přitom všech sto devadesát kolekcí
   * rozebral do konstant jedním jediným `const { BUS, TX, … } = GalerieData`.
   * Když po tom rozebrání přišlo další načtení, ukazovaly konstanty na pole
   * předchozí generace a `navlec` doplňoval data do té nové — tedy do polí,
   * na která se už nikdo nedíval.
   *
   * Bylo to vidět jen na datech, která dorazí pozdě: po obnovení stránky
   * obrazovka ukazovala skutečné řádky, ale zápis udělaný za běhu se objevil
   * až po dalším obnovení. Vypadalo to jako pomalý server.
   *
   * Obsah nového načtení se proto přelije do **staré** nádoby a ta se vrátí
   * do nového objektu. Všechny generace pak sdílejí táž pole a konstanta
   * z kterékoli z nich vidí každou pozdější změnu.
   */
  function sjednotNadoby(stara, nova) {
    if (! stara || ! nova || stara === nova) return;

    Object.keys(nova).forEach(function (klic) {
      // `ADMIN` a `STORAGE` jsou přístupové vlastnosti, které `obal` zakládá
      // pro každý objekt zvlášť; přepsat je hodnotou by getter zahodilo.
      if (klic === 'ADMIN' || klic === 'STORAGE') return;

      var puvodni = stara[klic];
      var nove = nova[klic];

      if (! puvodni || typeof puvodni !== 'object') return;
      if (! nove || typeof nove !== 'object') return;
      if (Array.isArray(puvodni) !== Array.isArray(nove)) return;

      if (! navlec(stara, klic, nove, true)) return;

      try { nova[klic] = puvodni; } catch (e) {}
    });
  }

  // `galerie-data.js` přiřazuje celé `window.GalerieData`, takže se hlídá i ono —
  // jinak by nový objekt obal ztratil.
  var data = window.GalerieData;
  obal(data);
  try {
    Object.defineProperty(window, 'GalerieData', {
      configurable: true,
      get: function () { return data; },
      set: function (v) {
        // Nejdřív nádoby, pak obal: `obal` znovu navlékne, co už dorazilo ze
        // serveru, takže ukázková data z nového načtení nic nepřebijí.
        sjednotNadoby(data, v);
        data = v;
        obal(v);
      }
    });
  } catch (e) {}

  /*
   * Totéž pro mechanismy — jinak `/api/mechanisms` mluví do prázdna.
   *
   * `galerie-api.js` si data ze serveru vezme a přiřadí je jako **nový objekt**
   * (`window.GalerieMech = Object.assign({}, staré, nové)`). Dokument si ale
   * všech dvaadvacet definic rozebral do konstant hned při načtení
   * (`const { DC_GRPS, … } = window.GalerieMech`), takže ukazují pořád na ten
   * původní objekt a serverová verze se tiše zahodí. Endpoint přitom odpovídá
   * a klient ho volá — celé to jen nikam nedojde.
   *
   * Přístupová vlastnost proto přiřazení zachytí a klíče **přimíchá na místo**,
   * stejně jako u obsahu.
   */
  try {
    // Vlastnost se zakládá **napřed**: `galerie-mechanismy.js` se načítá až
    // za touhle hlavičkou, takže první přiřazení je jeho soubor — a ten je
    // základ, do kterého se všechno pozdější přimíchává.
    var mech = window.GalerieMech || null;

    Object.defineProperty(window, 'GalerieMech', {
      configurable: true,
      enumerable: true,
      get: function () { return mech; },
      set: function (v) {
        if (! v || v === mech) return;
        if (! mech) { mech = v; return; }

        Object.keys(v).forEach(function (k) { navlec(mech, k, v[k], false); });
      }
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
  var SKUPINY = ['finance', 'knihovna', 'planovani', 'domacnost', 'cesty', 'vztah', 'zdravi', 'sdileni',
    'zpravy', 'kucharka', 'darky', 'denik', 'pravidla', 'rozbory', 'uklid', 'system', 'klid', 'pribeh',
    'mechanismy', 'rozhodovani', 'tyden', 'dnes'];

  /*
   * Tři dávky místo dvaceti požadavků. První nese to, co je vidět hned
   * (knihovna, zámek, plán, zprávy), takže obrazovka nečeká na kuchařku.
   */
  var DAVKY = [
    // `dnes` je úvodní obrazovka — ta se ukáže jako první.
    ['system', 'knihovna', 'planovani', 'zpravy', 'dnes'],
    ['finance', 'domacnost', 'cesty', 'vztah', 'zdravi', 'sdileni', 'kucharka'],
    ['darky', 'denik', 'pravidla', 'rozbory', 'uklid', 'klid', 'pribeh', 'mechanismy', 'rozhodovani', 'tyden']
  ];

  function skupiny() {
    // Skupina, kterou někdo přidá do SKUPINY a zapomene sem, se nesmí ztratit.
    var vDavce = [].concat.apply([], DAVKY);
    var zbytek = SKUPINY.filter(function (j) { return vDavce.indexOf(j) < 0; });
    return DAVKY.concat(zbytek.length ? [zbytek] : []).map(nactiDavku);
  }

  /*
   * Kolik skupin obsahu se právě stahuje.
   *
   * Mřížka knihovny pod sebou kreslila točící se kolečko s textem „Načítám
   * další vzpomínky…" a osm zástupných dlaždic — **pořád**, bez ohledu na to,
   * jestli se něco načítá. Kdo měl v oblíbených jednu fotku, viděl ji a pod ní
   * nekonečné načítání něčeho, co nikdy nedorazilo. Tohle číslo dává obrazovce
   * možnost se zeptat.
   */
  window.GalerieNacita = 0;

  /*
   * `cerstve` obchází třicetivteřinovou paměť odpovědi.
   *
   * Skupiny se posílají s `max-age=30`, aby se při překreslování nestahovaly
   * pořád dokola. Jenže obnovení po zápisu (nahrání fotek, zpráva do chatu,
   * nový kód zámku) tak dostalo odpověď z doby před zápisem — a nahrané fotky
   * se v knihovně ukázaly až za půl minuty nebo po obnovení stránky.
   */
  function nactiSkupinu(jmeno, cerstve) {
    window.GalerieNacita++;
    return fetch('/api/data/' + jmeno, { headers: hlavicky(), credentials: 'same-origin', cache: cerstve ? 'no-cache' : 'default' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(navlecSkupinu)
      .catch(function () { return false; })
      .then(hotovaSkupina);
  }

  function hotovaSkupina(ok) {
    window.GalerieNacita = Math.max(0, window.GalerieNacita - 1);
    // Až doběhne poslední skupina, obrazovka schová načítání sama.
    if (window.GalerieNacita === 0 && window.GalerieObnovObrazovku) window.GalerieObnovObrazovku();
    return ok;
  }

  /*
   * Víc skupin jedním požadavkem (`/api/data?skupiny=a,b`).
   *
   * Každá skupina zvlášť znamenala dvacet požadavků na jedno načtení stránky
   * — a firewall serveru blokuje adresu po sto dvaceti za minutu. Dvojice sedí
   * doma za jednou adresou, takže pár obnovení od obou stačilo, aby se
   * aplikace přestala načítat. Když dávkový požadavek selže (starší server),
   * skupiny se stáhnou po jedné jako dřív.
   */
  function nactiDavku(jmena) {
    window.GalerieNacita++;
    return fetch('/api/data?skupiny=' + jmena.join(','), { headers: hlavicky(), credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 404 || r.status === 405) throw new Error('bez dávky');
        return r.ok ? r.json() : null;
      })
      .then(function (b) {
        if (! b || ! b.skupiny) return false;
        return jmena.map(function (jmeno) { return b.skupiny[jmeno] ? navlecSkupinu(b.skupiny[jmeno]) : false; })
          .some(function (ok) { return ok; });
      })
      .catch(function (e) {
        if (e && e.message === 'bez dávky') return Promise.all(jmena.map(function (j) { return nactiSkupinu(j); }));
        return false;
      })
      .then(hotovaSkupina);
  }

  function navlecSkupinu(b) {
          if (! b || ! b.data) return false;

          var neslo = [];

          (b.uplne || []).forEach(function (klic) { uplne[klic] = true; });

          Object.keys(b.data).forEach(function (klic) {
            // Úzké rozvržení má vlastní, mnohem menší tvar týchž fotek a drží
            // si ho stranou od `GalerieData`.
            if (klic === 'MOBIL') { Object.assign(mobil, b.data[klic]); doMobilu(); return; }

            /*
             * Kolekci, na které se skládá víc skupin, si **poskládáme**.
             *
             * `obsah` je plochá mapa a slouží k tomu, aby se data ze serveru
             * dala navléct znovu, až runtime prototypu načte `galerie-data.js`
             * podruhé. Prostým přiřazením si ale skupiny přepisovaly navzájem:
             * `AL` posílá knihovna (`tagMerge`) i systém (`inbox`, `vault`),
             * takže si tu zůstala jen ta, která dorazila později — a při
             * dalším průchodu se zbytek vrátil na ukázková data.
             *
             * Poznat se to dalo jen na časování: podle toho, která skupina
             * doběhla dřív, byla obrazovka jednou skutečná a jindy vymyšlená.
             */
            var drive = obsah[klic];
            var slozeno = b.data[klic];

            if (drive && slozeno && ! Array.isArray(drive) && ! Array.isArray(slozeno)
                && typeof drive === 'object' && typeof slozeno === 'object') {
              slozeno = Object.assign({}, drive, slozeno);
            }

            obsah[klic] = slozeno;
            if (! navlec(window.GalerieData, klic, b.data[klic], !!uplne[klic])) neslo.push(klic);
          });

          // Aplikace už běží; překreslit, ať se data objeví bez čekání na klik.
          if (window.GalerieObnovObrazovku) window.GalerieObnovObrazovku();

          if (neslo.length) console.info('Galerie: skalární kolekce se projeví až po obnovení stránky —', neslo.join(', '));

          return true;
  }

  /*
   * Znovu načíst jednu skupinu obsahu.
   *
   * Po zápisu, který nejde přes `/api/state` — nahraná hlasovka, založený
   * odkaz —, potřebuje obrazovka svá data znovu. Načítat všech dvacet skupin
   * kvůli jedné je zbytečné.
   */
  window.GalerieObnovit = function (jmeno) {
    if (SKUPINY.indexOf(jmeno) < 0) return Promise.resolve(false);
    return nactiSkupinu(jmeno, true);
  };

  window.GalerieAdminObnov = nacti;

  /*
   * Kolekce z odpovědi na zápis, rovnou do obrazovky.
   *
   * Formuláře rozhodování posílají záznam vlastní cestou (`/api/zaznamy/...`)
   * a v odpovědi dostanou celou skupinu znovu. Kdyby se čekalo na další
   * `/api/data/rozhodovani`, nová obava by se objevila až za půl minuty —
   * odpověď má `Cache-Control: max-age=30` a prohlížeč by ji vzal z paměti.
   *
   * Klíč se zároveň ukládá do `obsah`, aby přežil další načtení
   * `galerie-data.js`; bez toho by se vrátila ukázková data.
   */
  window.GalerieObsahNavlec = function (mapa, prazdne) {
    if (! mapa && ! prazdne) return false;

    Object.keys(mapa || {}).forEach(function (klic) {
      obsah[klic] = mapa[klic];
      navlec(window.GalerieData, klic, mapa[klic], !!uplne[klic]);
    });

    /*
     * Kolekce, které po akci **zbyly prázdné**.
     *
     * Poskytovatel prázdné neposílá — při načtení stránky správně, protože
     * prázdná obrazovka a rozbitá aplikace vypadají stejně. U odpovědi na akci
     * je to ale naopak: když se vrátí poslední položka z koše, mlčení znamená
     * „nezměnilo se nic" a na obrazovce zůstane řádek, který už neexistuje.
     */
    (prazdne || []).forEach(function (klic) {
      obsah[klic] = [];
      navlec(window.GalerieData, klic, [], true);
    });

    if (window.GalerieObnovObrazovku) window.GalerieObnovObrazovku();

    return true;
  };

  /*
   * Šťouchnutí k překreslení.
   *
   * Vyměněná kolekce se sama neprojeví — aplikace překresluje na změnu stavu.
   *
   * Událost `resize` na to nestačí, i když to tak dlouho vypadalo. Obsluha
   * v prototypu je `if (w !== this.state.vw) this.setState(...)`, takže když
   * se šířka nezměnila — a ta se při dotažení dat nemění nikdy —, neudělá se
   * nic. Data pak na obrazovce byla až po prvním kliknutí, které překreslilo
   * aplikaci kvůli něčemu jinému. Vypadalo to, že to funguje, protože se
   * na obrazovku obvykle přišlo kliknutím.
   *
   * Spolehlivá cesta vede přes společnou datovou vrstvu: prototyp je na ni
   * přihlášený a v obsluze volá `setState` bez podmínky. Synthetická událost
   * `storage` ji požádá o rozeslání téhož obsahu — hodnoty se nemění, jen se
   * překreslí.
   */
  window.GalerieObnovObrazovku = function () {
    try { window.dispatchEvent(new Event('resize')); } catch (e) {}

    try {
      window.dispatchEvent(new StorageEvent('storage', { key: 'galerie.shared.v2' }));
    } catch (e) {
      // Starší prohlížeč `StorageEvent` konstruktor nemá; zůstane `resize`.
    }
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
