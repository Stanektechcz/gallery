// ——— Administrace: logika pro obě rozvržení ———
// Data jsou ve `GalerieData.ADMIN`, tenhle soubor je jediná implementace — obě
// rozvržení kreslí z týchž hodnot, takže se nemohou rozejít ve funkci, jen v tvaru.
//
// vals(c, key) → hodnoty pro jednu záložku administrace.
//   c   … komponenta (state, setState, toast, pl, kc)
//   key … users | health | jobs | risk | api | tarify
//
// ——— Napojení na server ———
// Administrace **není** místní model. Každé tlačítko volá `/api/admin/*`, server
// zásah provede a v odpovědi vrátí celý přehled; ten se uloží zpátky do
// `GalerieData.ADMIN` a obrazovka se překreslí z něj.
//
// Dřív se všechno jen zapisovalo do stavu komponenty. Po prvním kliknutí se pak
// obrazovka a databáze tiše rozešly: stav tvrdil, že Makinka je host, databáze
// že správce — a nikdo se to nedozvěděl. Odsud plyne pravidlo: **ve stavu
// komponenty se nedrží nic z administrace.** Jediná pravda je odpověď serveru.
//
// Bez backendu (`GalerieApi.mode !== 'http'`) tlačítka jen řeknou, že to nejde.
// Předstírat úspěch je horší než přiznat, že server není.
(function () {
  const D = () => window.GalerieData || {};
  const A = () => D().ADMIN || { roles: [], users: [], jobs: [], keys: [], plans: [], risks: [], incidents: [] };

  // Časy formátuje server — tady už nemá co vznikat.
  const tag = st => st === 'aktivní' || st === 'hotovo' ? 'tag-accent'
    : st === 'chyba' || st === 'zrušený' ? 'tag-accent-2'
    : st === 'běží' ? 'tag-outline' : 'tag-neutral';

  // Klíč k API server ukáže jednou a víc už nikdy — drží se tu do překreslení,
  // aby si ho člověk stihl zkopírovat.
  let cerstvyKlic = null;

  const jeServer = () => !!(window.GalerieApi && window.GalerieApi.mode === 'http');

  function hlavicky() {
    const h = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const m = document.querySelector('meta[name="csrf-token"]');
    if (m) h['X-CSRF-TOKEN'] = m.getAttribute('content');
    if (window.GALERIE_API_TOKEN) h['Authorization'] = 'Bearer ' + window.GALERIE_API_TOKEN;
    return h;
  }

  /**
   * Zavolá administrační endpoint a překreslí obrazovku z toho, co vrátil.
   *
   * Nic se nemění optimisticky. Obrazovka smí ukázat jen to, co server potvrdil —
   * u odebrání přístupu je omyl v opačném směru mnohem dražší než vteřina čekání.
   */
  function zavolej(c, cesta, telo, metoda) {
    if (!jeServer()) {
      c.toast('Administrace potřebuje server — teď běží jen v prohlížeči.', { icon: 'ph-plugs', local: true });
      return Promise.resolve(null);
    }

    return fetch('/api/admin' + cesta, {
      method: metoda || 'POST',
      headers: hlavicky(),
      credentials: 'same-origin',
      body: telo ? JSON.stringify(telo) : undefined
    })
      .then(r => r.json().then(b => ({ ok: r.ok, status: r.status, body: b })))
      .then(({ ok, status, body }) => {
        if (!ok) {
          // Hláška ze serveru, ne obecné „něco se pokazilo": u tarifu i u práv
          // je důvod to jediné, co s tím jde udělat.
          c.toast(body && body.message ? body.message : 'Zásah se nepodařilo provést (' + status + ').',
            { icon: 'ph-warning-circle' });
          return null;
        }
        if (body && body.data) {
          // Přes hlavičku stránky, pokud ji nasazení má: ta si drží serverovou
          // vrstvu a míchá ji nad výchozí data až při čtení. Zápis přímo do
          // `ADMIN` by se pod ní ztratil.
          if (typeof window.GalerieAdminZeServeru === 'function') window.GalerieAdminZeServeru(body.data);
          else window.GalerieData.ADMIN = Object.assign({}, A(), body.data);
          c.forceUpdate();
        }
        return body;
      })
      .catch(() => {
        c.toast('Server neodpověděl — zásah se neprovedl.', { icon: 'ph-wifi-slash' });
        return null;
      });
  }

  /**
   * Zapamatuje si čerstvý klíč a rovnou ho zkopíruje do schránky.
   *
   * Nový klíč je v odpovědi vždy tím posledním v seznamu; podle otisku se
   * v seznamu najde, aby se ukázal u správného řádku.
   */
  function zapamatujKlic(odpoved) {
    const token = odpoved.token;
    if (!token) return;

    const konec = token.slice(-4);
    const radek = (odpoved.data && odpoved.data.keys || []).find(k => k.suffix === konec);

    cerstvyKlic = { id: radek ? String(radek.id) : null, token: token };
    try { navigator.clipboard.writeText(token); } catch (e) {}
  }

  function vals(c, key) {
    const s = c.state, ad = A();
    const users = ad.users || [];
    const jobs = ad.jobs || [];
    const keys = ad.keys || [];
    const plan = ad.plan;
    // Které riziko je vyřešené, ví server; z jeho seznamu se udělá mapa,
    // aby zbytek souboru mohl zůstat, jak byl.
    const risk = {};
    (ad.risks || []).forEach(r => { if (r.hotovo) risk[r.id] = true; });
    const log = ad.log || [];

    // Zásah zapisuje do protokolu server — se jménem přihlášeného a tak, aby ho
    // viděl i ten druhý. Tady zbývá jen oznámení na obrazovce.
    const note = what => c.toast(what, { icon: 'ph-shield-check' });

    const base = {
      // Administrace má vlastní sazbu — obecné seznamy a grafy se pro ni nekreslí.
      appIsList: false, appIsStats: false, appIsTable: false, appIsGrid: false,
      // A vlastní akce u každé záložky, takže obecné „Filtrovat / Přidat“ v hlavičce nepatří.
      appHasSecondary: false, appHasPrimary: false,
      appIsAdmin: true, admKind: key,
      admIsUsers: key === 'users', admIsHealth: key === 'health', admIsJobs: key === 'jobs',
      admIsRisk: key === 'risk', admIsApi: key === 'api', admIsPlans: key === 'tarify',
      admLog: log.slice(0, 8),
      admLogHead: log.length
        ? c.pl(log.length, 'zápis v protokolu', 'zápisy v protokolu', 'zápisů v protokolu') + ' · vidí ho oba účty'
        : 'Protokol je prázdný — zapíše se sem každá změna v administraci.',
      admLogEmpty: !log.length
    };

    if (key === 'users') {
      const active = users.filter(u => u.state === 'aktivní').length;
      // Vlastník musí být právě jeden — platí tarif a jako jediný odebírá
      // přístup. Proto se jeho role necykluje: mění se jen předáním.
      const owner = users.find(u => u.role === 'vlastník') || null;
      return Object.assign(base, {
        admHead: c.pl(active, 'aktivní účet', 'aktivní účty', 'aktivních účtů') + ' z ' + users.length
          + ' · vlastník je ' + (owner ? owner.name : 'neurčený') + ', role určuje, co kdo uvidí',
        admUsers: users.map(u => {
          const isOwner = u.role === 'vlastník';
          // Ostatní role se cyklují jen mezi sebou, aby druhý vlastník nevznikl.
          const others = (ad.roles || []).filter(r => r !== 'vlastník');
          const next = isOwner ? u.role : others[(others.indexOf(u.role) + 1) % others.length];
          return {
            id: u.id, name: u.name, mail: u.mail, ini: (u.name || '?')[0],
            role: u.role, roleNote: (ad.roleNote || {})[u.role] || '',
            state: u.state, stateTag: tag(u.state),
            last: 'poslední přihlášení ' + u.last,
            isInvite: u.state === 'pozvaná',
            isOff: u.state === 'bez přístupu',
            isOwner: isOwner,
            nextRole: next,
            canCycle: !isOwner,
            // Předání vlastnictví je výslovná akce: nový vlastník jeden,
            // z předchozího se stane správce.
            canTransfer: !isOwner && u.state === 'aktivní' && !!owner,
            transfer: () => zavolej(c, '/users/' + u.id + '/transfer')
              .then(ok => ok && note('Vlastnictví předáno · ' + u.name + ' platí tarif, ' + (owner ? owner.name : '') + ' je správce')),
            cycleRole: () => {
              if (isOwner) { c.toast('Vlastník musí být právě jeden — nejdřív předejte vlastnictví.', { icon: 'ph-warning-circle' }); return; }
              zavolej(c, '/users/' + u.id + '/role', { role: next }, 'PATCH')
                .then(ok => ok && note(u.name + ' má nyní roli ' + next));
            },
            resend: () => zavolej(c, '/users/' + u.id + '/resend')
              .then(ok => ok && note('Pozvánka pro ' + u.name + ' odeslána znovu na ' + u.mail)),
            revoke: () => zavolej(c, '/users/' + u.id + '/access', { active: false })
              .then(ok => ok && note(u.name + ' už do galerie nemá přístup')),
            restore: () => zavolej(c, '/users/' + u.id + '/access', { active: true })
              .then(ok => ok && note('Přístup pro ' + u.name + ' obnoven')),
            canRevoke: u.role !== 'vlastník' && u.state !== 'bez přístupu'
          };
        }),
        admNewMail: s.admNewMail || '',
        admSetNewMail: e => c.setState({ admNewMail: e.target.value }),
        admInviteCant: !/.+@.+\..+/.test((s.admNewMail || '').trim()),
        admInvite: () => {
          const mail = (s.admNewMail || '').trim();
          if (!/.+@.+\..+/.test(mail)) return;
          zavolej(c, '/users', { email: mail, role: 'host' }).then(odpoved => {
            if (!odpoved) return;
            c.setState({ admNewMail: '' });
            note('Pozvánka odeslána na ' + mail);
            // Odkaz z pozvánky se ukáže i při úspěchu: e-mail končívá ve spamu
            // a tohle je jediná cesta, jak ho předat rovnou.
            if (odpoved.invite_url) {
              try { navigator.clipboard.writeText(odpoved.invite_url); } catch (e) {}
              c.toast('Odkaz z pozvánky zkopírován — kdyby e-mail nedorazil.', { icon: 'ph-link' });
            }
          });
        }
      });
    }

    if (key === 'health') {
      // Pruhy ze serveru: místo na disku, zaplněnost tarifu, tep plánovače,
      // fronta úloh. Dřív to byla čtveřice z ukázkových dat, takže obrazovka
      // hlásila dostupnost 99,98 % i na serveru, který zrovna neběžel.
      const health = ad.health || {};
      const bars = health.bars || ((D().ABARS || {}).health || []);
      const inc = ad.incidents || [];
      const failed = jobs.filter(j => j.state === 'chyba').length;
      return Object.assign(base, {
        admHead: (failed ? c.pl(failed, 'úloha hlásí chybu', 'úlohy hlásí chybu', 'úloh hlásí chybu') : 'Všechny úlohy prošly')
          + ' · poslední kontrola ' + (health.checkedAt || 'zatím nikdy'),
        admBars: bars.map(b => ({
          label: b[0], meta: b[1], pct: b[2] + ' %', w: Math.min(100, b[2]) + '%',
          color: b[3] === 1 ? 'var(--g-mag)' : b[3] === 2 ? 'var(--g-ink3)' : 'var(--g-acc)'
        })),
        admIncidents: inc.map(i => ({ when: i.when, what: i.what, fix: i.fix })),
        admIncHead: inc.length ? c.pl(inc.length, 'incident za 30 dní', 'incidenty za 30 dní', 'incidentů za 30 dní') : 'Za třicet dní žádný incident',
        admCheckLabel: s.admChecking ? 'Kontroluji…' : 'Spustit kontrolu teď',
        admChecking: !!s.admChecking,
        admCheck: () => {
          if (s.admChecking) return;
          c.setState({ admChecking: true });
          // Skutečná kontrola: server zařadí `gallery:doctor`, který projde disk,
          // databázi, frontu i připojený cloud. Trvá to déle než vteřina, kterou
          // tu dřív odpočítával `setTimeout` — proto se čeká na odpověď.
          zavolej(c, '/health/check').then(odpoved => {
            c.setState({ admChecking: false });
            if (odpoved) note('Kontrola systému zařazena · ' + ((odpoved.data && odpoved.data.health || {}).summary || ''));
          });
        }
      });
    }

    if (key === 'jobs') {
      // Pozastavení i stav úlohy říká server; `admJobPause` ve stavu komponenty
      // zmizelo, protože dvě odpovědi na tutéž otázku se dřív nebo později
      // rozejdou — a rozejít se můžou jen ve prospěch chybné.
      const stoji = j => j.state === 'pozastavená';
      return Object.assign(base, {
        admHead: c.pl(jobs.filter(j => !stoji(j)).length, 'úloha je naplánovaná', 'úlohy jsou naplánované', 'úloh je naplánovaných')
          + ' · běží v noci, aby nebrzdily nahrávání',
        admJobs: jobs.map(j => ({
          id: j.id, name: j.name, cron: j.cron,
          meta: 'poslední běh ' + j.last + ' · ' + j.dur,
          state: j.state, stateTag: tag(j.state),
          running: j.state === 'běží',
          runLabel: j.state === 'běží' ? 'Běží…' : 'Spustit teď',
          // Úloha se zařadí do fronty. Držet prohlížeč otevřený, dokud noční
          // záloha nedoběhne, by skončilo na časovém limitu serveru.
          run: () => {
            if (j.state === 'běží') return;
            zavolej(c, '/jobs/' + encodeURIComponent(j.id) + '/run')
              .then(ok => ok && note('Úloha „' + j.name + '“ zařazena ke spuštění'));
          },
          paused: stoji(j),
          pauseLabel: stoji(j) ? 'Obnovit plán' : 'Pozastavit',
          toggle: () => zavolej(c, '/jobs/' + encodeURIComponent(j.id) + '/pause')
            .then(ok => ok && note(stoji(j) ? 'Úloha „' + j.name + '“ je zpět v plánu' : 'Úloha „' + j.name + '“ pozastavena'))
        }))
      });
    }

    if (key === 'risk') {
      const risks = ad.risks || [];
      const open = risks.filter(r => !risk[r.id]);
      /*
       * Gigabajty jsou ze serveru, ne z kódu.
       *
       * Dřív tu stálo `trashGb = 1.2, singleGb = 8.1, growthGb = 2.4` — obrazovka
       * tedy hlásila cizí čísla bez ohledu na to, co v galerii doopravdy je,
       * a „předpověď zaplnění" byla vypočtená z růstu, který nikdo neměřil.
       *
       * `usedGb` už koš nezapočítává (server ho sčítá zvlášť), takže se od něj
       * po vysypání nic neodečítá — ubude sám.
       */
      const plans = ad.plans || [];
      const capacity = (plans.find(p => p.id === plan) || plans[0] || { gb: 200 }).gb;
      const singleGb = ad.singleGb || 0;
      const growthGb = ad.growthGb || 0;
      const used = ad.usedGb || 0;
      const pct = v => Math.max(0, Math.min(100, Math.round(v)));
      const gb = v => String(Math.round(v * 10) / 10).replace('.', ',') + ' GB';
      // Bez růstu se zaplnění nedá předpovědět; místo vymyšleného čísla se to řekne.
      const months = growthGb > 0 ? Math.max(1, Math.round((capacity - used) / growthGb)) : null;
      const zaloha = risks.find(r => r.id === 'r3');
      const bars = [
        ['Zaplněnost úložiště', gb(used) + ' z ' + capacity + ' GB', pct((used / capacity) * 100), (used / capacity) > 0.85 ? 1 : 0],
        ['Originály jen v jedné kopii', singleGb > 0 ? gb(singleGb) + ' · riziko' : 'nic — vše je ve dvou kopiích',
          singleGb > 0 && used > 0 ? pct((singleGb / used) * 100) : 0, singleGb > 0 ? 1 : 2],
        ['Poslední záloha', (zaloha && zaloha.note) || 'bez testu obnovy', risk.r3 ? 100 : 60, risk.r3 ? 0 : 1],
        // Horizont je urgence, ne přesné číslo: nad rok se nepočítá na měsíce
        // (nikdo nečte „za 369 měsíců“) a pruh má podlahu, aby nebyl nulový.
        ['Předpověď zaplnění',
          months === null
            ? 'zatím se nedá odhadnout — za půl roku nic nepřibylo'
            : (months <= 12 ? 'za ' + c.pl(months, 'měsíc', 'měsíce', 'měsíců') : months <= 36 ? 'za víc než rok' : 'za víc než tři roky')
              + ' při ' + gb(growthGb) + ' měsíčně',
          months === null ? 6 : pct(Math.max(6, 100 - (Math.min(months, 36) / 36) * 100)),
          months !== null && months <= 6 ? 1 : 2]
      ];
      return Object.assign(base, {
        admHead: open.length
          ? c.pl(open.length, 'riziko čeká na opravu', 'rizika čekají na opravu', 'rizik čeká na opravu')
            + ' · ' + gb(used) + ' z ' + capacity + ' GB využito'
          : 'Všechna rizika vyřešená · originály jsou ve dvou kopiích a obnova je ověřená',
        admBars: bars.map(b => ({
          label: b[0], meta: b[1], pct: b[2] + ' %', w: Math.min(100, b[2]) + '%',
          color: b[3] === 1 ? 'var(--g-mag)' : b[3] === 2 ? 'var(--g-ink3)' : 'var(--g-acc)'
        })),
        admRisks: risks.map(r => ({
          id: r.id, label: r.label,
          note: risk[r.id] ? r.done : r.note,
          done: !!risk[r.id],
          fixLabel: risk[r.id] ? 'Vyřešeno' : r.fix,
          fix: () => {
            if (risk[r.id]) return;
            zavolej(c, '/risks/' + encodeURIComponent(r.id) + '/fix')
              .then(ok => ok && note(r.label + ' — ' + r.fix.toLowerCase()));
          }
        }))
      });
    }

    if (key === 'api') {
      const active = keys.filter(k => k.state !== 'zrušený');

      /*
       * Klíč se ukáže jednou.
       *
       * Server ho ukládá jen jako otisk, takže ho podruhé nemá odkud vzít — a to
       * je správně. Dřív se tady celý klíč **skládal na klientovi**
       * (`'gal_' + id + suffix`), takže tlačítko „Kopírovat" podávalo řetězec,
       * který nikde neplatil: vypadalo to, že klíč funguje, a nefungoval.
       *
       * Čerstvý klíč se proto drží v paměti do odchodu z obrazovky a řádek u něj
       * ukazuje celou hodnotu; u ostatních zůstávají poslední čtyři znaky.
       */
      const cely = k => (cerstvyKlic && cerstvyKlic.id === String(k.id)) ? cerstvyKlic.token : null;

      return Object.assign(base, {
        admHead: c.pl(active.length, 'platný klíč', 'platné klíče', 'platných klíčů')
          + ' · klíč vidíte jen při vytvoření, potom už jen jeho konec',
        admKeys: keys.map(k => ({
          id: k.id, name: k.name, key: cely(k) || ('…' + k.suffix), scope: k.scope,
          meta: cely(k)
            ? 'vytvořen právě teď · zkopírujte si ho, podruhé se neukáže'
            : 'vytvořen ' + k.made + ' · ' + k.used,
          state: k.state, stateTag: tag(k.state),
          active: k.state !== 'zrušený',
          revoke: () => zavolej(c, '/keys/' + encodeURIComponent(k.id), null, 'DELETE')
            .then(ok => ok && note('Klíč „' + k.name + '“ zrušen — aplikace se odhlásí do minuty')),
          regen: () => zavolej(c, '/keys/' + encodeURIComponent(k.id) + '/regenerate').then(odpoved => {
            if (!odpoved) return;
            zapamatujKlic(odpoved);
            note('Klíč „' + k.name + '“ vygenerován znovu · zkopírujte si ho, podruhé se neukáže');
          }),
          copy: () => {
            const hodnota = cely(k);
            if (!hodnota) {
              // Přiznat, že klíč nemáme, je lepší než podat něco, co neplatí.
              c.toast('Celý klíč už není k dispozici — vygenerujte ho znovu.', { icon: 'ph-warning-circle' });
              return;
            }
            try { navigator.clipboard.writeText(hodnota); } catch (e) {}
            c.toast('Klíč „' + k.name + '“ zkopírován', { icon: 'ph-copy' });
          }
        })),
        admNewKeyName: s.admNewKeyName || '',
        admSetNewKeyName: e => c.setState({ admNewKeyName: e.target.value }),
        admNewKeyCant: !(s.admNewKeyName || '').trim(),
        admNewKey: () => {
          const name = (s.admNewKeyName || '').trim();
          if (!name) return;
          zavolej(c, '/keys', { name: name, scope: 'čtení i zápis' }).then(odpoved => {
            if (!odpoved) return;
            zapamatujKlic(odpoved);
            c.setState({ admNewKeyName: '' });
            note('Klíč „' + name + '“ vytvořen · zkopírujte si ho, podruhé se neukáže');
          });
        }
      });
    }

    // Tarify
    const plans = ad.plans || [];
    const cur = plans.find(p => p.id === plan) || plans[0] || { id: '', name: '—', gb: 0, price: 0 };
    // Obsazenost se řídí týmž číslem jako záložka Riziko úložiště — koš v něm
    // není, server ho sčítá zvlášť.
    const usedNow = ad.usedGb || 0;
    return Object.assign(base, {
      admHead: 'Tarif ' + cur.name + ' · ' + c.kc(cur.price) + ' měsíčně · využito '
        + String(Math.round(usedNow * 10) / 10).replace('.', ',') + ' GB z ' + cur.gb + ' GB',
      admPlans: plans.map(p => {
        const d = p.price - cur.price;
        return {
          id: p.id, name: p.name,
          meta: c.kc(p.price) + ' měsíčně · ' + (p.gb >= 1000 ? (p.gb / 1000) + ' TB' : p.gb + ' GB'),
          fill: Math.min(100, Math.round((usedNow / p.gb) * 100)) + '%',
          fillLabel: Math.min(100, Math.round((usedNow / p.gb) * 100)) + ' % zaplněno',
          cur: p.id === cur.id,
          state: p.id === cur.id ? 'aktivní' : 'zařadit', stateTag: tag(p.id === cur.id ? 'aktivní' : 'zařadit'),
          delta: p.id === cur.id ? 'Tenhle tarif teď platíte' : d > 0 ? 'o ' + c.kc(d) + ' měsíčně víc' : 'o ' + c.kc(-d) + ' měsíčně méně',
          /*
           * Placený tarif se **kupuje**, nepřiděluje.
           *
           * Dřív tlačítko jen přepsalo stav a obrazovka tvrdila, že tarif platí
           * od příštího období — aniž by se kdokoli něčeho dotkl na účtu. Server
           * u placeného tarifu vrátí adresu platební brány a jde se tam.
           */
          pick: () => {
            if (p.id === cur.id) return;
            zavolej(c, '/plan', { plan: p.id, period: 'monthly' }).then(odpoved => {
              if (!odpoved) return;
              if (odpoved.redirect) {
                c.toast('Tarif ' + p.name + ' · přesměrování na platbu', { icon: 'ph-credit-card' });
                window.location.href = odpoved.redirect;
                return;
              }
              // Hlásit „změněno" se smí jen tehdy, když to server potvrdil.
              // U placeného tarifu se změna stane až po zaplacení.
              const platny = odpoved.data && String(odpoved.data.plan) === String(p.id);
              note(platny
                ? 'Tarif změněn na ' + p.name + ' · ' + c.kc(p.price) + ' měsíčně'
                : 'Tarif ' + p.name + ' objednán · začne platit po zaplacení');
            });
          }
        };
      })
    });
  }

  window.GalerieAdmin = { vals: vals };
})();
