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
  var timer = null;
  var inflight = false;
  var lastSync = null;
  var lastError = null;
  var queuedBySw = false;
  var bc = null;

  function readLocal() {
    try {
      var raw = JSON.parse(localStorage.getItem(LS));
      if (raw && typeof raw === 'object') { rev = raw.rev || 0; return raw.data || {}; }
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

      localStorage.setItem(LS, JSON.stringify({ data: ulozit, rev: rev, updated_at: new Date().toISOString() }));
    } catch (e) {}
  }
  data = readLocal();

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
  function merge(patch) {
    var changed = false;
    Object.keys(patch || {}).forEach(function (k) {
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
    try {
      window.dispatchEvent(new CustomEvent('galerie-stret', { detail: { klice: klice.slice() } }));
    } catch (e) {}
  }

  function flush() {
    timer = null;
    if (inflight) { schedule(400); return; }
    var patch = pending;
    if (!Object.keys(patch).length) return;
    pending = {};

    if (mode === 'local') {
      rev += 1; writeLocal(); lastSync = new Date();
      if (bc) { try { bc.postMessage({ rev: rev }); } catch (e) {} }
      return;
    }

    inflight = true;
    fetch(base + '/state', { method: 'PATCH', headers: headers(), credentials: 'same-origin', body: JSON.stringify({ data: patch, rev: rev }) })
      .then(function (r) {
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
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (b) {
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
        // Offline nebo chyba serveru: patch se vrátí do fronty a zůstane
        // v localStorage. Prototyp funguje dál, jen nesynchronizuje.
        lastError = String(e && e.message || e);
        Object.keys(patch).forEach(function (k) { if (!(k in pending)) pending[k] = patch[k]; });
        writeLocal();
        schedule(4000);
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
  window.addEventListener('online', function () { schedule(200); notify(); });
  window.addEventListener('offline', notify);
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (e.data && e.data.type === 'galerie-sync-done') { queuedBySw = false; lastSync = new Date(); notify(); }
    });
  }
  window.addEventListener('beforeunload', function () {
    if (!Object.keys(pending).length) return;
    if (mode === 'local') { rev += 1; writeLocal(); return; }
    try {
      navigator.sendBeacon(base + '/state', new Blob([JSON.stringify({ data: pending, rev: rev, _method: 'PATCH' })], { type: 'application/json' }));
    } catch (e) {}
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
      return fetch(base + '/state', { headers: headers(), credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (b) {
          data = b.data || {}; rev = b.rev || 0; writeLocal(); lastSync = new Date(); notify();
          return snapshot();
        })
        .catch(function (e) { lastError = String(e && e.message || e); return snapshot(); });
    },

    // Zápis. Slučuje se lokálně hned, odesílá se s prodlevou (450 ms) v jednom PATCHi.
    save: function (patch) {
      if (!patch) return;
      if (!merge(patch)) return;
      Object.keys(patch).forEach(function (k) { pending[k] = patch[k]; });
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
    signIn: function (email, password, device) {
      if (mode !== 'http') return Promise.resolve(null);
      var url = (typeof window !== 'undefined' && window.GALERIE_TOKEN_URL) || '/sanctum/token';
      return fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, password: password, device_name: device || 'telefon' })
      }).then(function (r) {
        return r.json().then(function (b) {
          if (!r.ok) throw Object.assign(new Error(b.message || 'HTTP ' + r.status), { status: r.status, body: b });
          if (b.token) {
            window.GALERIE_API_TOKEN = b.token;
            try { localStorage.setItem('galerie.token', b.token); } catch (e) {}
          }
          return b;
        });
      });
    },

    signOut: function () {
      window.GALERIE_API_TOKEN = null;
      try { localStorage.removeItem('galerie.token'); } catch (e) {}
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/logout', { method: 'POST', headers: headers(), credentials: 'same-origin' }).catch(function () { return null; });
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
        return fetch(base + '/media', { method: 'POST', headers: hdr(), credentials: 'same-origin', body: fd })
          .then(function (r) { return r.status === 202 ? { status: 'queued' } : r.json(); });
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
        return fetch(base + '/media/chunk', { method: 'POST', headers: h, credentials: 'same-origin', body: part })
          .then(function (r) { if (!r.ok && r.status !== 202) throw new Error('HTTP ' + r.status); return send(i + 1); });
      };
      return send(0);
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
    download: function (path, filename) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, { headers: headers(), credentials: 'same-origin' })
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

    // Smazání jedné položky (fotka do koše). Stejná cesta jako post: v lokálním
    // režimu vrací null, aby volající poznal, že backend není.
    del: function (path) {
      if (mode !== 'http') return Promise.resolve(null);
      return fetch(base + '/' + path, {
        method: 'DELETE', headers: headers(), credentials: 'same-origin'
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
          window.GalerieMech = Object.assign({}, window.GalerieMech || {}, b.data);
          return b.data;
        })
        .catch(function () { return null; });
    },

    // Požádá service worker, ať zkusí frontu odeslat hned.
    flush: function () {
      schedule(0);
      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        try { navigator.serviceWorker.controller.postMessage({ type: 'galerie-flush' }); } catch (e) {}
      }
    }
  };
})();
