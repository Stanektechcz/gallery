// ——— Service worker aplikace Naše vzpomínky ———
// Tři úlohy: (1) držet skořápku aplikace offline, (2) data z Laravelu podávat
// „nejdřív ze sítě, pak z paměti", (3) doručit zápisy, které vznikly bez
// signálu — přes Background Sync, i když je aplikace zavřená.
const VERSION = 'v4';
const SHELL = 'galerie-shell-' + VERSION;
const DATA = 'galerie-data-' + VERSION;
const QUEUE_DB = 'galerie-queue';

// Skořápka: dokument, moduly a fonty. Bez nich by aplikace offline nenaběhla.
// Do skořápky patří jen to, co se nemění při každé editaci: náhradní obrazovka,
// manifest a ikony. Autorské soubory (dokument, support.js, galerie-*.js) se
// berou vždycky ze sítě a do paměti se ukládají jen jako záložka pro offline —
// jinak by uživatel po každé změně viděl o jedno načtení starší verzi.
const SHELL_FILES = [
  'offline.html',
  'manifest.webmanifest',
  'icons/icon.svg',
  'icons/icon-192.png',
  'icons/icon-512.png'
];
// Soubory, které se za vývoje mění — network-first. `mapa.html` taky: oprava
// v ní (zprávy jen od galerie) nesmí čekat jedno načtení na starou kopii z paměti.
const LIVE_RE = /(\.dc\.html|support\.js|galerie-[\w-]+\.js|image-slot\.js|sw\.js|mapa\.html)$/;

self.addEventListener('install', e => {
  e.waitUntil((async () => {
    const c = await caches.open(SHELL);
    // Jednotlivě, aby jeden chybějící soubor neshodil celou instalaci.
    await Promise.all(SHELL_FILES.map(f => c.add(new Request(f, { cache: 'reload' })).catch(() => {})));
    self.skipWaiting();
  })());
});

self.addEventListener('activate', e => {
  e.waitUntil((async () => {
    const keep = [SHELL, DATA];
    const names = await caches.keys();
    await Promise.all(names.filter(n => keep.indexOf(n) < 0).map(n => caches.delete(n)));
    await self.clients.claim();
  })());
});

// ——— fronta zápisů (IndexedDB, aby přežila zavření aplikace) ———
//
// Zápis patří účtu, který ho napsal: aplikace posílá autora v těle (`ucet`)
// a worker si ho čte odtamtud. Fronta dřív nesla jen hlavičky a „Odeslat"
// do nich dosadilo token toho, kdo byl přihlášený **teď** — takže co napsala
// ona bez signálu, po jejím odhlášení a jeho přihlášení odešlo do jeho
// galerie a pod jeho jménem.
//
// A na jednu adresu a jeden účet je ve frontě jediná položka. Každá změna
// bez signálu byla dřív vlastní položka se stejnou, starou revizí: po návratu
// signálu prošla první, druhá narazila na střet s tou první a novější hodnota
// se zahodila, aniž by se to kdokoli dozvěděl.
function idb() {
  return new Promise((res, rej) => {
    const r = indexedDB.open(QUEUE_DB, 1);
    r.onupgradeneeded = () => r.result.createObjectStore('patches', { autoIncrement: true });
    r.onsuccess = () => {
      const db = r.result;
      // Odhlášení frontu maže (`deleteDatabase`) a otevřené spojení by ho zdrželo.
      db.onversionchange = () => db.close();
      res(db);
    };
    r.onerror = () => rej(r.error);
  });
}
// Kdo zápis napsal — `null` u zápisů z doby, kdy to aplikace ještě neposílala.
function autorZapisu(telo) {
  try {
    const u = JSON.parse(telo).ucet;
    return u === undefined || u === null || u === '' ? null : String(u);
  } catch (e) { return null; }
}
function tokenZapisu(hlavicky) {
  const k = Object.keys(hlavicky || {}).find(h => h.toLowerCase() === 'authorization');
  return k ? hlavicky[k] : null;
}
// Dva zápisy v jeden. Novější klíč přepíše starší; rozdíly seznamů se slučují
// jako ve frontě aplikace (`save` a `vratDoFronty` v galerie-api.js). Revize
// zůstává **nejstarší**: od ní se počítá, co mezitím změnil ten druhý.
function slozZapisy(starsi, novejsi) {
  const a = (starsi && starsi.data) || {};
  const b = (novejsi && novejsi.data) || {};
  const data = Object.assign({}, a);
  const mapa = v => !!v && typeof v === 'object' && !Array.isArray(v);
  Object.keys(b).forEach(k => {
    if (k === '__zmenene' && mapa(a[k]) && mapa(b[k])) {
      const spojene = Object.assign({}, a[k]);
      Object.keys(b[k]).forEach(s => {
        spojene[s] = Array.isArray(spojene[s]) && Array.isArray(b[k][s])
          ? spojene[s].concat(b[k][s].filter(id => spojene[s].indexOf(id) < 0))
          : b[k][s];
      });
      data[k] = spojene;
    } else if ((k === '__odebrane' || k === 'xRows') && mapa(a[k]) && mapa(b[k])) {
      data[k] = Object.assign({}, a[k], b[k]);
    } else {
      data[k] = b[k];
    }
  });
  const revize = [starsi && starsi.rev, novejsi && novejsi.rev].filter(r => typeof r === 'number');
  return Object.assign({}, novejsi, { data: data, rev: revize.length ? Math.min.apply(null, revize) : null });
}
async function queuePush(item) {
  const db = await idb();
  const autor = autorZapisu(item.body);
  return new Promise(res => {
    const tx = db.transaction('patches', 'readwrite');
    const st = tx.objectStore('patches');
    let slouceno = false;
    st.openCursor().onsuccess = ev => {
      const cur = ev.target.result;
      if (!cur) { if (!slouceno) st.add(item); return; }
      const it = cur.value;
      // Bez autora se slučuje jen se zápisem pod týmž tokenem — účet se tu nedá poznat jinak.
      const tentyz = it.url === item.url && autorZapisu(it.body) === autor
        && (autor !== null || tokenZapisu(it.headers) === tokenZapisu(item.headers));
      if (!slouceno && tentyz) {
        try {
          const telo = slozZapisy(JSON.parse(it.body), JSON.parse(item.body));
          cur.update(Object.assign({}, item, { body: JSON.stringify(telo) }));
          slouceno = true;
        } catch (e) {}
      }
      cur.continue();
    };
    tx.oncomplete = tx.onabort = () => { db.close(); res(); };
  });
}
async function queueAll() {
  const db = await idb();
  return new Promise(res => {
    const tx = db.transaction('patches', 'readwrite');
    const st = tx.objectStore('patches');
    const out = [];
    st.openCursor().onsuccess = ev => {
      const cur = ev.target.result;
      if (cur) { out.push({ key: cur.key, body: cur.value }); cur.continue(); }
    };
    tx.oncomplete = tx.onabort = () => { db.close(); res(out); };
  });
}
async function queueDrop(key) {
  const db = await idb();
  return new Promise(res => {
    const tx = db.transaction('patches', 'readwrite');
    tx.objectStore('patches').delete(key);
    tx.oncomplete = tx.onabort = () => { db.close(); res(); };
  });
}
// `auth` a `ucet` posílá aplikace při „Odeslat": aktuální přihlášení a čí je.
// Bez nich (Background Sync se zavřenou aplikací) jde každý zápis se svým tokenem.
async function flush(auth, ucet) {
  const items = await queueAll();
  const strety = {};
  let doruceno = 0;
  for (const it of items) {
    const zapsal = autorZapisu(it.body.body);
    // Token z aplikace jen pro zápis téhož účtu; cizí zápis jde s tím svým.
    const svuj = !!auth && !!ucet && zapsal === ucet;
    const cizi = !!ucet && zapsal !== ucet;
    try {
      const hlavicky = Object.assign({}, it.body.headers);
      if (svuj) {
        Object.keys(hlavicky).forEach(k => { if (k.toLowerCase() === 'authorization') delete hlavicky[k]; });
        hlavicky['Authorization'] = auth;
      }
      // Zápis bez autora bez cookie: jinak by server poznal přihlášeného, ne pisatele.
      const r = await fetch(it.body.url, { method: 'PATCH', headers: hlavicky, body: it.body.body, credentials: zapsal ? 'same-origin' : 'omit' });
      // Natrvalo odmítnutý zápis ven z fronty — jinak by se posílal při každém probuzení.
      // 401/403 je natrvalo s čerstvým tokenem téhož účtu, a u cizího zápisu,
      // jehož vlastní token skončil: pod jiným účtem už neodejde nikdy.
      const odmitnuto = r.status === 400 || r.status === 413 || r.status === 422
        || ((svuj || cizi) && (r.status === 401 || r.status === 403));
      if (r.ok || r.status === 409) {
        if (r.ok) doruceno++;
        // Co mezitím změnil ten druhý, musí aplikace říct — ne to tiše zahodit.
        const b = await r.json().catch(() => null);
        if (b && Array.isArray(b.strety) && b.strety.length) strety[zapsal || ''] = (strety[zapsal || ''] || []).concat(b.strety);
      }
      if (r.ok || r.status === 409 || odmitnuto) await queueDrop(it.key);
    } catch (e) { return; } // pořád offline — zkusí se při dalším sync
  }
  const cs = await self.clients.matchAll();
  cs.forEach(c => c.postMessage({ type: 'galerie-sync-done', doruceno: doruceno, strety: strety }));
}

self.addEventListener('sync', e => { if (e.tag === 'galerie-patch') e.waitUntil(flush()); });
self.addEventListener('periodicsync', e => { if (e.tag === 'galerie-refresh') e.waitUntil(flush()); });
self.addEventListener('message', e => {
  if (e.data && e.data.type === 'galerie-flush') e.waitUntil(flush(e.data.auth || null, e.data.ucet ? String(e.data.ucet) : null));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Zápisy do API: offline je uložíme do fronty a slíbíme doručení.
  if (req.method === 'PATCH' && url.pathname.indexOf('/api/state') >= 0) {
    e.respondWith((async () => {
      const clone = req.clone();
      try {
        return await fetch(req);
      } catch (err) {
        const body = await clone.text();
        const headers = {};
        clone.headers.forEach((v, k) => { headers[k] = v; });
        await queuePush({ url: req.url, headers: headers, body: body });
        try { await self.registration.sync.register('galerie-patch'); } catch (e2) {}
        return new Response(JSON.stringify({ queued: true, rev: null }), { status: 202, headers: { 'Content-Type': 'application/json' } });
      }
    })());
    return;
  }
  if (req.method !== 'GET') return;

  // Data z API: nejdřív síť, kopie do paměti; offline se podá poslední známý stav.
  if (url.pathname.indexOf('/api/') >= 0) {
    e.respondWith((async () => {
      try {
        const r = await fetch(req);
        const c = await caches.open(DATA);
        c.put(req, r.clone());
        return r;
      } catch (err) {
        const hit = await caches.match(req);
        return hit || new Response(JSON.stringify({ data: {}, rev: 0, offline: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      }
    })());
    return;
  }

  // Dokument a autorské soubory: nejdřív síť, paměť je jen záložka pro offline.
  // Klíč do paměti je URL bez dotazu, aby se verzované adresy nehromadily —
  // ale čte se z něj teprve tehdy, když síť selže.
  const live = req.mode === 'navigate' || LIVE_RE.test(url.pathname);
  if (live) {
    e.respondWith((async () => {
      const bare = new Request(url.origin + url.pathname, { credentials: 'same-origin' });
      try {
        const r = await fetch(req, { cache: 'no-store' });
        if (r.ok && url.pathname.indexOf('/sw.js') < 0) {
          const c = await caches.open(SHELL);
          c.put(bare, r.clone());
        }
        return r;
      } catch (err) {
        const hit = await caches.match(bare);
        if (hit) return hit;
        if (req.mode === 'navigate') {
          const off = await caches.match('offline.html');
          if (off) return off;
        }
        return new Response('', { status: 504 });
      }
    })());
    return;
  }

  // Zbytek (ikony, manifest, fonty, design systém): nejdřív z paměti.
  e.respondWith((async () => {
    const hit = await caches.match(req, { ignoreSearch: true });
    if (hit) {
      fetch(req).then(r => { if (r.ok) caches.open(SHELL).then(c => c.put(req, r)); }).catch(() => {});
      return hit;
    }
    try {
      const r = await fetch(req);
      if (r.ok) {
        const c = await caches.open(SHELL);
        c.put(req, r.clone());
      }
      return r;
    } catch (err) {
      return new Response('', { status: 504 });
    }
  })());
});

// ——— Upozornění ———
// Chodí jen dvojí: vypršelá domluva a revize rozhodnutí. Server posílá
// { title, body, route } — route otevře přímo tu obrazovku, ne úvod.
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  const title = d.title || 'Naše vzpomínky';
  e.waitUntil(self.registration.showNotification(title, {
    body: d.body || '',
    icon: 'icons/icon-192.png',
    badge: 'icons/icon-192.png',
    tag: d.tag || 'galerie',
    data: { route: d.route || null },
    requireInteraction: false
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const route = (e.notification.data || {}).route;
  const target = 'Galerie%20mobil%20aplikace.dc.html' + (route ? '#' + route : '');
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.indexOf('Galerie') >= 0) {
        if (route) c.postMessage({ type: 'galerie-open', route: route });
        return c.focus();
      }
    }
    return self.clients.openWindow(target);
  })());
});
