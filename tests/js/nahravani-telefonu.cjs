'use strict';
/*
 * Průchod nahráváním z telefonu nad skutečným `public/galerie-api.js`.
 *
 * Soubor se jen čte a pouští ve vlastním kontextu (`vm`) s falešným serverem,
 * který mluví stejným protokolem jako `MediaController::chunk`. Každý scénář
 * je chyba, kterou produkce opravdu umí vyrobit: nginx s malým
 * `client_max_body_size` (413 jako HTML), výpadek signálu uprostřed videa,
 * stránka 200 místo JSONu, server bez práva zápisu (507), zmizelé album.
 *
 * Spuštění: `node tests/js/nahravani-telefonu.cjs` — nenulový kód = chyba.
 * Pouští ho i tests/Unit/NahravaniVTelefonuTest.php.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

// `GALERIE_API_JS` jen pro srovnání se starší verzí (`git show HEAD:public/galerie-api.js`).
const ZDROJ = fs.readFileSync(process.env.GALERIE_API_JS || path.join(__dirname, '..', '..', 'public', 'galerie-api.js'), 'utf8');
const MB = 1024 * 1024;

function json(status, body, hlavicky) {
  return {
    ok: status >= 200 && status < 300, status,
    headers: { get: k => Object.assign({ 'content-type': 'application/json' }, hlavicky || {})[k.toLowerCase()] || null },
    json: () => Promise.resolve(body),
  };
}
function html(status, text) {
  return {
    ok: status >= 200 && status < 300, status,
    headers: { get: k => (k.toLowerCase() === 'content-type' ? 'text/html' : null) },
    json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON: ' + text.slice(0, 20))),
  };
}

/*
 * Falešný server: skládá části podle X-Upload-Id jako MediaController.
 * `pravidla.pred(pozadavek)` může vrátit vlastní odpověď (chyba, výpadek).
 */
function server(pravidla = {}) {
  const nahravani = {};
  const hotove = [];
  const log = [];
  let soubezne = 0, nejvicSoubezne = 0, dalsiId = 1;

  async function fetch(url, init = {}) {
    const h = init.headers || {};
    const pozadavek = { url, metoda: init.method || 'GET', h, velikost: init.body && init.body.size };
    log.push(pozadavek);
    soubezne++; nejvicSoubezne = Math.max(nejvicSoubezne, soubezne);
    try {
      await new Promise(r => setTimeout(r, 1));
      if (url.endsWith('/media/limity')) {
        return json(200, Object.assign({ cast: 8 * MB, nejvic: 32 * 1024 * MB, zapis: true, zprava: null }, pravidla.limity || {}));
      }
      if (!url.endsWith('/media/chunk')) return json(200, { data: {}, rev: 0 });
      if (pravidla.pred) {
        const vlastni = pravidla.pred(pozadavek);
        if (vlastni === 'sit') throw new TypeError('Failed to fetch');
        if (vlastni) return vlastni;
      }
      const id = h['X-Upload-Id'], i = +h['X-Chunk-Index'], n = +h['X-Chunk-Count'];
      const u = nahravani[id] || (nahravani[id] = { casti: {}, jmeno: decodeURIComponent(h['X-File-Name']) });
      u.casti[i] = init.body.size;
      const mame = Object.keys(u.casti).length;
      if (mame < n) {
        const odpoved = { id, received: mame, of: n, status: 'partial' };
        if (i === n - 1) odpoved.chybi = [...Array(n).keys()].filter(x => !(x in u.casti));
        return json(202, odpoved);
      }
      delete nahravani[id];
      const zaznam = { id: 'media-' + (dalsiId++), name: u.jmeno, status: 'stored', album: h['X-Album'] || undefined, takenAt: h['X-Taken-At'] };
      hotove.push(zaznam);
      return json(201, zaznam);
    } finally {
      soubezne--;
    }
  }
  return { fetch, log, hotove, nahravani, get nejvicSoubezne() { return nejvicSoubezne; } };
}

function nactiApi(fetch, token) {
  const noop = () => {};
  const uloziste = {};
  const window = {
    GALERIE_API_BASE: '/api',
    GALERIE_API_TOKEN: token || null,
    addEventListener: noop, removeEventListener: noop,
    location: { href: 'https://galerie.test/', origin: 'https://galerie.test' },
  };
  const kontext = {
    window, fetch, console, setTimeout, clearTimeout, setInterval: () => 0, clearInterval: noop,
    Promise, JSON, Math, Date, Object, Array, String, Number, Error, TypeError, SyntaxError, encodeURIComponent, decodeURIComponent, parseInt, isNaN,
    localStorage: { getItem: k => (k in uloziste ? uloziste[k] : null), setItem: (k, v) => { uloziste[k] = String(v); }, removeItem: k => { delete uloziste[k]; } },
    document: { querySelector: () => null, addEventListener: noop, visibilityState: 'visible', hidden: false },
    navigator: { onLine: true },
    // Jen kvůli srovnání se starší verzí, která malé soubory posílala formulářem.
    FormData: class { constructor() { this.size = 0; } append(k, v) { this.size += (v && v.size) || 0; } },
  };
  window.window = window;
  vm.createContext(kontext);
  vm.runInContext(ZDROJ, kontext, { filename: 'galerie-api.js' });
  const api = window.GalerieApi;
  api.prodlevyNahravani = [0, 0, 0];
  api.__okno = window;
  return api;
}

// Soubor, jak ho dá výběr v telefonu: `size`, `name`, `lastModified`, `slice`.
function soubor(jmeno, velikost, lastModified = 1756000000000) {
  return {
    name: jmeno, size: velikost, lastModified, type: 'image/jpeg',
    slice(od, do_) { return { size: Math.max(0, Math.min(do_, velikost) - od) }; },
  };
}

// Pole z kontextu `vm` mají jiný prototyp — porovnává se obsah, ne původ.
function stejne(skutecne, cekane) {
  assert.strictEqual(JSON.stringify(skutecne), JSON.stringify(cekane));
}

const scenare = [];
function scenar(nazev, fn) { scenare.push({ nazev, fn }); }

scenar('malá fotka jde po částech, s albem a datem pořízení', async () => {
  const s = server();
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([soubor('IMG_1.jpg', 3 * MB)], null, null, { album: 'alb-1', soubezne: 2 });
  assert.strictEqual(v.ulozeno, 1);
  stejne(v.selhalo, []);
  assert.strictEqual(v.media.length, 1);
  const casti = s.log.filter(p => p.url.endsWith('/media/chunk'));
  assert.strictEqual(casti.length, 1, 'fotka pod velikostí části je jeden požadavek');
  assert.strictEqual(casti[0].h['X-Album'], 'alb-1');
  assert.strictEqual(casti[0].h['X-Taken-At'], '1756000000000');
  assert.ok(s.log[0].url.endsWith('/media/limity'), 'limity se zjistí před dávkou');
  assert.strictEqual(v.mimoAlbum, 0);
});

scenar('413 jako HTML stránka nginxu zmenší části a soubor projde', async () => {
  const limit = 1 * MB; // nginx client_max_body_size 1m
  const s = server({ pred: p => (p.velikost > limit ? html(413, '<html>413 Request Entity Too Large</html>') : null) });
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([soubor('VID_1.mp4', 3 * MB + 5), soubor('IMG_2.jpg', 900 * 1024)], null, null, { soubezne: 1 });
  stejne(v.selhalo, []);
  assert.strictEqual(v.ulozeno, 2);
  const po = s.log.filter(p => p.url.endsWith('/media/chunk') && p.velikost <= limit);
  assert.ok(po.length >= 4, 'video po 1MB částech');
});

scenar('výpadek signálu uprostřed videa zopakuje jen tu část', async () => {
  let vypadek = 1;
  const s = server({ pred: p => (p.h['X-Chunk-Index'] === '1' && vypadek-- > 0 ? 'sit' : null) });
  const api = nactiApi(s.fetch);
  const prubeh = [];
  const v = await api.nahrajVse([soubor('VID_2.mp4', 20 * MB)], null, null, { onCast: (i, o, c) => prubeh.push([o, c]) });
  assert.strictEqual(v.ulozeno, 1);
  stejne(v.selhalo, []);
  const casti = s.log.filter(p => p.url.endsWith('/media/chunk'));
  assert.strictEqual(casti.length, 4, '3 části + 1 opakování');
  assert.strictEqual(new Set(casti.map(p => p.h['X-Upload-Id'])).size, 1, 'soubor se nezačíná znovu');
  stejne(prubeh[prubeh.length - 1], [20 * MB, 20 * MB]);
});

scenar('poslední část bez předchozích dopošle chybějící', async () => {
  let zahod = 1;
  // Server „ztratí" první část (odpoví 202, ale neuloží ji).
  const s = server({ pred: p => (p.h['X-Chunk-Index'] === '0' && zahod-- > 0 ? json(202, { status: 'partial', received: 0, of: 3 }) : null) });
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([soubor('VID_3.mp4', 20 * MB)]);
  assert.strictEqual(v.ulozeno, 1, JSON.stringify(v));
  assert.strictEqual(s.hotove.length, 1);
});

scenar('stránka 200 místo JSONu se nepočítá jako nahraná', async () => {
  const s = server({ pred: p => (p.h['X-File-Name'] === 'spatna.jpg' ? html(200, '<html>přihlášení</html>') : null) });
  const api = nactiApi(s.fetch);
  const polozky = {};
  const v = await api.nahrajVse([soubor('spatna.jpg', MB), soubor('dobra.jpg', MB)], null, (i, stav, zprava) => { polozky[i] = [stav, zprava]; });
  assert.strictEqual(v.ulozeno, 1);
  assert.strictEqual(v.selhalo.length, 1);
  assert.strictEqual(v.selhalo[0].i, 0);
  assert.match(v.selhalo[0].zprava, /nečekaně/);
  assert.strictEqual(polozky[0][0], 'selhalo');
  assert.strictEqual(polozky[1][0], 'hotovo');
});

scenar('server bez práva zápisu (507) zastaví dávku s jeho větou', async () => {
  const zprava = 'Server nemohl uložit nahrávaný soubor na disk.';
  const s = server({ pred: () => json(507, { message: zprava }) });
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([1, 2, 3, 4, 5].map(n => soubor('IMG_' + n + '.jpg', MB)), null, null, { soubezne: 1 });
  assert.strictEqual(v.selhalo.length, 5);
  assert.ok(v.selhalo.every(x => x.zprava === zprava));
  assert.strictEqual(s.log.filter(p => p.url.endsWith('/media/chunk')).length, 1, 'po 507 se už nic neposílá');
});

scenar('limity bez zápisu: nic se neposílá a dávka to řekne hned', async () => {
  const s = server({ limity: { zapis: false, zprava: 'Server nemůže zapisovat.' } });
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([soubor('a.jpg', MB), soubor('b.jpg', MB)]);
  assert.strictEqual(v.selhalo.length, 2);
  assert.strictEqual(v.selhalo[0].zprava, 'Server nemůže zapisovat.');
  assert.strictEqual(s.log.filter(p => p.url.endsWith('/media/chunk')).length, 0);
});

scenar('zmizelé album zastaví dávku', async () => {
  const s = server({ pred: () => json(422, { message: 'Album, do kterého se nahrává, už v galerii není. Založte ho znovu.' }) });
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([soubor('a.jpg', MB), soubor('b.jpg', MB), soubor('c.jpg', MB)], null, null, { album: 'x', soubezne: 1 });
  assert.strictEqual(v.selhalo.length, 3);
  assert.strictEqual(s.log.filter(p => p.url.endsWith('/media/chunk')).length, 1);
});

scenar('403 od firewallu (HTML) zastaví dávku, ale neodhlásí', async () => {
  const s = server({ pred: () => html(403, '<html>Blocked by WAF</html>') });
  const api = nactiApi(s.fetch, 'token-telefonu');
  const v = await api.nahrajVse([soubor('a.jpg', MB), soubor('b.jpg', MB)], null, null, { soubezne: 1 });
  assert.strictEqual(v.selhalo.length, 2);
  assert.match(v.selhalo[0].zprava, /firewall/);
  assert.strictEqual(api.__okno.GALERIE_API_TOKEN, 'token-telefonu', 'HTML 403 neposlala aplikace — přihlášení zůstává');
});

scenar('souběžně nejvýš tolik souborů, kolik se řekne', async () => {
  const s = server();
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([...Array(9).keys()].map(n => soubor('IMG_' + n + '.jpg', MB)), null, null, { soubezne: 2 });
  assert.strictEqual(v.ulozeno, 9);
  assert.ok(s.nejvicSoubezne <= 2, 'nejvíc souběžně: ' + s.nejvicSoubezne);
});

scenar('jednotlivá chyba dávku nezastaví a jméno se vrátí k opakování', async () => {
  const s = server({ pred: p => (p.h['X-File-Name'] === 'text.jpg' ? json(422, { message: 'Soubor „text.jpg“ není fotka ani video.' }) : null) });
  const api = nactiApi(s.fetch);
  const v = await api.nahrajVse([soubor('a.jpg', MB), soubor('text.jpg', MB), soubor('c.jpg', MB)], null, null, { album: 'alb', soubezne: 2 });
  assert.strictEqual(v.ulozeno, 2);
  stejne(v.selhalo.map(x => [x.jmeno, x.i]), [['text.jpg', 1]]);
});

(async () => {
  let chyb = 0;
  for (const { nazev, fn } of scenare) {
    try {
      await fn();
      console.log('ok   ' + nazev);
    } catch (e) {
      chyb++;
      console.log('CHYBA ' + nazev + '\n      ' + (e && e.message));
    }
  }
  console.log(chyb ? chyb + ' z ' + scenare.length + ' scénářů selhalo' : 'všech ' + scenare.length + ' scénářů prošlo');
  process.exit(chyb ? 1 : 0);
})();
