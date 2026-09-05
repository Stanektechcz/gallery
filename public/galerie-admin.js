// ——— Administrace: logika pro obě rozvržení ———
// Data jsou v galerie-data.js (GalerieData.ADMIN), stav v komponentě pod klíči
// admUsers / admJobs / admKeys / admPlan / admRisk / admLog. Tenhle soubor je
// jediná implementace — obě rozvržení kreslí z týchž hodnot, takže se nemohou
// rozejít ve funkci, jen ve tvaru.
//
// vals(c, key) → hodnoty pro jednu záložku administrace.
//   c   … komponenta (state, setState, toast, pl, kc)
//   key … users | health | jobs | risk | api | tarify
(function () {
  const D = () => window.GalerieData || {};
  const A = () => D().ADMIN || { roles: [], users: [], jobs: [], keys: [], plans: [], risks: [], incidents: [] };

  const now = () => {
    const d = new Date();
    return d.getDate() + '. ' + (d.getMonth() + 1) + '. ' + d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0');
  };
  const tag = st => st === 'aktivní' || st === 'hotovo' ? 'tag-accent'
    : st === 'chyba' || st === 'zrušený' ? 'tag-accent-2'
    : st === 'běží' ? 'tag-outline' : 'tag-neutral';

  function vals(c, key) {
    const s = c.state, ad = A();
    const who = ((D().LOCKWHO || {})[s.lockWho || 'A']) || '';
    const users = s.admUsers || ad.users;
    const jobs = s.admJobs || ad.jobs;
    const keys = s.admKeys || ad.keys;
    const plan = s.admPlan || ad.plan;
    const risk = s.admRisk || {};
    const log = s.admLog || [];

    // Každý zásah se zapíše — administrace bez záznamu není administrace.
    const note = (what, patch) => {
      const entry = { when: now(), who: who, what: what };
      c.setState(Object.assign({ admLog: [entry].concat(log).slice(0, 40) }, patch || {}));
      c.toast(what, { icon: 'ph-shield-check' });
    };

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
      const set = next => next;
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
            transfer: () => note('Vlastnictví předáno · ' + u.name + ' platí tarif, ' + (owner ? owner.name : '') + ' je správce',
              { admUsers: users.map(x => x.id === u.id ? Object.assign({}, x, { role: 'vlastník' })
                : (owner && x.id === owner.id ? Object.assign({}, x, { role: 'správce' }) : x)) }),
            cycleRole: () => {
              if (isOwner) { c.toast('Vlastník musí být právě jeden — nejdřív předejte vlastnictví.', { icon: 'ph-warning-circle' }); return; }
              note(u.name + ' má nyní roli ' + next,
                { admUsers: users.map(x => x.id === u.id ? Object.assign({}, x, { role: next }) : x) });
            },
            resend: () => note('Pozvánka pro ' + u.name + ' odeslána znovu na ' + u.mail),
            revoke: () => note(u.name + ' už do galerie nemá přístup',
              { admUsers: users.map(x => x.id === u.id ? Object.assign({}, x, { state: 'bez přístupu' }) : x) }),
            restore: () => note('Přístup pro ' + u.name + ' obnoven',
              { admUsers: users.map(x => x.id === u.id ? Object.assign({}, x, { state: u.last === 'nikdy' ? 'pozvaná' : 'aktivní' }) : x) }),
            canRevoke: u.role !== 'vlastník' && u.state !== 'bez přístupu'
          };
        }),
        admNewMail: s.admNewMail || '',
        admSetNewMail: e => c.setState({ admNewMail: e.target.value }),
        admInviteCant: !/.+@.+\..+/.test((s.admNewMail || '').trim()),
        admInvite: () => {
          const mail = (s.admNewMail || '').trim();
          if (!/.+@.+\..+/.test(mail)) return;
          const name = mail.split('@')[0].replace(/[._-]+/g, ' ').replace(/^./, ch => ch.toUpperCase());
          const next = users.concat([{ id: 'u' + (users.length + 1) + Date.now(), name: name, mail: mail, role: 'host', last: 'nikdy', state: 'pozvaná' }]);
          note('Pozvánka odeslána na ' + mail, { admUsers: set(next), admNewMail: '' });
        }
      });
    }

    if (key === 'health') {
      const bars = ((D().ABARS || {}).health || []);
      const inc = (s.admInc || ad.incidents);
      const failed = jobs.filter(j => j.state === 'chyba').length;
      return Object.assign(base, {
        admHead: (failed ? c.pl(failed, 'úloha hlásí chybu', 'úlohy hlásí chybu', 'úloh hlásí chybu') : 'Všechny úlohy prošly')
          + ' · poslední kontrola ' + (s.admCheckAt || 'dnes 4:40'),
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
          setTimeout(() => {
            c.setState({ admChecking: false, admCheckAt: now() });
            note('Kontrola systému dokončena · dostupnost 99,98 %, disk 57 %');
          }, 1100);
        }
      });
    }

    if (key === 'jobs') {
      const run = j => {
        if (j.state === 'běží') return;
        c.setState({ admJobs: jobs.map(x => x.id === j.id ? Object.assign({}, x, { state: 'běží' }) : x) });
        setTimeout(() => {
          const cur = c.state.admJobs || jobs;
          c.setState({ admJobs: cur.map(x => x.id === j.id ? Object.assign({}, x, { state: 'hotovo', last: now(), dur: x.dur }) : x) });
          note('Úloha „' + j.name + '“ dokončena');
        }, 1200);
      };
      const paused = s.admJobPause || {};
      return Object.assign(base, {
        admHead: c.pl(jobs.filter(j => !paused[j.id]).length, 'úloha je naplánovaná', 'úlohy jsou naplánované', 'úloh je naplánovaných')
          + ' · běží v noci, aby nebrzdily nahrávání',
        admJobs: jobs.map(j => ({
          id: j.id, name: j.name, cron: paused[j.id] ? 'pozastaveno' : j.cron,
          meta: 'poslední běh ' + j.last + ' · ' + j.dur,
          state: paused[j.id] ? 'pozastavená' : j.state, stateTag: tag(paused[j.id] ? 'pozastavená' : j.state),
          running: j.state === 'běží',
          runLabel: j.state === 'běží' ? 'Běží…' : 'Spustit teď',
          run: () => run(j),
          paused: !!paused[j.id],
          pauseLabel: paused[j.id] ? 'Obnovit plán' : 'Pozastavit',
          toggle: () => note(paused[j.id] ? 'Úloha „' + j.name + '“ je zpět v plánu' : 'Úloha „' + j.name + '“ pozastavena',
            { admJobPause: Object.assign({}, paused, { [j.id]: !paused[j.id] }) })
        }))
      });
    }

    if (key === 'risk') {
      const open = ad.risks.filter(r => !risk[r.id]);
      // Pruhy se počítají ze skutečného stavu, ne ze statické tabulky: kapacita
      // podle zvoleného tarifu, obsazenost po vysypání koše, druhá kopie a test
      // obnovy podle vyřešených rizik. Jinak by si dvě záložky protiřečily.
      const plans = ad.plans || [];
      const capacity = (plans.find(p => p.id === plan) || plans[0] || { gb: 200 }).gb;
      const trashGb = 1.2, singleGb = 8.1, growthGb = 2.4;
      const used = ad.usedGb - (risk.r2 ? trashGb : 0);
      const pct = v => Math.max(0, Math.min(100, Math.round(v)));
      const gb = v => String(Math.round(v * 10) / 10).replace('.', ',') + ' GB';
      const months = Math.max(1, Math.round((capacity - used) / growthGb));
      const bars = [
        ['Zaplněnost úložiště', gb(used) + ' z ' + capacity + ' GB', pct((used / capacity) * 100), (used / capacity) > 0.85 ? 1 : 0],
        ['Originály jen v jedné kopii', risk.r1 ? 'nic — vše je ve dvou kopiích' : gb(singleGb) + ' · riziko',
          risk.r1 ? 0 : pct((singleGb / used) * 100), risk.r1 ? 2 : 1],
        ['Poslední záloha', risk.r3 ? 'dnes 3:00 · obnova ověřena' : 'dnes 3:00 · bez testu obnovy', risk.r3 ? 100 : 60, risk.r3 ? 0 : 1],
        // Horizont je urgence, ne přesné číslo: nad rok se nepočítá na měsíce
        // (nikdo nečte „za 369 měsíců“) a pruh má podlahu, aby nebyl nulový.
        ['Předpověď zaplnění',
          (months <= 12 ? 'za ' + c.pl(months, 'měsíc', 'měsíce', 'měsíců') : months <= 36 ? 'za víc než rok' : 'za víc než tři roky')
            + ' při ' + gb(growthGb) + ' měsíčně',
          pct(Math.max(6, 100 - (Math.min(months, 36) / 36) * 100)), months <= 6 ? 1 : 2]
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
        admRisks: ad.risks.map(r => ({
          id: r.id, label: r.label,
          note: risk[r.id] ? r.done : r.note,
          done: !!risk[r.id],
          fixLabel: risk[r.id] ? 'Vyřešeno' : r.fix,
          fix: () => { if (!risk[r.id]) note(r.label + ' — ' + r.fix.toLowerCase(), { admRisk: Object.assign({}, risk, { [r.id]: true }) }); }
        }))
      });
    }

    if (key === 'api') {
      const active = keys.filter(k => k.state !== 'zrušený');
      return Object.assign(base, {
        admHead: c.pl(active.length, 'platný klíč', 'platné klíče', 'platných klíčů')
          + ' · klíč vidíte jen při vytvoření, potom už jen jeho konec',
        admKeys: keys.map(k => ({
          id: k.id, name: k.name, key: '…' + k.suffix, scope: k.scope,
          meta: 'vytvořen ' + k.made + ' · ' + k.used,
          state: k.state, stateTag: tag(k.state),
          active: k.state !== 'zrušený',
          revoke: () => note('Klíč „' + k.name + '“ zrušen — aplikace se odhlásí do minuty',
            { admKeys: keys.map(x => x.id === k.id ? Object.assign({}, x, { state: 'zrušený' }) : x) }),
          regen: () => {
            const suf = Math.random().toString(16).slice(2, 6);
            note('Klíč „' + k.name + '“ vygenerován znovu · …' + suf,
              { admKeys: keys.map(x => x.id === k.id ? Object.assign({}, x, { suffix: suf, state: 'aktivní', made: now(), used: 'zatím nepoužit' }) : x) });
          },
          copy: () => {
            const full = 'gal_' + k.id + '_' + k.suffix;
            try { navigator.clipboard.writeText(full); } catch (e) {}
            c.toast('Konec klíče zkopírován · …' + k.suffix, { icon: 'ph-copy' });
          }
        })),
        admNewKeyName: s.admNewKeyName || '',
        admSetNewKeyName: e => c.setState({ admNewKeyName: e.target.value }),
        admNewKeyCant: !(s.admNewKeyName || '').trim(),
        admNewKey: () => {
          const name = (s.admNewKeyName || '').trim();
          if (!name) return;
          const suf = Math.random().toString(16).slice(2, 6);
          note('Klíč „' + name + '“ vytvořen · …' + suf, {
            admKeys: keys.concat([{ id: 'k' + Date.now(), name: name, suffix: suf, made: now(), used: 'zatím nepoužit', scope: 'čtení i zápis', state: 'aktivní' }]),
            admNewKeyName: ''
          });
        }
      });
    }

    // Tarify
    const cur = ad.plans.find(p => p.id === plan) || ad.plans[0];
    // Obsazenost se řídí týmž výpočtem jako záložka Riziko úložiště.
    const usedNow = ad.usedGb - (risk.r2 ? 1.2 : 0);
    return Object.assign(base, {
      admHead: 'Tarif ' + cur.name + ' · ' + c.kc(cur.price) + ' měsíčně · využito '
        + String(Math.round(usedNow * 10) / 10).replace('.', ',') + ' GB z ' + cur.gb + ' GB',
      admPlans: ad.plans.map(p => {
        const d = p.price - cur.price;
        return {
          id: p.id, name: p.name,
          meta: c.kc(p.price) + ' měsíčně · ' + (p.gb >= 1000 ? (p.gb / 1000) + ' TB' : p.gb + ' GB'),
          fill: Math.min(100, Math.round((usedNow / p.gb) * 100)) + '%',
          fillLabel: Math.min(100, Math.round((usedNow / p.gb) * 100)) + ' % zaplněno',
          cur: p.id === cur.id,
          state: p.id === cur.id ? 'aktivní' : 'zařadit', stateTag: tag(p.id === cur.id ? 'aktivní' : 'zařadit'),
          delta: p.id === cur.id ? 'Tenhle tarif teď platíte' : d > 0 ? 'o ' + c.kc(d) + ' měsíčně víc' : 'o ' + c.kc(-d) + ' měsíčně méně',
          pick: () => { if (p.id !== cur.id) note('Tarif změněn na ' + p.name + ' · ' + c.kc(p.price) + ' měsíčně od příštího období', { admPlan: p.id }); }
        };
      })
    });
  }

  window.GalerieAdmin = { vals: vals };
})();
