// Společná datová vrstva Galerie — sdílí stav mezi desktopovou verzí, mobilní aplikací
// a hostitelskou obrazovkou sdílených odkazů.
//   mapové klíče (favs, txCat, visited) se slučují po jednotlivých id,
//   seznamové klíče (shares, guestMsgs) se přepisují celé — zapisuje ten, kdo je právě mění.
(function () {
  var KEY = 'galerie.shared.v2';
  var MAP_KEYS = ['favs', 'txCat', 'visited'];
  var LIST_KEYS = ['shares', 'guestMsgs'];
  var KEYS = MAP_KEYS.concat(LIST_KEYS);
  var subs = [];
  var bc = null;

  function load() {
    try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; }
  }
  var data = load();

  function snapshot() {
    var o = {};
    MAP_KEYS.forEach(function (k) { o[k] = Object.assign({}, data[k] || {}); });
    LIST_KEYS.forEach(function (k) { o[k] = (data[k] || []).slice(); });
    return o;
  }
  function set(patch) {
    if (!patch) return;
    var changed = false;
    MAP_KEYS.forEach(function (k) {
      if (patch[k]) { data[k] = Object.assign({}, data[k] || {}, patch[k]); changed = true; }
    });
    LIST_KEYS.forEach(function (k) {
      if (patch[k]) { data[k] = patch[k].slice(); changed = true; }
    });
    if (!changed) return;
    try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) {}
    if (bc) { try { bc.postMessage(Date.now()); } catch (e) {} }
    push();
  }
  // Se nasazeným backendem jde týž obsah do /api/state pod klíčem `shared`,
  // takže oblíbené, sdílené odkazy, kategorie a navštívená místa nezůstávají
  // jen v prohlížeči jednoho zařízení. Bez backendu se nic nemění.
  function push() {
    var api = window.GalerieApi;
    if (!api || api.mode !== 'http' || !api.save) return;
    try { api.save({ shared: JSON.parse(JSON.stringify(data)) }); } catch (e) {}
  }
  function adopt(next) {
    var shared = next && next.shared;
    if (!shared) return;
    var changed = false;
    KEYS.forEach(function (k) {
      if (shared[k] === undefined) return;
      // Mapa, kterou server (PHP) vrátil jako pole — `{}` jako `[]`, `{"0":"x"}`
      // jako `["x"]`. Obsah je týž; porovnávat tvar znamenalo zapsat znovu
      // a při každém dvacetivteřinovém načtení stavu poslat dva PATCHe.
      var prisla = MAP_KEYS.indexOf(k) >= 0 && Array.isArray(shared[k]) ? Object.assign({}, shared[k]) : shared[k];
      if (JSON.stringify(prisla) === JSON.stringify(data[k])) return;
      data[k] = prisla;
      changed = true;
    });
    if (!changed) return;
    try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) {}
    var snap = snapshot();
    subs.forEach(function (fn) { fn(snap); });
  }
  function refresh() {
    data = load();
    var snap = snapshot();
    subs.forEach(function (fn) { fn(snap); });
  }

  try { bc = new BroadcastChannel('galerie.shared'); bc.onmessage = refresh; } catch (e) {}
  window.addEventListener('storage', function (e) { if (e.key === KEY) refresh(); });
  // Stav ze serveru přebíráme, jakmile dorazí (i později, když ho změní druhé
  // zařízení). Datová vrstva se načítá až za tímhle souborem, proto až na tik.
  setTimeout(function () {
    var api = window.GalerieApi;
    if (!api || !api.subscribe) return;
    adopt(api.loadSync ? api.loadSync() : null);
    api.subscribe(adopt);
  }, 0);

  // Identita platby je její název, ne id řádku — každý soubor má vlastní datovou sadu.
  function txKey(name) {
    return String(name).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }
  // Slovníky kategorií se mezi soubory liší („Restaurace“ vs „Restaurace a kavárny“) —
  // porovnáváme první slovo bez diakritiky.
  function catSlug(name) { return txKey(name).split('-')[0]; }

  window.GalerieStore = {
    KEYS: KEYS,
    txKey: txKey,
    catSlug: catSlug,
    MAP_KEYS: MAP_KEYS,
    LIST_KEYS: LIST_KEYS,
    get: snapshot,
    set: set,
    subscribe: function (fn) {
      subs.push(fn);
      return function () { subs = subs.filter(function (x) { return x !== fn; }); };
    }
  };
})();
