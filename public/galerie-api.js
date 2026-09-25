// ——— Datová vrstva Galerie ———
// Jeden klient pro celý prototyp. Dnes ukládá do localStorage, po nasazení
// Laravelu stačí nastavit window.GALERIE_API_BASE = '/api' a nic dalšího se
// v aplikaci nemění — kontrakt je stejný.
//
// KONTRAKT (viz laravel/README.md):
//   GET    {base}/state            → { data: {…}, updated_at: "2026-09-04T10:00:00Z", rev: 12 }
//   PATCH  {base}/state            ← { data: {…částečný patch…}, rev: 12 }
//                                  → { data: {…celý stav…}, updated_at, rev: 13 }
//   DELETE {base}/state            → { data: {}, rev: 0 }
//   GET    {base}/state/stream     → SSE (nepovinné; jinak polling)
// Hlavičky: Accept: application/json, X-CSRF-TOKEN z <meta name="csrf-token">,
// cookie session (Sanctum) nebo Bearer token z window.GALERIE_API_TOKEN.
//
// Zápisy jsou vždy PATCH s částečným objektem — server drží celý stav a slučuje
// po klíčích. Díky tomu je jedno, kolik obrazovek zapisuje současně, a přírůstek
// jde po drátě malý.
(function () {
  /*
   * Podruhé se tenhle soubor nespouští.
   *
   * Runtime prototypu své skripty načítá znovu — a každý průchod zakládal
   * **druhou datovou vrstvu**: vlastní frontu, vlastní číslo revize, vlastní
   * odběratele. Obojí pak psalo do `/api/state` na střídačku a to pozadu
   * dostávalo od serveru 409: jeho zápis se zahodil a nikde po tom nezůstala
   * stopa. Zapsaná věc prostě zmizela.
   */
  if (window.GalerieApi && window.GalerieApi.__vrstva) return;

  var LS = 'galerie.state.v1';
  var CH = 'galerie.state';

  var base = (typeof window !== 'undefined' && window.GALERIE_API_BASE) || null;
  var mode = base ? 'http' : 'local';
  // Token z předchozího přihlášení — aplikace po restartu nezapomíná, kdo je přihlášený.
  if (typeof window !== 'undefined' && !window.GALERIE_API_TOKEN) {
    try { window.GALERIE_API_TOKEN = localStorage.getItem('galerie.token') || null; } catch (e) {}
  }

  var data = {};
  var rev = 0;
  /*
   * Odběratelé žijí na `window`, ne v tomhle uzávěru.
   *
   * Runtime prototypu své skripty načítá znovu, takže tenhle soubor může
   * proběhnout dvakrát — a podruhé vznikne nový objekt s prázdným seznamem.
   * Aplikace se přitom přihlásila k tomu prvnímu: zápisy ven chodily dál
   * (`window.GalerieApi` se čte pokaždé znovu), ale **odpověď serveru se
   * do obrazovky nikdy nevrátila**. U věcí, kde identifikátor přiděluje
   * databáze, si ho klient nikdy nepřevzal a poslal řádek podruhé jako nový.
   */
  var subs = (window.__galerieSubs = window.__galerieSubs || []);
  var pending = {};      // patch, který čeká na odeslání
  var pendingUcet = null; // kdo ho napsal — viz „Čí je čekající zápis"
  var timer = null;
  var inflight = false;
  var lastSync = null;
  var lastError = null;
  var queuedBySw = false;
  var bc = null;

  /*
   * Čekající zápis přežije zavření okna.
   *
   * Fronta (`pending`) žila jen v paměti. Kdo zapsal něco bez signálu
   * a stránku pak obnovil — telefon v tunelu, zavřená karta —, o zápis
   * přišel potichu: lokální kopie ho měla, ale `load()` ji přepsala tím,
   * co má server, a nikdo se nic nedozvěděl.
   *
   * Fronta se proto ukládá vedle kopie a po spuštění se zase odešle.
   * Starší než týden se zahazuje: přepsat po týdnech to, co mezitím napsal
   * ten druhý, by bylo horší než ztratit poznámku, na kterou se zapomnělo.
   */
  var TYDEN = 7 * 24 * 3600 * 1000;

  function readLocal() {
    try {
      var raw = JSON.parse(localStorage.getItem(LS));
      if (raw && typeof raw === 'object') {
        rev = raw.rev || 0;
        var cekal = raw.pending && typeof raw.pending === 'object' ? raw.pending : null;
        var stari = raw.pendingAt ? Date.now() - raw.pendingAt : 0;
        if (cekal && Object.keys(cekal).length && stari < TYDEN) { pending = cekal; pendingUcet = raw.pendingUcet || null; }
        return raw.data || {};
      }
    } catch (e) {}
    return {};
  }
  /*
   * Klíče, které server v odpovědi posílá, ale neukládá.
   *
   * Jsou to pravidla, sezónní fondy a podobné věci, které mají vlastní
   * tabulku: v odpovědi jsou proto, aby obrazovka po kliknutí neblikla, ale
   * do lokální kopie nepatří. Uložené by se při dalším spuštění postavily
   * před skutečná data ze serveru — přesně to, čemu se ta vrstva vyhýbá.
   *
   * Jednou označený klíč zůstává dočasný po celou relaci: další odpověď už
   * ho zmiňovat nemusí.
   */
  var docasne = {};

  function oznacDocasne(b) {
    if (! b || ! b.docasne) return;
    (b.docasne || []).forEach(function (k) { docasne[k] = true; });
  }

  function writeLocal() {
    try {
      var ulozit = data;

      if (Object.keys(docasne).length) {
        ulozit = {};
        Object.keys(data).forEach(function (k) { if (! docasne[k]) ulozit[k] = data[k]; });
      }

      var ceka = Object.keys(pending).length > 0;

      localStorage.setItem(LS, JSON.stringify({
        data: ulozit, rev: rev, updated_at: new Date().toISOString(),
        pending: ceka ? pending : undefined,
        pendingAt: ceka ? Date.now() : undefined,
        pendingUcet: ceka && pendingUcet ? pendingUcet : undefined
      }));
    } catch (e) {}
  }
  data = readLocal();

  /*
   * Čí je čekající zápis.
   *
   * Zápis bez signálu (tady v `pending` i ve frontě workera) odcházel s tím,
   * kdo byl přihlášený **při odeslání**. Na sdíleném zařízení tak to, co
   * napsala ona, po jejím odhlášení a jeho přihlášení skončilo v jeho galerii
   * a pod jeho jménem — i soukromé klíče.
   *
   * Každý zápis proto nese autora (`ucet`) a server jiný účet nepustí.
   * Kdo je přihlášený, ví s jistotou jen server (`ucet` v odpovědi na čtení
   * stavu, `user` při přihlášení heslem); `kdo` platí jen pro token, ke
   * kterému ho server potvrdil. Otisk prstu mění token mimo `signIn` — pak
   * se čekající zápis před odesláním nejdřív zeptá, komu token patří.
   */
  var kdo = null;
  try { kdo = localStorage.getItem('galerie.ucet') || null; } catch (e) {}
  if (!kdo && !window.GALERIE_API_TOKEN && window.GALERIE_USER && window.GALERIE_USER.id != null) kdo = String(window.GALERIE_USER.id);
  var kdoToken = window.GALERIE_API_TOKEN || null;
  var overenyToken; // na který token se karta už ptala — podruhé rozhodne až server

  function tokenBezUctu() { return (window.GALERIE_API_TOKEN || null) !== kdoToken; }
  function kdoTed() { return tokenBezUctu() ? null : kdo; }
  // Jednou za token; bez signálu se zápis pošle s autorem a cizí odmítne server.
  function overUcet() {
    var t = window.GALERIE_API_TOKEN || null;
    if (overenyToken === t || !window.GalerieApi) return false;
    overenyToken = t;
    window.GalerieApi.load();
    return true;
  }

  // Server řekl, komu patří aktuální token. Cizí čekající zápis se zahodí —
  // neodejde pod nikým jiným; zápis bez autora (vznikl, když to karta nevěděla) se přivlastní.
  /*
   * Přihlášení jiným účtem nesmí zdědit kopii toho předchozího.
   *
   * Přihlášení na tomtéž zařízení jiným účtem (dvojice si ho půjčuje, „Jiný
   * účet" na zamčené obrazovce) nechávalo `data`/`rev` v paměti i kopii
   * v localStorage a v cache workeru po tom prvním — offline pak dostal
   * druhý účet jeho `/api/state` i `/api/data/*`. Známý předchozí účet, který
   * se liší od nově přihlášeného, nebo neznámý předchozí, ale s už
   * existující kopií (typicky otisk prstu — mění token mimo `signIn`), se
   * bere jako přepnutí účtu: kopie i mezipaměť se zahodí a paměť se vyprázdní
   * dřív, než volající stihne načíst čerstvá data a poslat je odběratelům.
   * Týž účet se nedotkne ničeho — ani rozepsaného zápisu nového účtu.
   */
  function prevezmiUcet(id) {
    if (id === undefined || id === null || id === '') return false;
    var novy = String(id);
    var jinyUcet = kdo ? kdo !== novy : Object.keys(data).length > 0;
    if (jinyUcet) {
      zahodKopieDat();
      data = {}; rev = 0; lastError = null;
    }
    if (pendingUcet && pendingUcet !== novy) {
      pending = {}; pendingUcet = null; poJednom = false; odmitnutyToken = undefined;
    }
    if (!pendingUcet && Object.keys(pending).length) pendingUcet = novy;
    kdo = novy;
    kdoToken = window.GALERIE_API_TOKEN || null;
    try { localStorage.setItem('galerie.ucet', novy); } catch (e) {}
    writeLocal();
    return jinyUcet;
  }
  function teloZapisu(patch, ucet) {
    var t = { data: patch, rev: rev };
    if (ucet) t.ucet = ucet;
    return JSON.stringify(t);
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : null;
  }
  function headers() {
    var h = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var t = csrf();
    if (t) h['X-CSRF-TOKEN'] = t;
    if (window.GALERIE_API_TOKEN) h['Authorization'] = 'Bearer ' + window.GALERIE_API_TOKEN;
    return h;
  }

  /*
   * Strop na nahrávací požadavky: nejvýš sto za minutu.
   *
   * Firewall serveru má pravidlo „víc než 120 požadavků za 60 vteřin" a adresu,
   * která ho překročí, zablokuje — nginx pak na všechno odpoví zavřeným
   * spojením a aplikace nejde načíst vůbec. Dvě stě malých fotek po třech
   * souběžně se přes ten práh dostane legitimně. Radši o chvíli pomalejší
   * nahrávání než zablokovaný telefon.
   */
  var nahravaciCasy = [];
  function pockejNaSlot() {
    var ted = Date.now();
    nahravaciCasy = nahravaciCasy.filter(function (t) { return ted - t < 60000; });
    if (nahravaciCasy.length < 100) {
      nahravaciCasy.push(ted);
      return Promise.resolve();
    }
    var za = 60000 - (ted - nahravaciCasy[0]) + 50;
    return new Promise(function (hotovo) { setTimeout(hotovo, za); }).then(pockejNaSlot);
  }

  function notify() {
    var snap = snapshot();
    subs.forEach(function (fn) { try { fn(snap); } catch (e) {} });
  }
  function snapshot() {
    var o = {};
    Object.keys(data).forEach(function (k) { o[k] = data[k]; });
    return o;
  }

  // Sloučení patche do lokální kopie. Hodnoty se nahrazují celé — objekty i pole
  // v tomhle prototypu vždy vznikají nové, takže hloubkové slučování by jen
  // schovávalo mazání klíčů.
  /*
   * Rozdíl pro zápis do tabulek — co prohlížeč v seznamu změnil, odebral nebo
   * vrátil. Jde jen na server: do stavu (a do lokální kopie) nepatří, jinak by
   * se po obnovení stránky vracel jako stav a rostl.
   */
  var ROZDIL = ['__odebrane', '__zmenene', 'evZmenene', 'evZrusene', 'evObnovene', 'xBoardZmenene', 'xBoardZrusene', 'vaultVyjmout'];

  function merge(patch) {
    var changed = false;
    Object.keys(patch || {}).forEach(function (k) {
      if (ROZDIL.indexOf(k) >= 0) return;
      if (JSON.stringify(data[k]) === JSON.stringify(patch[k])) return;
      data[k] = patch[k];
      changed = true;
    });
    return changed;
  }

  /*
   * Střet o tutéž věc.
   *
   * Server zapíše všechno, o co se ti dva nepřetahují; klíč, který mezitím
   * změnil ten druhý, se zahodí — a tohle je jediné místo, kde se to dá
   * říct. Bez toho se obrazovka jen sama vrátila o krok zpět a mlčela.
   *
   * Aplikace si tu událost odchytí (`window.addEventListener('galerie-stret', …)`)
   * a ukáže hlášku; datová vrstva o hláškách nic neví.
   */
  function ohlasStret(klice) {
    if (! klice || ! klice.length) return;
    ohlas('galerie-stret', { klice: klice.slice() });
  }
  function ohlas(nazev, detail) {
    try {
      window.dispatchEvent(new CustomEvent(nazev, { detail: detail }));
    } catch (e) {}
  }

  /*
   * Odmítnutý zápis se neopakuje dokola.
   *
   * Každá chyba vracela patch do fronty a za čtyři vteřiny ho poslala znovu —
   * i když ho server odmítl natrvalo. Prošlé přihlášení (401), odebraný
   * přístup (403) nebo příliš velký zápis (413) tak z otevřené karty dělaly
   * smyčku patnácti požadavků za minutu (firewall serveru už jednou adresu
   * dvojice zablokoval) a čekající zápis zastavil i dotazy na změny toho
   * druhého — hlavička se neptá, dokud něco čeká na odeslání.
   *
   *  - 401/403: token se zahodí, aplikace ukáže přihlášení (`galerie-odhlaseno`)
   *    a zápisy čekají na nové přihlášení;
   *  - 400/413/422: patch s víc klíči se pošle po jednom; klíč, který server
   *    nevezme ani sám, se zahodí a aplikace to řekne (`galerie-odmitnuto`);
   *  - výpadek a chyby serveru: další pokus s prodlužující se prodlevou
   *    (4 s, 8 s, … nejvýš 2 minuty).
   */
  var prodleva = 0;
  var poJednom = false;
  var odmitnutyToken; // `undefined` = zápisy nečekají na přihlášení
  var ROZDIL_KE_KLICI = { evZmenene: 'evList', evZrusene: 'evList', evObnovene: 'evList', xBoardZmenene: 'xBoard', xBoardZrusene: 'xBoard', vaultVyjmout: 'vaultAdded' };

  // Jeden klíč z fronty i s rozdílem, který k němu patří (`__odebrane['xRows.films']` k `xRows`).
  function vyjmiKlic(k) {
    var p = {};
    p[k] = pending[k];
    delete pending[k];
    ['__odebrane', '__zmenene'].forEach(function (r) {
      var mapa = pending[r];
      if (!mapa || typeof mapa !== 'object') return;
      var zbytek = Object.assign({}, mapa);
      Object.keys(mapa).forEach(function (s) {
        if (s !== k && s.indexOf(k + '.') !== 0) return;
        p[r] = p[r] || {};
        p[r][s] = mapa[s];
        delete zbytek[s];
      });
      if (Object.keys(zbytek).length) pending[r] = zbytek; else delete pending[r];
    });
    Object.keys(ROZDIL_KE_KLICI).forEach(function (r) {
      if (ROZDIL_KE_KLICI[r] === k && r in pending) { p[r] = pending[r]; delete pending[r]; }
    });
    return p;
  }
  // Neodeslaný patch zpátky do fronty; novější zápis téhož klíče má přednost.
  // Jen do fronty téhož autora — mezitím se mohl přihlásit někdo jiný.
  function vratDoFronty(patch, zapsal) {
    var ted = kdoTed();
    if (zapsal && ted && zapsal !== ted) return;
    if (!Object.keys(pending).length) pendingUcet = zapsal || pendingUcet;
    else if (zapsal && pendingUcet && zapsal !== pendingUcet) return;
    Object.keys(patch).forEach(function (k) {
      if ((k === '__odebrane' || k === '__zmenene' || k === 'xRows') && pending[k] && typeof pending[k] === 'object' && patch[k] && typeof patch[k] === 'object') {
        pending[k] = Object.assign({}, patch[k], pending[k]);
        return;
      }
      if (!(k in pending)) pending[k] = patch[k];
    });
    writeLocal();
  }
  function odhlaseno(stav, zprava) {
    window.GALERIE_API_TOKEN = null;
    odmitnutyToken = null;
    kdo = null; kdoToken = null;
    try { localStorage.removeItem('galerie.token'); localStorage.removeItem('galerie.ucet'); } catch (e) {}
    zahodKopieDat();
    ohlas('galerie-odhlaseno', { status: stav, zprava: zprava || '' });
    notify();
  }
  /*
   * Kopie dat dvojice v prohlížeči.
   *
   * Po odhlášení nebo odvolání zařízení („odhlásit ostatní zařízení" kvůli
   * ztracenému telefonu) zůstávala: stav v localStorage a odpovědi API
   * v paměti workera, které se bez signálu podávaly dál. Rozepsané změny
   * zůstávají v paměti karty a odejdou, jen když se přihlásí týž účet
   * (`prevezmiUcet`); výslovné odhlášení je nejdřív doručí, pak zahodí
   * i frontu workera (`signOut`).
   */
  function zahodKopieDat() {
    try { localStorage.removeItem(LS); } catch (e) {}
    try {
      if (window.caches && caches.keys) {
        caches.keys().then(function (nazvy) {
          nazvy.filter(function (n) { return n.indexOf('galerie-data-') === 0; }).forEach(function (n) { caches.delete(n); });
        }).catch(function () {});
      }
    } catch (e) {}
  }

  /*
   * Odhlášení a fronta workera.
   *
   * Zápisy bez signálu ležely v IndexedDB i po odhlášení — s tokenem a se
   * vším, co v nich bylo, i soukromými klíči — a po přihlášení kohokoli
   * odešly. Odhlášení je proto nejdřív doručí (token ještě platí), pak
   * teprve zruší přihlášení a frontu smaže. Čeká se jen chvíli: bez signálu
   * se nedoručí stejně a odhlášení nesmí viset.
   */
  var CEKANI_NA_FRONTU = 4000;
  var QUEUE_DB = 'galerie-queue'; // stejné jméno jako v sw.js
  var odhlasuji = false;

  function odesliFrontuWorkeru() {
    var sw = navigator.serviceWorker;
    if (!sw || !sw.controller) return false;
    // S aktuálním přihlášením a s tím, čí je — worker token vymění jen u zápisů téhož účtu.
    var auth = window.GALERIE_API_TOKEN ? 'Bearer ' + window.GALERIE_API_TOKEN : null;
    try { sw.controller.postMessage({ type: 'galerie-flush', auth: auth, ucet: kdoTed() }); } catch (e) { return false; }
    return true;
  }
  function pockejNaFrontuWorkera() {
    var sw = navigator.serviceWorker;
    if (!sw || !sw.controller) return Promise.resolve();
    return new Promise(function (hotovo) {
      var konec = setTimeout(dal, CEKANI_NA_FRONTU);
      function dal() { clearTimeout(konec); sw.removeEventListener('message', naZpravu); hotovo(); }
      function naZpravu(e) { if (e.data && e.data.type === 'galerie-sync-done') dal(); }
      sw.addEventListener('message', naZpravu);
      if (!odesliFrontuWorkeru()) dal();
    });
  }
  /*
   * Výsledek je `true`, když server zápis karty přímo přijal (nebo odmítl
   * střetem) — karta pak má starou revizi a má si stav načíst znovu.
   * Nedoručený zápis se vrací do fronty: odhlášení ho stejně zahodí, ale
   * počet neodeslaných (`neodeslane`) ho do té doby musí vidět.
   */
  function dorucPredOdhlasenim() {
    var cekani = Promise.resolve(false);
    var nejistyUcet = pendingUcet && tokenBezUctu();
    if (Object.keys(pending).length && !nejistyUcet && odmitnutyToken === undefined) {
      var zapsal = pendingUcet || kdoTed();
      var patch = pending;
      pending = {};
      cekani = fetch(base + '/state', { method: 'PATCH', headers: headers(), credentials: 'same-origin', body: teloZapisu(patch, zapsal) })
        .then(function (r) {
          // 202: zápis převzal worker do své fronty — počítá se tam.
          if (r.status === 202) { queuedBySw = true; return false; }
          if (r.ok || r.status === 409) return true;
          vratDoFronty(patch, zapsal);
          return false;
        })
        .catch(function () { vratDoFronty(patch, zapsal); return false; });
    }
    return cekani.then(function (primo) {
      return pockejNaFrontuWorkera().then(function () { return primo; });
    });
  }

  /*
   * Neodeslané změny tohoto účtu.
   *
   * Odhlášení bez signálu zahodí, co se ještě neodeslalo — nic soukromého
   * nemá na zařízení zůstat. Dřív to udělalo mlčky; obrazovka se teď nejdřív
   * zeptá. Počítá se paměť karty i fronta workera, obojí jen se zápisy
   * tohoto účtu, a to po klíčích stavu: dvě úpravy téhož seznamu jsou jedna
   * změna, rozdíl seznamu (`__odebrane`…) patří ke svému klíči.
   */
  function klicePatche(patch, klice) {
    var hlavni = Object.keys(patch || {}).filter(function (k) { return ROZDIL.indexOf(k) < 0; });
    hlavni.forEach(function (k) { klice[k] = 1; });
    if (!hlavni.length && Object.keys(patch || {}).length) klice.__rozdil = 1;
  }
  // Položky fronty workera, jak je uložil (`{ url, headers, body }`); bez fronty prázdno.
  function ctiFrontuWorkera() {
    return new Promise(function (hotovo) {
      var r = null;
      try { if (window.indexedDB) r = indexedDB.open(QUEUE_DB, 1); } catch (e) {}
      if (!r) { hotovo([]); return; }
      // Fronta ještě nevznikla: tady ji nezakládat — worker by pak neměl svůj sklad.
      r.onupgradeneeded = function () { try { r.transaction.abort(); } catch (e) {} };
      r.onerror = function (e) { try { e.preventDefault(); } catch (x) {} hotovo([]); };
      r.onsuccess = function () {
        var db = r.result;
        var out = [];
        db.onversionchange = function () { db.close(); };
        try {
          var tx = db.transaction('patches', 'readonly');
          tx.objectStore('patches').openCursor().onsuccess = function (ev) {
            var cur = ev.target.result;
            if (cur) { out.push(cur.value); cur.continue(); }
          };
          tx.oncomplete = tx.onabort = function () { db.close(); hotovo(out); };
        } catch (e) { db.close(); hotovo([]); }
      };
    });
  }
  // Rozeslaný zápis ještě běží — po něm teprve je jasné, jestli odešel, nebo čeká.
  function pockejNaOdeslani() {
    var konec = Date.now() + CEKANI_NA_FRONTU;
    return new Promise(function (hotovo) {
      (function znovu() { if (!inflight || Date.now() > konec) hotovo(); else setTimeout(znovu, 100); })();
    });
  }
  function zahodFrontuWorkera() {
    // Worker spojení při `versionchange` zavře, takže mazání nečeká.
    try { if (window.indexedDB) indexedDB.deleteDatabase(QUEUE_DB); } catch (e) {}
  }
  // Adresa odběru upozornění tohoto zařízení — server podle ní pozná, který odběr je jeho.
  function adresaOdberu() {
    try {
      if (!navigator.serviceWorker || !navigator.serviceWorker.getRegistration || !('PushManager' in window)) return Promise.resolve(null);
      return navigator.serviceWorker.getRegistration()
        .then(function (reg) { return reg && reg.pushManager ? reg.pushManager.getSubscription() : null; })
        .then(function (sub) { return sub && sub.endpoint ? sub.endpoint : null; })
        .catch(function () { return null; });
    } catch (e) { return Promise.resolve(null); }
  }

  function flush() {
    timer = null;
    if (inflight) { schedule(400); return; }
    if (!Object.keys(pending).length) return;
    // Čeká se na přihlášení: se stejným (žádným) tokenem by to dopadlo stejně.
    if (odmitnutyToken !== undefined && (window.GALERIE_API_TOKEN || null) === odmitnutyToken) return;
    odmitnutyToken = undefined;
    // Token se změnil mimo přihlášení heslem (otisk): nejdřív zjistit, komu patří.
    if (mode === 'http' && pendingUcet && tokenBezUctu() && overUcet()) return;

    var zapsal = pendingUcet || kdoTed();
    var patch;
    var hlavniVeFronte = Object.keys(pending).filter(function (k) { return ROZDIL.indexOf(k) < 0; });
    if (poJednom && hlavniVeFronte.length) {
      patch = vyjmiKlic(hlavniVeFronte[0]);
    } else {
      patch = pending;
      pending = {};
    }

    if (mode === 'local') {
      rev += 1; writeLocal(); lastSync = new Date();
      if (bc) { try { bc.postMessage({ rev: rev }); } catch (e) {} }
      return;
    }

    inflight = true;
    var sTokenem = !!window.GALERIE_API_TOKEN;
    fetch(base + '/state', { method: 'PATCH', headers: headers(), credentials: 'same-origin', body: teloZapisu(patch, zapsal) })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          return r.json().catch(function () { return {}; }).then(function (b) {
            throw Object.assign(new Error('HTTP ' + r.status), { status: r.status, zprava: (b && b.message) || '' });
          });
        }
        // 202 = service worker patch přijal do fronty a doručí ho sám
        // (i když aplikaci zavřete). Lokální kopie je tím pádem platná.
        if (r.status === 202) { queuedBySw = true; return null; }
        // 409 znamená, že se **všechny** poslané klíče mezitím změnily
        // u toho druhého. Stav se převezme od serveru a člověku se to
        // řekne — obrazovka, která se sama vrátí o krok zpět a mlčí,
        // je horší než střet.
        if (r.status === 409) return r.json().then(function (b) {
          data = b.data || data; rev = b.rev || rev;
          writeLocal(); notify(); ohlasStret(b.strety || Object.keys(patch));
          return null;
        });
        if (r.status === 422) return r.json().catch(function () { return {}; }).then(function (b) {
          throw Object.assign(new Error('HTTP 422'), { status: 422, jinyUcet: !!(b && b.jiny_ucet) });
        });
        if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { status: r.status });
        return r.json();
      })
      .then(function (b) {
        prodleva = 0;
        if (poJednom) {
          if (Object.keys(pending).length) schedule(300); else poJednom = false;
        }
        if (b) {
          rev = b.rev || rev + 1;
          oznacDocasne(b);

          if (b.data) {
            data = b.data;
            /*
             * Co čeká na odeslání, serveru ještě nedorazilo.
             *
             * Jeho odpověď o tom neví a přepsala by rozepsanou změnu zpátky
             * na stav před ní.
             */
            Object.keys(pending).forEach(function (k) { data[k] = pending[k]; });
          }

          writeLocal();
          if (b.strety && b.strety.length) ohlasStret(b.strety);
          /*
           * A obrazovka se to musí dozvědět.
           *
           * Bez tohohle řádku odpověď skončila v lokální kopii a nikdo ji
           * nepřečetl: aplikace dál kreslila to, co si sama tipla. U věcí,
           * kde server přiděluje identifikátor — nová položka v tabulce —
           * to znamenalo, že si ho klient nikdy nepřevzal a při další změně
           * poslal řádek znovu jako nový. Jedno kliknutí pak založilo
           * druhou kopii celého seznamu.
           */
          notify();
        }
        lastSync = new Date(); lastError = null;
      })
      .catch(function (e) {
        var stav = e && e.status;
        lastError = String(e && e.message || e);

        if (stav === 401 || stav === 403) {
          vratDoFronty(patch, zapsal);
          odmitnutyToken = window.GALERIE_API_TOKEN || null;
          if (sTokenem) odhlaseno(stav, e.zprava);
          return;
        }

        // Token patří jinému účtu, než kdo zápis napsal: neprojde ani po kouscích.
        // Zápis se zahodí a karta se zeptá, čí token je — další zápisy pak nesou správného autora.
        if (e && e.jinyUcet) {
          kdoToken = undefined;
          overenyToken = undefined;
          overUcet();
          return;
        }

        if (stav === 400 || stav === 413 || stav === 422) {
          var hlavni = Object.keys(patch).filter(function (k) { return ROZDIL.indexOf(k) < 0; });
          if (hlavni.length > 1) {
            vratDoFronty(patch, zapsal);
            poJednom = true;
            schedule(300);
            return;
          }
          // Tenhle klíč server nevezme ani samotný — další pokus by dopadl stejně.
          ohlas('galerie-odmitnuto', { klice: hlavni, status: stav });
          if (Object.keys(pending).length) schedule(300); else poJednom = false;
          return;
        }

        // Offline nebo chyba serveru: patch se vrátí do fronty a zkusí se
        // znovu, pokaždé s delší prodlevou. Prototyp funguje dál, jen nesynchronizuje.
        vratDoFronty(patch, zapsal);
        prodleva = Math.min(prodleva ? prodleva * 2 : 4000, 120000);
        schedule(prodleva);
      })
      .then(function () { inflight = false; });
  }
  function schedule(ms) {
    if (timer) clearTimeout(timer);
    timer = setTimeout(flush, ms === undefined ? 450 : ms);
  }

  try {
    bc = new BroadcastChannel(CH);
    bc.onmessage = function () { var next = readLocal(); data = next; notify(); };
  } catch (e) {}
  window.addEventListener('storage', function (e) {
    if (e.key !== LS) return;
    data = readLocal(); notify();
  });
  // Nedoručený patch se zkusí poslat ještě při zavírání karty.
  window.addEventListener('online', function () { prodleva = 0; schedule(200); notify(); });
  window.addEventListener('offline', notify);
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (!e.data || e.data.type !== 'galerie-sync-done') return;
      queuedBySw = false; lastSync = new Date();
      /*
       * Co fronta workera doručila, karta ještě nemá.
       *
       * Revize tu zůstala z doby bez signálu, takže další změna téhož klíče
       * by narazila na střet s vlastním doručeným zápisem. A klíče, které
       * mezitím změnil ten druhý, worker dřív zahodil mlčky — teď je vrací
       * (`strety`, po autorech) a karta to řekne jako u vlastního zápisu.
       */
      var moje = kdoTed() && e.data.strety ? e.data.strety[kdoTed()] : null;
      if (moje && moje.length) ohlasStret(moje);
      // Při odhlašování ne: odpověď by po úklidu znovu uložila data do prohlížeče.
      if (e.data.doruceno && window.GalerieApi && mode === 'http' && !odhlasuji) window.GalerieApi.load();
      else notify();
    });
  }
  /*
   * Rozepsaná změna při zavírání karty.
   *
   * Posílala se přes `sendBeacon`, jenže ten umí jen POST a žádné hlavičky.
   * Na `/api/state` žádný POST není a bez `Authorization` server odpoví 401
   * — takže to, co člověk změnil v posledních 450 ms před zavřením karty,
   * se tiše ztratilo. V síťovém logu to bylo vidět jako `POST /api/state 401`.
   *
   * `fetch` s `keepalive` přežije zavření stránky stejně jako beacon a nese
   * hlavičky i správnou metodu. `pagehide` a přechod do pozadí se hlídají
   * taky: na telefonu `beforeunload` často nepřijde vůbec.
   */
  function odesliPriOdchodu() {
    if (!Object.keys(pending).length) return;
    if (mode === 'local') { rev += 1; writeLocal(); return; }
    // Server tenhle zápis už odmítl (přihlášení, velikost) — naslepo by ho odmítl znovu.
    if (odmitnutyToken !== undefined || poJednom) return;
    var zapsal = pendingUcet || kdoTed();
    var patch = pending;
    pending = {};
    try {
      fetch(base + '/state', {
        method: 'PATCH', headers: headers(), credentials: 'same-origin', keepalive: true,
        body: teloZapisu(patch, zapsal)
      }).catch(function () {});
    } catch (e) {
      // Starší prohlížeč bez `keepalive`: změna zůstane uložená lokálně
      // a odejde při příštím spuštění.
      Object.keys(patch).forEach(function (k) { if (pending[k] === undefined) pending[k] = patch[k]; });
      writeLocal();
    }
  }
  window.addEventListener('beforeunload', odesliPriOdchodu);
  window.addEventListener('pagehide', odesliPriOdchodu);
  // Přechod do pozadí stránku nezavírá — tady stačí obyčejné odeslání, které
  // si převezme odpověď serveru i novou revizi. Odeslání „naslepo" by nechalo
  // starou revizi a další zápis téhož klíče by server vzal jako střet.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'hidden' || !Object.keys(pending).length) return;
    if (timer) { clearTimeout(timer); timer = null; }
    flush();
  });

  window.GalerieApi = {
    // Značka pro druhý průchod téhož souboru — viz začátek.
    __vrstva: true,
    mode: mode,
    base: base,

    // Okamžitý stav z lokální kopie — aplikace umí vykreslit hned, bez čekání na síť.
    loadSync: function () { return snapshot(); },

    // Autoritativní stav ze serveru (v local režimu jen lokální kopie).
    load: function () {
      if (mode === 'local') return Promise.resolve(snapshot());
      var sTokenem = !!window.GALERIE_API_TOKEN;
      var tokenCteni = window.GALERIE_API_TOKEN || null;
      return fetch(base + '/state', { headers: headers(), credentials: 'same-origin' })
        .then(function (r) {
          // Uložený token už neplatí (90 dní bez použití, odebraný přístup).
          // Bez tokenu je 401 jen zamčená obrazovka, žádné „přihlášení skončilo".
          if ((r.status === 401 || r.status === 403) && sTokenem) {
            return r.json().catch(function () { return {}; }).then(function (b) {
              odhlaseno(r.status, b && b.message);
              throw new Error('HTTP ' + r.status);
            });
          }
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (b) {
          // Čí je token, dřív než se na stav položí čekající zápis — cizí se zahodí.
          if ((window.GALERIE_API_TOKEN || null) === tokenCteni) prevezmiUcet(b.ucet);
          data = b.data || {}; rev = b.rev || 0;
          /*
           * Co čeká ve frontě, zůstává nahoře.
           *
           * Odpověď serveru je pravda o tom, co se uložilo — ale zápis,
           * který se kvůli výpadku ještě neodeslal, by se jí přepsal
           * a obrazovka by ukázala starší hodnotu, než jakou má člověk
           * před očima. Odešle se hned, jak to půjde (`schedule`).
           */
          if (Object.keys(pending).length) { merge(pending); schedule(0); }
          writeLocal(); lastSync = new Date(); notify();
          return snapshot();
        })
        .catch(function (e) { lastError = String(e && e.message || e); return snapshot(); });
    },

    // Zápis. Slučuje se lokálně hned, odesílá se s prodlevou (450 ms) v jednom PATCHi.
    save: function (patch) {
      if (!patch) return;
      if (!merge(patch)) return;
      // Nová fronta patří tomu, kdo je právě přihlášený.
      if (!Object.keys(pending).length) pendingUcet = kdoTed();
      Object.keys(patch).forEach(function (k) {
        /*
         * Změněné položky se ve frontě sčítají.
         *
         * Ostatní rozdíly prohlížeč počítá celé ze stavu, takže novější
         * přepíše starší správně. Změněné se ale počítají proti poslední
         * odpovědi serveru — a zápis, který čekal ve frontě (offline, obnovení
         * stránky), by jinak přepsal seznam změn toho předchozího.
         */
        if (k === '__zmenene' && pending[k] && typeof pending[k] === 'object' && patch[k] && typeof patch[k] === 'object') {
          var spojene = Object.assign({}, pending[k]);
          Object.keys(patch[k]).forEach(function (s) {
            spojene[s] = Array.isArray(spojene[s]) ? spojene[s].concat((patch[k][s] || []).filter(function (id) { return spojene[s].indexOf(id) < 0; })) : patch[k][s];
          });
          pending[k] = spojene;
          return;
        }
        /*
         * Seznamy (`xRows`) se ve frontě slučují po seznamech.
         *
         * Telefon posílá jen seznam, který změnil (`{ xRows: { shopping } }`),
         * a druhý zápis v téže vteřině (`{ xRows: { gifts } }`) přepsal celé
         * `xRows` — nákup z rychlého zápisu se na server nikdy nedostal.
         */
        if (k === 'xRows' && pending[k] && typeof pending[k] === 'object' && patch[k] && typeof patch[k] === 'object') {
          pending[k] = Object.assign({}, pending[k], patch[k]);
          return;
        }
        pending[k] = patch[k];
      });
      writeLocal();
      schedule();
    },

    subscribe: function (fn) {
      subs.push(fn);
      return function () { subs = subs.filter(function (x) { return x !== fn; }); };
    },

    reset: function () {
      data = {}; rev = 0; pending = {};
      try { localStorage.removeItem(LS); } catch (e) {}
      if (mode === 'http') fetch(base + '/state', { method: 'DELETE', headers: headers(), credentials: 'same-origin' }).catch(function () {});
      notify();
    },

    status: function () {
      return {
        mode: mode, rev: rev, online: navigator.onLine !== false,
        pending: Object.keys(pending).length,
        queuedBySw: queuedBySw,
        installed: !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches),
        lastSync: lastSync, lastError: lastError
      };
    },

    // ——— Přihlášení ———
    // POST {tokenUrl} ← { email, password, device_name } → { token, user }
    // Sanctum vrátí osobní token; ten se drží v paměti a v localStorage, aby
    // se po restartu aplikace nemuselo přihlašovat znovu. V lokálním režimu
    // vrací null — volající pak ověří heslo sam, jako dosud.
    signIn: function (email, password, device, code, volby) {
      if (mode !== 'http') return Promise.resolve(null);
      // `volby.bezDotazu`: přihlašovací obrazovka má na kód vlastní políčko —
      // chyba s `two_factor` se vrátí jí, místo aby se ptal dialog prohlížeče.
      var bezDotazu = !!(volby && volby.bezDotazu);
      var self = this;
      var url = (typeof window !== 'undefined' && window.GALERIE_TOKEN_URL) || '/sanctum/token';
      var telo = { email: email, password: password, device_name: device || 'telefon' };
      if (code) telo.code = code;
      return fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(telo)
      }).then(function (r) {
        return r.json().then(function (b) {
          /*
           * Dvoufázové ověření.
           *
           * Server token se samotným heslem nevydá, když má účet zapnutý druhý
           * faktor, a řekne to příznakem `two_factor`. Přihlašovací obrazovky
           * obou rozvržení na kód políčko nemají — zeptá se proto prohlížeč
           * a pokus se zopakuje. Zrušení dotazu vrátí chybu jako dosud.
           */
          if (!bezDotazu && r.status === 422 && b && b.two_factor && typeof window !== 'undefined' && window.prompt) {
            var zadany = window.prompt(code
              ? 'Kód nesouhlasí. Zadejte znovu kód z ověřovací aplikace, nebo obnovovací kód:'
              : 'Zadejte kód z ověřovací aplikace, nebo obnovovací kód:');
            if (zadany) return self.signIn(email, password, device, String(zadany).trim());
          }
          if (!r.ok) throw Object.assign(new Error(b.message || 'HTTP ' + r.status), { status: r.status, body: b });
          if (b.token) {
            window.GALERIE_API_TOKEN = b.token;
            try { localStorage.setItem('galerie.token', b.token); } catch (e) {}
            // Co čekalo na přihlášení, odejde hned — ale jen když se přihlásil
            // týž účet. Jinak by jeho zápis odešel pod ní (`prevezmiUcet` ho zahodí).
            // Odpověď na přihlášení stav dvojice nenese — po přepnutí účtu se
            // proto hned načte znovu, ať odběratelé nevykreslí prázdno ani
            // zbytek toho předchozího.
            if (b.user && prevezmiUcet(b.user.id)) self.load();
            if (Object.keys(pending).length) schedule(450);
          }
          return b;
        });
      });
    },

    /*
     * Odhlášení: doručit, odhlásit, uklidit — v tomhle pořadí.
     *
     * Rozepsané změny a fronta workera odejdou ještě s platným tokenem;
     * teprve pak se zruší přihlášení (s adresou odběru upozornění, ať
     * odhlášený telefon dál nezvoní) a smaže se, co by po odhlášení zůstalo:
     * token, kopie dat, čekající zápisy i fronta workera.
     */
    signOut: function () {
      if (mode !== 'http') {
        window.GALERIE_API_TOKEN = null;
        try { localStorage.removeItem('galerie.token'); } catch (e) {}
        return Promise.resolve(null);
      }
      if (timer) { clearTimeout(timer); timer = null; }
      odhlasuji = true;
      return dorucPredOdhlasenim()
        .then(adresaOdberu)
        .then(function (endpoint) {
          return fetch(base + '/logout', {
            method: 'POST', headers: headers(), credentials: 'same-origin',
            body: JSON.stringify(endpoint ? { endpoint: endpoint } : {})
          });
        })
        .catch(function () { return null; })
        .then(function (odpoved) {
          window.GALERIE_API_TOKEN = null;
          try { localStorage.removeItem('galerie.token'); localStorage.removeItem('galerie.ucet'); } catch (e) {}
          pending = {}; pendingUcet = null; poJednom = false; odmitnutyToken = undefined;
          kdo = null; kdoToken = null;
          zahodKopieDat();
          zahodFrontuWorkera();
          odhlasuji = false;
          // Aplikace ukáže přihlášení — tutéž obrazovku jako po prošlém tokenu.
          ohlas('galerie-odhlaseno', { status: 0, zprava: '', vyslovne: true });
          return odpoved;
        });
    },

    /*
     * Kolik změn tohoto účtu se ještě neodeslalo (Promise<number>).
     *
     * Paměť karty a fronta workera; cizí zápis (jiný autor) se nepočítá —
     * ten neodejde pod tímhle účtem nikdy. Zápis bez autora z fronty patří
     * tomu, pod čím tokenem vznikl. Viz „Neodeslané změny tohoto účtu".
     */
    neodeslane: function () {
      if (mode !== 'http') return Promise.resolve(0);
      return pockejNaOdeslani().then(ctiFrontuWorkera).then(function (fronta) {
        var ja = kdoTed() || kdo;
        var tokenTed = window.GALERIE_API_TOKEN ? 'Bearer ' + window.GALERIE_API_TOKEN : null;
        var klice = {};
        if (!(pendingUcet && ja && pendingUcet !== ja)) klicePatche(pending, klice);
        fronta.forEach(function (it) {
          var telo = null;
          try { telo = JSON.parse(it && it.body); } catch (e) {}
          if (!telo || typeof telo !== 'object') return;
          var autor = telo.ucet === undefined || telo.ucet === null || telo.ucet === '' ? null : String(telo.ucet);
          var muj = autor ? autor === ja : !!tokenTed && ((it.headers || {}).authorization || null) === tokenTed;
          if (muj) klicePatche(telo.data, klice);
        });
        return Object.keys(klice).length;
      });
    },

    /*
     * Zkusit doručit, co čeká — tentýž krok, jakým začíná `signOut`.
     *
     * Když se člověk po dotazu rozhodne zůstat přihlášený, karta má po
     * přímém doručení starou revizi; stav se proto načte znovu. Po doručení
     * frontou workera se načítá sám (`galerie-sync-done`).
     */
    dorucNeodeslane: function () {
      if (mode !== 'http') return Promise.resolve();
      if (timer) { clearTimeout(timer); timer = null; }
      var api = this;
      return pockejNaOdeslani().then(dorucPredOdhlasenim).then(function (primo) {
        if (odhlasuji) return;
        // Co se nedoručilo, je zpátky ve frontě karty — a jde dál běžnou cestou
        // s prodlevou; bez toho by čekalo na další úpravu nebo návrat signálu.
        if (Object.keys(pending).length) schedule(Math.max(prodleva, 4000));
        if (primo) return api.load().then(function () {});
      });
    },

    /*
     * „Odhlásit ostatní zařízení" s adresou odběru tohoto zařízení.
     *
     * Server bez ní zruší odběry upozornění všem, i tomuhle telefonu, který
     * přihlášený zůstává; s ní ten jeho nechá být.
     */
    odhlasOstatni: function () {
      var api = this;
      return adresaOdberu().then(function (endpoint) {
        return api.post('zamek/odhlasit-ostatni', endpoint ? { endpoint: endpoint } : {});
      });
    },

    // ——— Nahrávání médií ———
    // POST {base}/media (multipart) → { id, name, bytes, status }
    // Velké soubory jdou po částech: POST {base}/media/chunk s hlavičkami
    // X-Upload-Id, X-Chunk-Index, X-Chunk-Count. 202 znamená, že zápis vzal
    // service worker do fronty a doručí ho sám. Bez backendu vrací null,
    // aby aplikace mohla soubor jen započítat lokálně.
    upload: function (file, meta) {
      if (mode !== 'http') return Promise.resolve(null);
      var CHUNK = 8 * 1024 * 1024;
      var hdr = function () {
        var h = { 'Accept': 'application/json' };
        var t = csrf();
        if (t) h['X-CSRF-TOKEN'] = t;
        if (window.GALERIE_API_TOKEN) h['Authorization'] = 'Bearer ' + window.GALERIE_API_TOKEN;
        return h;
      };
      if (file.size <= CHUNK) {
        var fd = new FormData();
        fd.append('file', file, file.name);
        Object.keys(meta || {}).forEach(function (k) { fd.append(k, meta[k]); });
        return pockejNaSlot().then(function () {
          return fetch(base + '/media', { method: 'POST', headers: hdr(), credentials: 'same-origin', body: fd });
        }).then(function (r) {
          if (r.status === 202) return { status: 'queued' };
          return r.json().then(function (b) {
            // Chybová odpověď dřív prošla jako „nahráno" — do knihovny nedorazilo nic.
            if (!r.ok) throw Object.assign(new Error(b.message || 'HTTP ' + r.status), { status: r.status, body: b });
            return b;
          });
        });
      }
      var id = 'up-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8);
      var count = Math.ceil(file.size / CHUNK);
      var send = function (i) {
        if (i >= count) return Promise.resolve({ id: id, status: 'complete', name: file.name, bytes: file.size });
        var part = file.slice(i * CHUNK, Math.min(file.size, (i + 1) * CHUNK));
        var h = hdr();
        h['X-Upload-Id'] = id;
        h['X-Chunk-Index'] = String(i);
        h['X-Chunk-Count'] = String(count);
        h['X-File-Name'] = encodeURIComponent(file.name);
        return pockejNaSlot().then(function () {
          return fetch(base + '/media/chunk', { method: 'POST', headers: h, credentials: 'same-origin', body: part });
        }).then(function (r) {
          if (!r.ok && r.status !== 202) throw Object.assign(new Error('HTTP ' + r.status), { status: r.status });
          /*
           * Poslední část vrací hotový záznam (`id` fotky, `stored`/`duplicate`).
           * Dřív se zahodil a vracel se jen identifikátor přenosu — duplicitní
           * velké video se tak hlásilo jako nahrané a fotku nešlo zařadit do alba.
           */
          if (i === count - 1 && r.status !== 202) {
            return r.json().then(function (b) { return Object.assign({ name: file.name, bytes: file.size }, b); },
              function () { return { id: id, status: 'complete', name: file.name, bytes: file.size }; });
          }
          return send(i + 1);
        });
      };
      return send(0);
    },

    /*
     * Nahrání víc souborů najednou.
     *
     * Dřív šly soubory po jednom, takže dvě stě fotek z telefonu trvalo tolik,
     * kolik trvá dvě stě nahrání za sebou. A co selhalo, hláška poslala „do
     * fronty, odejde po připojení" — přitom žádná fronta pro soubory není:
     * soubor se ztratil a nikdo o tom nevěděl.
     *
     * Teď běží tři nahrávání souběžně, co selže, se zkusí ještě jednou, a na
     * konci se řekne, kolik prošlo, kolik už v knihovně bylo a která jména
     * zůstala venku. `onProgress(hotovo, celkem)` hlásí postup.
     */
    /*
     * `onItem(index, stav, zprava)` hlásí jednotlivé soubory — `nahravam`,
     * `hotovo`, `duplicitni`, `opakuji`, `selhalo`. Panel přenosů podle toho
     * kreslí skutečná jména; dřív ukazoval pět vymyšlených („IMG_2711.HEIC,
     * Přenáším originál — 62 %") a „Nahrávám 4 z 218" u tří fotek.
     *
     * `api.pauza = true` zastaví start dalších souborů; rozběhnuté doběhnou.
     */
    nahrajVse: function (files, onProgress, onItem) {
      var api = this;
      var seznam = Array.prototype.slice.call(files || []);
      var celkem = seznam.length, hotovo = 0, ulozeno = 0, duplicitni = 0;
      var selhalo = [], media = [];
      var fronta = seznam.map(function (f, i) { return { f: f, i: i, pokus: 0 }; });
      var hlas = function (i, stav, zprava) { try { if (onItem) onItem(i, stav, zprava || ''); } catch (e) {} };

      function pockejNaPokracovani() {
        if (!api.pauza) return Promise.resolve();
        return new Promise(function (hotovo) { setTimeout(hotovo, 400); }).then(pockejNaPokracovani);
      }

      function dalsi() {
        return pockejNaPokracovani().then(function () {
          var polozka = fronta.shift();
          if (!polozka) return;
          hlas(polozka.i, 'nahravam');
          return api.upload(polozka.f, { taken_at: polozka.f.lastModified }).then(function (b) {
            // Identifikátory nahraných fotek — kvůli zařazení do alba, odkud se nahrávalo.
            if (b && b.id && String(b.id).indexOf('up-') !== 0) media.push(b.id);
            if (b && b.status === 'duplicate') { duplicitni++; hlas(polozka.i, 'duplicitni'); } else { ulozeno++; hlas(polozka.i, 'hotovo'); }
            hotovo++;
            if (onProgress) onProgress(hotovo, celkem);
          }, function (e) {
            // 413 a 422 se opakováním nespraví (velký soubor, nepodporovaný formát).
            var stav = e && e.status;
            var zprava = (e && e.body && e.body.message) || '';
            /*
             * Bez přihlášení neprojde ani jeden další soubor.
             *
             * Každý zbylý soubor se zkoušel dvakrát — u pěti set fotek tisíc
             * odmítnutých požadavků za sebou. Dávka se zastaví, zbytek se
             * označí jako neodeslaný a aplikace ukáže přihlášení.
             */
            if (stav === 401 || stav === 403) {
              var zbyle = [polozka].concat(fronta.splice(0, fronta.length));
              zbyle.forEach(function (p) {
                selhalo.push({ jmeno: p.f.name, stav: stav, zprava: zprava });
                hlas(p.i, 'selhalo', zprava);
                hotovo++;
              });
              if (onProgress) onProgress(hotovo, celkem);
              if (window.GALERIE_API_TOKEN) odhlaseno(stav, zprava);
              return;
            }
            if (polozka.pokus < 1 && stav !== 413 && stav !== 422) {
              polozka.pokus++;
              fronta.push(polozka);
              hlas(polozka.i, 'opakuji', zprava);
            } else {
              selhalo.push({ jmeno: polozka.f.name, stav: stav || 0, zprava: zprava });
              hlas(polozka.i, 'selhalo', zprava);
              hotovo++;
              if (onProgress) onProgress(hotovo, celkem);
            }
          }).then(dalsi);
        });
      }

      var soubezne = Math.min(3, Math.max(1, celkem));
      var vlakna = [];
      for (var i = 0; i < soubezne; i++) vlakna.push(dalsi());

      return Promise.all(vlakna).then(function () {
        return { celkem: celkem, ulozeno: ulozeno, duplicitni: duplicitni, selhalo: selhalo, media: media };
      });
    },

    // ——— Upozornění ———
    // Posílají se jen dvě věci: vypršělá domluva a revize rozhodnutí.
    pushSubscribe: function () {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) return Promise.resolve(null);
      var key = (typeof window !== 'undefined' && window.GALERIE_VAPID_KEY) || null;
      return Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') return null;
        return navigator.serviceWorker.ready.then(function (reg) {
          var opts = { userVisibleOnly: true };
          if (key) {
            var raw = atob(key.replace(/-/g, '+').replace(/_/g, '/'));
            opts.applicationServerKey = Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
          }
          return reg.pushManager.subscribe(opts).then(function (sub) {
            if (mode !== 'http') return sub;
            return fetch(base + '/push/subscribe', {
              method: 'POST', headers: headers(), credentials: 'same-origin', body: JSON.stringify(sub)
            }).then(function () { return sub; });
          });
        });
      });
    },

    pushUnsubscribe: function () {
      if (!('serviceWorker' in navigator)) return Promise.resolve(null);
      return navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
          if (!sub) return null;
          var ep = sub.endpoint;
          return sub.unsubscribe().then(function () {
            if (mode !== 'http') return null;
            return fetch(base + '/push/subscribe', {
              method: 'DELETE', headers: headers(), credentials: 'same-origin', body: JSON.stringify({ endpoint: ep })
            }).catch(function () { return null; });
          });
        });
      });
    },

    // Jednotlivý POST mimo stav (přihlašovací klíč). V lokálním režimu vrací
    // null, takže se volající pozná, že backend není, a použije vlastní cestu.
    post: function (path, body) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, {
        method: 'POST', headers: headers(), credentials: 'same-origin',
        body: JSON.stringify(body || {})
      }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
          return b;
        });
      });
    },

    /*
     * Stažení souboru ze serveru.
     *
     * Přes fetch s hlavičkami, ne přes obyčejný odkaz: `<a href>` posílá
     * prohlížeč sám a hlavičku `Authorization` k němu nepřidá, takže by
     * sezení přihlášené tokenem dostalo 401. Takhle to funguje i s cookie,
     * i s tokenem.
     */
    // S `body` jde požadavek jako POST (archiv výběru — seznam fotek se do adresy nevejde).
    download: function (path, filename, body) {
      if (mode !== 'http') return Promise.resolve(null);
      var volby = body
        ? { method: 'POST', headers: headers(), credentials: 'same-origin', body: JSON.stringify(body) }
        : { headers: headers(), credentials: 'same-origin' };
      return fetch(base + '/' + path, volby)
        .then(function (r) {
          if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { status: r.status });
          return r.blob();
        })
        .then(function (blob) {
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = filename || 'soubor';
          document.body.appendChild(a);
          a.click();
          a.remove();
          // Uvolnit až po kliknutí, jinak Safari stáhne prázdný soubor.
          setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
          return true;
        });
    },

    // Úprava jedné věci mimo stav (nastavení sdíleného odkazu). Jako post,
    // jen jiná metoda — server podle ní pozná změnu od založení.
    patch: function (path, body) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, {
        method: 'PATCH', headers: headers(), credentials: 'same-origin',
        body: JSON.stringify(body || {})
      }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
          return b;
        });
      });
    },

    // Náhrada celé věci (změna hesla) — `PUT` jako v API účtu.
    put: function (path, body) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, {
        method: 'PUT', headers: headers(), credentials: 'same-origin',
        body: JSON.stringify(body || {})
      }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
          return b;
        });
      });
    },

    /*
     * Profilová fotka — soubor, takže `FormData` bez ručního `Content-Type`.
     * `/api/v1/avatar` hlídá typ i velikost a vrátí adresu nové fotky.
     */
    nahrajAvatar: function (soubor) {
      if (mode !== 'http' || ! soubor) return Promise.resolve(null);
      var telo = new FormData();
      telo.append('image', soubor, soubor.name || 'avatar');

      var h = headers();
      delete h['Content-Type'];

      return fetch(base + '/v1/avatar', { method: 'POST', headers: h, credentials: 'same-origin', body: telo })
        .then(function (r) {
          return r.json().then(function (b) {
            if (! r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
            return b;
          });
        });
    },

    /*
     * Nahrávka hlasu.
     *
     * Zvlášť od `post`, protože jde o soubor: `FormData` si hranici těla
     * skládá sám a `Content-Type` se k němu nesmí přidat ručně.
     */
    nahrajHlas: function (blob, vteriny, nazev) {
      if (mode !== 'http') return Promise.resolve(null);
      var telo = new FormData();
      telo.append('audio', blob, 'hlasovka.webm');
      if (nazev) telo.append('title', nazev);
      if (vteriny) telo.append('duration_ms', String(Math.round(vteriny * 1000)));

      var h = headers();
      delete h['Content-Type'];

      // `/api/v1`, ne `/api`: modul hlasovek je za verzovanou cestou.
      return fetch(base + '/v1/voice-notes', { method: 'POST', headers: h, credentials: 'same-origin', body: telo })
        .then(function (r) {
          return r.json().then(function (b) {
            if (! r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
            return b;
          });
        });
    },

    /*
     * Výpis z banky (CSV, XLS, XLSX) na účet v knize plateb.
     *
     * Soubor, takže `FormData` bez ručního `Content-Type`. Server vrátí větu
     * pro toast a rovnou novou knihu (`data`, `prazdne`) jako jiné akce.
     * Příliš velký soubor odmítne už webový server stránkou místo JSON —
     * i pak přijde srozumitelná chyba, ne výjimka z `r.json()`.
     */
    nahrajVypis: function (soubor, ucet) {
      if (mode !== 'http' || ! soubor) return Promise.resolve(null);
      var telo = new FormData();
      telo.append('vypis', soubor, soubor.name || 'vypis.csv');
      if (ucet) telo.append('ucet', ucet);

      var h = headers();
      delete h['Content-Type'];

      return fetch(base + '/finance/import', { method: 'POST', headers: h, credentials: 'same-origin', body: telo })
        .then(function (r) {
          return r.json().catch(function () {
            return { message: r.status === 413 ? 'Výpis je na server příliš velký — stáhněte z banky kratší období.' : 'Server výpis nepřijal (' + r.status + ').' };
          }).then(function (b) {
            if (! r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
            return b;
          });
        });
    },

    /*
     * Obrázek do chatu.
     *
     * Zvlášť od `post` ze stejného důvodu jako hlasovka: jde o soubor, takže
     * hranici těla si `FormData` skládá samo a `Content-Type` se k němu
     * nesmí přidat ručně.
     */
    nahrajDoChatu: function (soubor, text) {
      if (mode !== 'http' || ! soubor) return Promise.resolve(null);
      var telo = new FormData();
      telo.append('image', soubor, soubor.name || 'priloha');
      if (text) telo.append('body', text);

      var h = headers();
      delete h['Content-Type'];

      return fetch(base + '/v1/chat', { method: 'POST', headers: h, credentials: 'same-origin', body: telo })
        .then(function (r) {
          return r.json().then(function (b) {
            if (! r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
            return b;
          });
        });
    },

    /*
     * Adresa k přehrání hlasovky.
     *
     * Přes `fetch` do `blob:`, ne přímým odkazem: proud je za přihlášením
     * a `<audio src>` k němu hlavičku `Authorization` nepřidá.
     */
    adresaHlasu: function (uuid) {
      if (mode !== 'http' || ! uuid) return Promise.resolve(null);
      return fetch(base + '/v1/voice-notes/' + uuid + '/stream', { headers: headers(), credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.blob() : null; })
        .then(function (b) { return b ? URL.createObjectURL(b) : null; })
        .catch(function () { return null; });
    },

    // Čtení jedné věci ze serveru (komentáře u fotky…). V lokálním režimu null.
    get: function (path) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, {
        headers: headers(), credentials: 'same-origin', cache: 'no-cache'
      }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
          return b;
        });
      });
    },

    // Smazání jedné položky (fotka do koše). Stejná cesta jako post: v lokálním
    // režimu vrací null, aby volající poznal, že backend není.
    // Volitelné tělo (třeba heslo k vypnutí ověření) — nikdy do adresy, ta končí v lozích.
    del: function (path, body) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, {
        method: 'DELETE', headers: headers(), credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined
      }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw Object.assign(new Error('HTTP ' + r.status), { body: b, status: r.status });
          return b;
        });
      });
    },

    // Definice mechanismů ze serveru. Když backend není nebo data ještě
    // nevygeneroval, zůstane v platnosti window.GalerieMech ze souboru.
    mechanisms: function () {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/mechanisms', { headers: headers(), credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) return null; return r.json(); })
        .then(function (b) {
          if (!b || !b.data) return null;
          // Hlavička si serverovou verzi pamatuje a navlékne ji znovu, kdyby
          // runtime později spustil `galerie-mechanismy.js` s ukázkou.
          window.GalerieMechZeServeru = b.data;
          window.GalerieMech = Object.assign({}, window.GalerieMech || {}, b.data);
          return b.data;
        })
        .catch(function () { return null; });
    },

    // Požádá service worker, ať zkusí frontu odeslat hned.
    flush: function () {
      prodleva = 0;
      schedule(0);
      // Zápis ve frontě workera nese token z doby, kdy vznikl — čerstvý dostane jen týž účet.
      odesliFrontuWorkeru();
    }
  };
})();
