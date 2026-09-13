// ——— Logika mechanismů pro dva ———
// Vytaženo z Galerie.dc.html, aby hlavní komponent nerostl a aby nové metody
// nemohly tiše přepsat existující (instalace níž na kolizi upozorní).
// Metody se instalují na prototyp komponentu a běží s jeho `this` —
// mají tedy state, setState, toast, kc i pl jako každá jiná metoda.
(function () {
  var G = window.GalerieMech || {};
  var DAY_LOAD = G.DAY_LOAD, DAY_HIST = G.DAY_HIST, BLIZ = G.BLIZ,
      SOLO_MONEY = G.SOLO_MONEY, SOLO_COST = G.SOLO_COST, KIDS = G.KIDS, PARENTS = G.PARENTS,
      ARB_MECH = G.ARB_MECH, ARB_ROWS = G.ARB_ROWS, SURP = G.SURP, VERS = G.VERS,
      EXIT_PACK = G.EXIT_PACK, SVED = G.SVED, FIGHT_START = G.FIGHT_START, QUART = G.QUART,
      INDEP = G.INDEP, TRUST = G.TRUST, EXPIRE = G.EXPIRE;
  // Data ze sdíleného katalogu (rozhodnutí a tichá pravidla).
  function DESK() { return window.GalerieData || { DEC_LIST: [], TACIT: [] }; }
  /*
   * Jména dvojice z komponentu, ne „Adrian" a „Makinka" napevno.
   *
   * Mechanismy psaly jména ukázkové dvojice do nadpisů, sloupců i hlášek —
   * a porovnávaly s nimi data (`who === 'Adrian'`). U jiné dvojice pak nic
   * neodpovídalo a obrazovky ukazovaly nuly pod cizími jmény. `dva()` vrací
   * jména z `DVOJICE`; bez přihlášení ukázková, takže ukázka zůstává stejná.
   * Věty jsou psané bez rodu, kde to jde — skloňování umí `sklon()`.
   */
  function DVA(self) { const d = self.dva ? self.dva() : []; return [d[0] || 'Adrian', d[1] || 'Makinka']; }
  function KR(j) { return String(j || '').trim().split(/\s+/)[0]; }
  function PAD(self, j, pad) { return self.sklon ? self.sklon(KR(j), pad) : KR(j); }

  window.GalerieMechLogic = {
  // Opt-in vrstva: citlivé funkce nic nesledují, dokud je někdo nezapne.
  optOn: function (k) { return !!(this.state.optIn || {})[k]; },
  optEnable: function (k, label) {
    const prev = this.state.optIn || {};
    this.setState({ optIn: Object.assign({}, prev, { [k]: true }) });
    this.toast(label + ' zapnuto · zůstává to jen mezi vámi dvěma', { icon: 'ph-toggle-right', undo: () => this.setState({ optIn: prev }) });
  },
  optOff: function (k, label) {
    const prev = this.state.optIn || {};
    this.setState({ optIn: Object.assign({}, prev, { [k]: false }) });
    this.toast(label + ' vypnuto · zápisy zůstávají, jen se nepočítají', { icon: 'ph-toggle-left', undo: () => this.setState({ optIn: prev }) });
  },

  // Kdo je dnes na tom hůř: jednoduchý přehoz podle skutečné zátěže dne.
  dayVals: function () {
    const s = this.state;
    const on = this.optOn('day');
    const bonus = s.dayBonus || {};
    const score = w => (DAY_LOAD.find(d => d.who === w) || { items: [] }).items.reduce((a, i) => a + i[1], 0) + (bonus[w] || 0);
    const [jA, jM] = DVA(this);
    const sA = score(jA), sM = score(jM);
    const worse = sA === sM ? null : sA > sM ? jA : jM;
    const druhy = worse === jA ? jM : jA;
    const given = !!s.dayGiven, shifted = !!s.dayShift;
    const maxS = Math.max(sA, sM, 1);
    return {
      dayOn: on, dayOff: !on,
      dayEnable: () => this.optEnable('day', 'Kdo je dnes na tom hůř'),
      dayDisable: () => this.optOff('day', 'Kdo je dnes na tom hůř'),
      dayOptWhy: 'Máte mapu energie i náladu dvou, ale nic z toho neřekne, kdo dnes uvaří. Tohle to řekne — jedním číslem za den, ne debatou u sporáku. Nic se nikam nehlásí a nikdo za to nedostane bod.',
      dayHead: worse
        ? KR(worse) + ' je dnes na tom hůř · ' + Math.abs(sA - sM) + ' bod' + (Math.abs(sA - sM) === 1 ? '' : 'y') + ' rozdíl'
        : DAY_LOAD.length ? 'Dnes jste na tom stejně' : 'Dnešní zátěž zatím nikdo nezapsal',
      dayNote: worse
        ? (given ? KR(worse) + ' · výhoda vzdaná. To se taky počítá — jen se to nikde neúčtuje.' : 'Dnes ' + KR(worse) + ' nic nemusí. Není to odměna, je to logistika.')
        : 'Rozdíl je nula. Dnes se dělí všechno normálně.',
      dayNoteColor: given ? 'var(--g-ink3)' : worse ? 'var(--g-ink)' : 'var(--g-ink2)',
      dayPeople: DAY_LOAD.map(d => {
        const sc = score(d.who);
        return {
          who: d.who, v: String(sc),
          w: Math.round(sc / maxS * 100) + '%',
          fill: d.who === jA ? 'var(--g-acc)' : 'var(--g-mag)',
          lead: worse === d.who,
          items: d.items.map(i => ({ text: i[0], pts: i[1] ? '+' + i[1] : '—', color: i[1] >= 2 ? 'var(--g-mag)' : 'var(--g-ink3)' })),
          worse: () => { const prev = bonus; this.setState({ dayBonus: Object.assign({}, bonus, { [d.who]: (bonus[d.who] || 0) + 1 }) }); this.toast(d.who + ' — je to dnes horší, než to vypadá. Zapsáno.', { icon: 'ph-plus', undo: () => this.setState({ dayBonus: prev }) }); }
        };
      }),
      dayCanShift: !!worse && !shifted && !given,
      dayShiftLabel: worse ? 'Dnešní práce bere ' + KR(druhy) : 'Dnešní práce',
      /*
       * Přehodit dnešní práci doopravdy.
       *
       * Hláška „Dnešní práce přešly na …" dřív jen nastavila příznak — rozpis
       * domácnosti zůstal, jak byl. Přehazuje se to, co dnes vychází na toho,
       * kdo je na tom hůř: práce na dnešní den, denní a ty po termínu.
       * Rozpis se ukládá do databáze (DomacnostVeStavu), takže to uvidí oba.
       */
      dayDoShift: () => {
        const prace = s.chores === null || s.chores === undefined ? (DESK().HOUSE_CHORES || []) : s.chores;
        const dnes = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'][new Date().getDay()];
        const dnesni = c => c.who === worse && (c.day === dnes || c.every === 'denně' || c.overdue);
        const kolik = prace.filter(dnesni).length;
        if (!kolik) { this.setState({ route: 'x-domacnost', hsTab: 'chores' }); this.toast('Na ' + PAD(this, worse, 'acc') + ' dnes z rozpisu nic nevychází — není co přehodit', { icon: 'ph-info' }); return; }
        const prev = s.chores;
        this.setState({ dayShift: true, chores: prace.map(c => dnesni(c) ? Object.assign({}, c, { who: druhy }) : c), route: 'x-domacnost', hsTab: 'chores' });
        this.toast(this.pl(kolik, 'dnešní práce přešla', 'dnešní práce přešly', 'dnešních prací přešlo') + ' na ' + PAD(this, druhy, 'acc'), {
          icon: 'ph-arrows-left-right', undo: () => this.setState({ dayShift: false, chores: prev })
        });
      },
      dayCanGive: !!worse && !given,
      dayGiveLabel: 'Vzdát se výhody',
      dayGive: () => { this.setState({ dayGiven: true }); this.toast('Výhoda se nepřenáší do zítřka a nikde se nesčítá', { icon: 'ph-hand-heart', undo: () => this.setState({ dayGiven: false }) }); },
      dayShifted: shifted,
      dayHist: DAY_HIST.map(h => ({
        d: h.d, pts: h.pts,
        who: h.who === 'nikdo' ? 'nerozhodně' : KR(h.who),
        color: h.who === jA ? 'var(--g-acc)' : h.who === jM ? 'var(--g-mag)' : 'var(--g-ink3)'
      })),
      // Souhrn z historie, ne napsaná věta „dvakrát Adrian, dvakrát Makinka".
      dayHistLine: !DAY_HIST.length ? 'Historie zatím prázdná — začne prvním zapsaným dnem.'
        : 'Za posledních ' + this.pl(DAY_HIST.length, 'den', 'dny', 'dní') + ': ' + KR(jA) + ' ' + DAY_HIST.filter(h => h.who === jA).length + '×, ' + KR(jM) + ' ' + DAY_HIST.filter(h => h.who === jM).length + '×, nerozhodně ' + DAY_HIST.filter(h => h.who !== jA && h.who !== jM).length + '×. Kdyby to bylo pořád stejně, byla by to informace o práci, ne o dni.',
      dayFoot: 'Skóre se resetuje o půlnoci. Nikdo si nic nenese do zítřka — jinak by z toho byl další účet.'
    };
  },

  // Blízkost: frekvence, iniciativa a to, co jí stojí v cestě. Bez hodnocení.
  blizVals: function () {
    const s = this.state;
    const on = this.optOn('bliz');
    const extra = s.blizAdd || 0;
    const [jA, jM] = DVA(this);
    /*
     * Chuť tento týden — pod jménem, ne pod „A" a „M".
     *
     * Stav je společný a `dva()` dává na první místo toho, kdo se dívá. Klíče
     * `blizWA`/`blizWM` proto na druhém zařízení patřily tomu druhému: každý
     * viděl číslo partnera jako svoje a mohl mu ho přepsat. U dvojice se píše
     * do `blizW[jméno]` a zadat jde jen své vlastní.
     */
    const ukazka = !((DESK().DVOJICE) || []).length;
    const wMap = s.blizW || {};
    const wA = ukazka ? s.blizWA : wMap[jA], wM = ukazka ? s.blizWM : wMap[jM];
    const blocked = s.blizBlock || {};
    const weeks = BLIZ.weeks.map((w, i) => [w[0], w[1] + (i === BLIZ.weeks.length - 1 ? extra : 0)]);
    const total = weeks.reduce((a, w) => a + w[1], 0);
    const maxW = Math.max.apply(null, weeks.map(w => w[1]).concat([1]));
    const iA = (BLIZ.init || {})[jA] || 0, iM = (BLIZ.init || {})[jM] || 0;
    const nA = (BLIZ.no || {})[jA] || 0, nM = (BLIZ.no || {})[jM] || 0;
    const bothIn = wA !== undefined && wM !== undefined;
    const blocks = BLIZ.block.filter(b => !blocked[b[0]]).slice().sort((a, b) => b[1] - a[1]);
    const maxB = Math.max.apply(null, blocks.map(b => b[1]).concat([1]));
    return {
      blizOn: on, blizOff: !on,
      blizEnable: () => this.optEnable('bliz', 'Blízkost'),
      blizDisable: () => this.optOff('bliz', 'Blízkost'),
      blizOptWhy: 'Nejčastější důvod, proč se páry rozejdou, nemá v aplikaci řádek. Tohle není hodnocení, není tu žádné cílové číslo a nikdo tu nedostane známku. Je tu frekvence, kdo navrhuje, kdo odmítá — a co tomu stojí v cestě.',
      blizHead: this.pl(total, 'krát za osm týdnů', 'krát za osm týdnů', 'krát za osm týdnů'),
      blizNote: 'Žádné doporučené číslo neexistuje. Zajímavý je jen trend a to, jestli je iniciativa na jedné straně.',
      blizWeeks: weeks.map(w => ({
        label: w[0], v: String(w[1]),
        h: Math.round(w[1] / maxW * 100) + '%',
        fill: w[1] === 0 ? 'var(--g-line)' : 'var(--g-acc)'
      })),
      blizTrend: !weeks.length ? 'Zatím nic zapsaného — trend se ukáže po několika týdnech.'
        : weeks.slice(4).reduce((a, w) => a + w[1], 0) < weeks.slice(0, 4).reduce((a, w) => a + w[1], 0)
        ? 'Poslední čtyři týdny je to méně než předchozí čtyři. Podívejte se na tabulku níž — pravděpodobně tam ten důvod stojí.'
        : 'Poslední čtyři týdny to drží.',
      blizInit: [
        { name: 'Navrhuje ' + KR(jA), v: String(iA), w: Math.round(iA / Math.max(iA, iM, 1) * 100) + '%', color: 'var(--g-acc)' },
        { name: 'Navrhuje ' + KR(jM), v: String(iM), w: Math.round(iM / Math.max(iA, iM, 1) * 100) + '%', color: 'var(--g-mag)' },
        { name: 'Odmítá ' + KR(jA), v: String(nA), w: Math.round(nA / Math.max(nA, nM, 1) * 100) + '%', color: 'var(--g-ink3)' },
        { name: 'Odmítá ' + KR(jM), v: String(nM), w: Math.round(nM / Math.max(nA, nM, 1) * 100) + '%', color: 'var(--g-ink3)' }
      ],
      blizInitLine: iA > iM * 2 || iM > iA * 2
        ? 'Navrhuje skoro vždycky ' + KR(iA > iM ? jA : jM) + '. To je dvojí zátěž: jeden nosí riziko odmítnutí, druhý nosí povinnost odpovědět. Ani jedno není příjemné.'
        : iA + iM ? 'Iniciativa je rozdělená.' : 'Zatím nikdo nic nezapsal.',
      blizPrivate: bothIn
        ? 'Tento týden: ' + KR(jA) + ' ' + wA + ' z 5, ' + KR(jM) + ' ' + wM + ' z 5.' + (Math.abs(wA - wM) >= 2 ? ' Rozdíl dva body a víc — o tom se dá mluvit bez viníka.' : ' Podobně.')
        : wA !== undefined || wM !== undefined
          ? 'Jeden z vás už odpověděl. Čísla se zobrazí, až odpoví oba — dřív by to bylo hodnocení, ne rozhovor.'
          : 'Chuť tenhle týden zadává každý sám. Ukáže se, až to udělají oba.',
      blizPrivateColor: bothIn ? 'var(--g-ink)' : 'var(--g-ink3)',
      blizScaleA: [1, 2, 3, 4, 5].map(n => ({
        n: String(n),
        bg: wA === n ? 'var(--g-acc-soft)' : 'transparent',
        fg: wA === n ? 'var(--g-acc-deep)' : 'var(--g-ink3)',
        set: () => ukazka ? this.setState({ blizWA: n }) : this.setState({ blizW: Object.assign({}, wMap, { [jA]: n }) })
      })),
      blizScaleM: [1, 2, 3, 4, 5].map(n => ({
        n: String(n),
        bg: wM === n ? 'var(--g-mag-soft)' : 'transparent',
        fg: wM === n ? 'var(--g-mag)' : 'var(--g-ink3)',
        set: () => ukazka ? this.setState({ blizWM: n }) : this.toast('Chuť zadává ' + KR(jM) + ' na svém zařízení — za druhého se nepíše', { icon: 'ph-lock-simple' })
      })),
      blizBlocks: blocks.map(b => ({
        what: b[0], v: this.pl(b[1], 'krát', 'krát', 'krát'),
        w: Math.round(b[1] / maxB * 100) + '%',
        fill: b[1] >= 6 ? 'var(--g-mag)' : 'var(--g-ink3)',
        canOpen: !!b[2],
        open: () => { if (b[2]) this.setState({ route: b[2] }); },
        solved: () => { const prev = blocked; this.setState({ blizBlock: Object.assign({}, blocked, { [b[0]]: true }) }); this.toast('„' + b[0] + '“ vyřešeno · vypadlo z překážek', { icon: 'ph-check-circle', undo: () => this.setState({ blizBlock: prev }) }); }
      })),
      blizAdd: () => { const prev = extra; this.setState({ blizAdd: extra + 1 }); this.toast('Zapsáno k tomuto týdnu · bez podrobností', { icon: 'ph-heart', undo: () => this.setState({ blizAdd: prev }) }); },
      blizCyc: () => this.setState({ route: 'x-cyklus' }),
      blizFoot: 'Tahle záložka neposílá připomínky a nikdy nenapíše, že máte něco dohnat. Kdyby to dělala, byla by to ta nejhorší funkce v aplikaci.'
    };
  },

  // Peníze jednoho, ne naše.
  mineVals: function () {
    const s = this.state;
    const on = this.optOn('solo');
    const inc = s.mineInc || {};
    const even = !!s.mineEven;
    const rows = SOLO_MONEY.map(m => Object.assign({}, m, { income: inc[m.who] || m.income, share: even ? 50 : m.share }));
    const flat = SOLO_COST.rent + SOLO_COST.life;
    const [jA] = DVA(this);
    // Bez řádků by `Math.max()` vrátil −Infinity a šířky pruhů NaN.
    const maxI = Math.max.apply(null, rows.map(r => r.income).concat([1]));
    const solo = r => r.income - (SOLO_COST.rent + SOLO_COST.alone) - Math.round(r.debt ? 3400 : 0);
    const both = rows.map(r => ({ r: r, left: solo(r) }));
    const weak = both.slice().sort((a, b) => a.left - b.left)[0];
    return {
      mineOn: on, mineOff: !on,
      mineEnable: () => this.optEnable('solo', 'Každý sám'),
      mineDisable: () => this.optOff('solo', 'Každý sám'),
      mineOptWhy: 'Celá aplikace počítá „naše“. To je v pořádku, dokud to funguje. Tady se to jednou rozpočítá na dva — příjem, dluh, majetek a to, co by z toho zbylo, kdyby jeden zůstal sám. Není to plán odchodu, je to test, jestli zůstáváte dobrovolně.',
      mineHead: !rows.length ? 'Zatím nic zapsaného — příjmy, dluhy a náklady si tu každý vyplní sám'
        : 'Nájem a život ve dvou ' + this.kc(flat) + ' · sám ' + this.kc(SOLO_COST.rent + SOLO_COST.alone),
      mineNote: 'Když jeden odejde, náklady se nepůlí. Nájem zůstane celý a k životu ubere jen část — to je ten rozdíl, který nikdo nepočítá dopředu.',
      mineRows: both.map(x => ({
        who: KR(x.r.who), note: x.r.note,
        tag: x.r.who === jA ? 'tag-accent' : 'tag-accent-2',
        income: this.kc(x.r.income),
        w: Math.round(x.r.income / maxI * 100) + '%',
        fill: x.r.who === jA ? 'var(--g-acc)' : 'var(--g-mag)',
        debt: x.r.debt ? this.kc(x.r.debt) + ' · ' + x.r.debtNote : x.r.debtNote,
        debtColor: x.r.debt ? 'var(--g-mag)' : 'var(--g-ok)',
        own: this.kc(x.r.own),
        share: x.r.share + ' % společných výdajů',
        left: (x.left >= 0 ? '+' : '−') + this.kc(Math.abs(x.left)),
        leftColor: x.left >= 0 ? 'var(--g-ok)' : 'var(--g-mag)',
        leftLine: x.left >= 0
          ? 'Samotnému by po nájmu a životě zbylo ' + this.kc(x.left) + ' měsíčně.'
          : 'Samotnému by chybělo ' + this.kc(-x.left) + ' měsíčně. Z vlastního majetku by to vydrželo ' + Math.max(1, Math.round(x.r.own / -x.left)) + ' měsíců.',
        setInc: e => this.setState({ mineInc: Object.assign({}, inc, { [x.r.who]: parseInt(String(e.target.value).replace(/\s/g, ''), 10) || x.r.income }) }),
        incVal: String(x.r.income)
      })),
      mineWeak: weak && rows.length > 1
        ? 'Samostatně na tom hůř: ' + KR(weak.r.who) + '. To není argument v hádce — je to důvod, proč má obálka jen pro sebe smysl právě tam víc.'
        : '',
      mineEvenOn: even,
      mineEvenLabel: even ? 'Podíly jsou 50 : 50' : 'Vyrovnat podíly na 50 : 50',
      mineEven: () => { const prev = even; this.setState({ mineEven: !even }); this.toast(even ? 'Podíly zpátky podle příjmů' : 'Podíly vyrovnány na polovinu · vyšší příjem to unese lépe', { icon: 'ph-scales', undo: () => this.setState({ mineEven: prev }) }); },
      mineEnv: () => this.setState({ route: 'x-rozpocty', budTab: 8 }),
      mineFoot: 'Tohle číslo se nikomu neposílá a v žádném souhrnu se neobjeví. Otevírá se jen tady a jen když ho někdo otevře.'
    };
  },

  // Děti: rozhodnutí, které schválně nemá termín ani mechanismus.
  kidsVals: function () {
    const s = this.state;
    const on = this.optOn('kids');
    const st = s.kidsStance || {}, yr = s.kidsYear || {}, fixed = s.kidsFixed || {};
    const talks = (s.kidsTalks || []).concat(KIDS.talks);
    const [jA, jM] = DVA(this);
    // Bez zapsaných pozic dvě prázdné — každý svoji, pod svým jménem. Dřív tu
    // stály pozice ukázkové dvojice a prázdné pole shodilo `pos[0].stance`.
    const zaklad = KIDS.pos.length ? KIDS.pos : [jA, jM].map(w => ({ who: w, stance: 'nevím', year: null, sure: 0, note: '' }));
    const pos = zaklad.map(p => Object.assign({}, p, { stance: st[p.who] || p.stance, year: yr[p.who] || p.year }));
    const years = pos.map(p => p.year);
    const gap = years[0] && years[1] ? Math.abs(years[0] - years[1]) : 0;
    const blocks = KIDS.blockers.map(b => Object.assign({}, b, { state: fixed[b.what] ? 'vyřešeno' : b.state }));
    const openB = blocks.filter(b => b.state !== 'vyřešeno');
    const STC = { 'ano': ['tag-accent', 'var(--g-acc-deep)'], 'ne': ['tag-accent-2', 'var(--g-mag)'], 'nevím': ['tag-neutral', 'var(--g-ink2)'] };
    const BST = { 'vyřešeno': 'var(--g-ok)', 'částečně': 'var(--g-warn)', 'otevřené': 'var(--g-mag)', 'nezačato': 'var(--g-ink3)' };
    return {
      kidsOn: on, kidsOff: !on,
      kidsEnable: () => this.optEnable('kids', 'Děti'),
      kidsDisable: () => this.optOff('kids', 'Děti'),
      kidsOptWhy: 'Největší rozhodnutí, které pár dělá, tady dosud nemělo řádek. Tahle záložka na nic netlačí: nemá termín, nepočítá odpočet a nikdy nepošle připomínku. Drží jen dvě pozice, dvě čísla a seznam toho, co tomu stojí v cestě.',
      kidsHead: pos[0].stance === pos[1].stance
        ? 'Oba říkáte „' + pos[0].stance + '“'
        : KR(pos[0].who) + ': ' + pos[0].stance + ' · ' + KR(pos[1].who) + ': ' + pos[1].stance,
      kidsGap: gap
        ? this.pl(gap, 'rok rozdílu', 'roky rozdílu', 'let rozdílu') + ' v tom, kdy. Rozdíl v roce není nesouhlas — je to informace o tom, kolik času máte na ' + this.pl(openB.length, 'tu jednu věc', 'ty věci', 'ty věci') + ' níž.'
        : years[0] && years[1] ? 'Ve roce se shodujete.' : 'Rok zatím nikdo nezapsal.',
      kidsNoMech: 'Arbitr se na tohle nepoužije. Losování ani minimaximum tady nemají co dělat — u rozhodnutí, které nese jeden člověk v těle, nemůže padnout mechanismem.',
      kidsPos: pos.map(p => {
        const sc = STC[p.stance] || STC['nevím'];
        /*
         * Pozici si píše každý sám. U dvojice je první v `dva()` ten, kdo se
         * dívá — zapsat „ano" nebo rok za druhého by bylo mluvit za něj
         * přesně u rozhodnutí, kde to nejde.
         */
        const ukazka = !((DESK().DVOJICE) || []).length;
        const cizi = !ukazka && p.who !== jA;
        const zaDruheho = () => { this.toast('Pozici ' + PAD(this, p.who, 'gen') + ' zapíše ' + KR(p.who) + ' na svém zařízení', { icon: 'ph-lock-simple' }); };
        return {
          who: KR(p.who), note: p.note, stance: p.stance, tag: sc[0], color: sc[1],
          year: p.year ? String(p.year) : '',
          sure: p.sure + ' z 5',
          w: Math.round(p.sure / 5 * 100) + '%',
          fill: p.who === jA ? 'var(--g-acc)' : 'var(--g-mag)',
          picks: ['ano', 'nevím', 'ne'].map(x => ({
            label: x,
            bg: p.stance === x ? 'var(--g-acc-soft)' : 'transparent',
            fg: p.stance === x ? 'var(--g-acc-deep)' : 'var(--g-ink3)',
            set: () => { if (cizi) { zaDruheho(); return; } const prev = st; this.setState({ kidsStance: Object.assign({}, st, { [p.who]: x }) }); this.toast(p.who + ': ' + x + ' · zapsáno bez komentáře', { icon: 'ph-user', undo: () => this.setState({ kidsStance: prev }) }); }
          })),
          setYear: e => { if (cizi) { zaDruheho(); return; } this.setState({ kidsYear: Object.assign({}, yr, { [p.who]: parseInt(e.target.value, 10) || p.year }) }); }
        };
      }),
      kidsBlocks: blocks.map(b => ({
        what: b.what, kind: b.kind, state: b.state,
        color: BST[b.state] || 'var(--g-ink3)',
        dim: b.state === 'vyřešeno' ? .6 : 1,
        canFix: b.state !== 'vyřešeno',
        fix: () => { const prev = fixed; this.setState({ kidsFixed: Object.assign({}, fixed, { [b.what]: true }) }); this.toast('„' + b.what + '“ vyřešeno', { icon: 'ph-check-circle', undo: () => this.setState({ kidsFixed: prev }) }); },
        canOpen: !!b.route,
        open: () => { if (b.route) this.setState({ route: b.route }); }
      })),
      kidsTalks: talks.map(t => ({ when: t.when, mins: this.pl(t.mins, 'minuta', 'minuty', 'minut'), out: t.out })),
      kidsTalkLine: talks.length
        ? this.pl(talks.length, 'zapsaný rozhovor', 'zapsané rozhovory', 'zapsaných rozhovorů') + '. Na rozhodnutí téhle velikosti se nepočítá, kolik jich má být — jen že nějaký byl.'
        : 'Zatím žádný zapsaný rozhovor.',
      kidsNewMins: s.kidsM || '', kidsNewOut: s.kidsO || '',
      kidsSetMins: e => this.setState({ kidsM: e.target.value }),
      kidsSetOut: e => this.setState({ kidsO: e.target.value }),
      kidsAddOff: !(s.kidsO || '').trim(),
      kidsAddOp: (s.kidsO || '').trim() ? 1 : .45,
      kidsAdd: () => {
        const prev = s.kidsTalks || [];
        this.setState({ kidsTalks: [{ when: 'dnes', mins: parseInt(s.kidsM || '0', 10) || 20, out: (s.kidsO || '').trim() }].concat(prev), kidsM: '', kidsO: '' });
        this.toast('Rozhovor zapsán · žádné další datum se nenaplánovalo', { icon: 'ph-chat-circle-dots', undo: () => this.setState({ kidsTalks: prev }) });
      },
      kidsFoot: 'Nedohodnout se je platný stav. Aplikace ho nebude opravovat.'
    };
  },

  // Rodiče: stárnutí, vzdálenost a co je opravdu vyřízené.
  parVals: function () {
    const s = this.state;
    const on = this.optOn('par');
    const done = s.parDone || {};
    const rows = PARENTS.map(p => {
      const extra = done[p.id] || [];
      return Object.assign({}, p, { done: p.done.concat(extra), missing: p.missing.filter(m => extra.indexOf(m) < 0) });
    });
    const ready = rows.reduce((a, p) => a + p.done.length, 0);
    const all = rows.reduce((a, p) => a + p.done.length + p.missing.length, 0);
    const near = rows.filter(p => p.dist <= 30).length;
    const cost = rows.reduce((a, p) => a + p.cost, 0);
    const oldest = rows.slice().sort((a, b) => b.age - a.age)[0];
    return {
      parOn: on, parOff: !on,
      parEnable: () => this.optEnable('par', 'Rodiče'),
      parDisable: () => this.optOff('par', 'Rodiče'),
      parOptWhy: 'Rodina v téhle aplikaci řeší návštěvy a jejich symetrii. Neřeší, kdo bude za deset let starat se o koho, za čí peníze a jak daleko. Tahle záložka to jen drží zapsané — bez odpočtů a bez morálky.',
      parHead: rows.length ? ready + ' ze ' + all + ' věcí vyřízených · ' + this.pl(rows.length, 'rodič', 'rodiče', 'rodičů') : 'Zatím nikdo zapsaný',
      parNote: 'Plná moc, dokumenty a dohoda o financování. Tři věci, které se vyřizují za hodinu, dokud je čas — a nedají se vyřídit vůbec, když čas není.',
      // Bez zapsaných rodičů by podíl vyšel 0/0 = NaN.
      parW: (all ? Math.round(ready / all * 100) : 0) + '%',
      parPct: (all ? Math.round(ready / all * 100) : 0) + ' %',
      parRows: rows.map(p => ({
        name: p.name, health: p.health,
        age: this.pl(p.age, 'rok', 'roky', 'let'),
        dist: p.dist <= 30 ? p.dist + ' km' : p.dist + ' km — na jednu návštěvu celý den',
        distColor: p.dist <= 30 ? 'var(--g-ink3)' : 'var(--g-warn)',
        who: 'má ' + KR(p.who),
        whoTag: p.who === DVA(this)[0] ? 'tag-accent' : 'tag-accent-2',
        horizon: 'za ' + Math.max(1, 80 - p.age) + ' let mu bude osmdesát',
        cost: p.cost ? this.kc(p.cost) + ' měsíčně už teď' : 'zatím bez nákladů',
        costColor: p.cost ? 'var(--g-mag)' : 'var(--g-ink3)',
        w: Math.round(p.done.length / Math.max(1, p.done.length + p.missing.length) * 100) + '%',
        fill: p.missing.length === 0 ? 'var(--g-ok)' : p.missing.length >= 3 ? 'var(--g-mag)' : 'var(--g-warn)',
        doneList: p.done.map(d => ({ text: d })),
        missList: p.missing.map(m => ({
          text: m,
          fix: () => { const prev = done; this.setState({ parDone: Object.assign({}, done, { [p.id]: (done[p.id] || []).concat([m]) }) }); this.toast(m + ' — ' + p.name + ' · vyřízeno', { icon: 'ph-check-circle', undo: () => this.setState({ parDone: prev }) }); }
        })),
        allDone: p.missing.length === 0,
        vault: () => { this.setState({ route: 'x-trezor' }); this.toast('Dokumenty k ' + p.name + ' patří do trezoru', { icon: 'ph-lock-key' }); },
        visit: () => this.setState({ hsTab: 'vis' })
      })),
      parBalance: !rows.length ? '' : 'Blízko máte ' + this.pl(near, 'rodiče', 'rodiče', 'rodičů') + ', daleko ' + (rows.length - near) + '. Vzdálenost rozhodne o tom, kdo bude jezdit — a to nebude spravedlivé, ať se dohodnete jakkoli.',
      parCost: cost ? 'Dnes to stojí ' + this.kc(cost) + ' měsíčně. Za deset let to může být deset násobek — a bude to na dvou lidech.' : 'Dnes to nestojí nic.',
      parOldest: oldest ? 'Nejstarší je ' + oldest.name.toLowerCase() + ' · ' + oldest.age + ' let. U ' + (oldest.missing.length ? this.pl(oldest.missing.length, 'té jedné věci', 'těch věcí', 'těch věcí') + ' chybí podpis' : 'něj je vyřízeno vše') + '.' : '',
      parBudget: () => this.setState({ route: 'x-rozpocty', budTab: 2 }),
      parFoot: 'Tohle je jediná tabulka v aplikaci, která zestárne sama. Každý rok se čísla zvětší, i když nikdo nic neudělá.'
    };
  },

  // Arbitr: mechanismus se vybírá v klidu a spouští se v nepohodě.
  arbVals: function () {
    const s = this.state;
    const over = s.arbMech || {}, done = s.arbDone || {};
    const M = {}; ARB_MECH.forEach(m => { M[m.id] = m; });
    const mechOf = r => over[r.id] !== undefined ? over[r.id] : r.mech;
    const decs = s.decs === null || s.decs === undefined ? DESK().DEC_LIST : s.decs;
    const open = ARB_ROWS.filter(r => !done[r.id]);
    const missing = open.filter(r => !mechOf(r));
    const oldest = open.slice().sort((a, b) => b.days - a.days)[0];
    const run = r => {
      const mid = mechOf(r), m = M[mid];
      let pick = '', why = '';
      if (mid === 'los') { pick = Math.random() < 0.5 ? r.optA : r.optM; why = 'Los padl na „' + pick + '“. Druhá varianta byla stejně snesitelná — právě proto tohle los rozhodnout mohl.'; }
      else if (mid === 'last') { pick = r.owner === DVA(this)[1] ? r.optM : r.optA; why = KR(r.owner) + ' má v oblasti „' + r.area + '“ poslední slovo. Dohodnuto předem, ne teď.'; }
      else if (mid === 'minimax') { pick = r.lossM > r.lossA ? r.optM : r.optA; why = 'Menší škoda: ' + (r.lossM > r.lossA ? KR(DVA(this)[0]) + ' — ' + r.lossA + ' z 5' : KR(DVA(this)[1]) + ' — ' + r.lossM + ' z 5') + '. Vyhrává varianta, u které ten druhý bolí méně.'; }
      else { pick = 'Odloženo o sedm dní'; why = 'Nerozhodlo se nic. Za týden se to otevře znovu a odklad už použít nelze.'; }
      const prevD = done, prevDec = decs;
      const patch = { arbDone: Object.assign({}, done, { [r.id]: { pick: pick, why: why, mech: m.name, when: 'právě teď' } }) };
      if (mid !== 'delay') {
        patch.decs = [{
          id: 'r' + Date.now(), title: r.what + ' → ' + pick, date: 'dnes', by: 'Arbitr · ' + m.name, status: 'platí',
          why: [why, 'Rozhodl mechanismus dohodnutý předem. Nikdo z vás nemusel druhého přesvědčit.'],
          rejected: [pick === r.optA ? r.optM : r.optA], review: 'za rok'
        }].concat(decs);
      }
      this.setState(patch);
      this.toast(mid === 'delay' ? '„' + r.what + '“ odloženo o týden' : 'Rozhodnuto: ' + pick + ' · zapsáno do Paměti rozhodnutí',
        { icon: m.icon, undo: () => this.setState({ arbDone: prevD, decs: prevDec }) });
    };
    return {
      arbHead: missing.length
        ? this.pl(missing.length, 'rozpor bez mechanismu', 'rozpory bez mechanismu', 'rozporů bez mechanismu')
        : 'Každý otevřený rozpor má svůj mechanismus',
      arbNote: 'Mechanismus se vybírá, dokud jste v pohodě. Ve chvíli, kdy se nemůžete dohodnout, se o něm už nehlasuje — jen se spustí. To je celý trik.',
      arbBig: String(open.length),
      arbOldest: oldest
        ? 'Nejdéle otevřené: ' + oldest.what.toLowerCase() + ' — ' + oldest.days + ' dní. ' + (mechOf(oldest) ? 'Mechanismus je připravený, jen ho nikdo nespustil.' : 'A pořád nemá mechanismus.')
        : 'Nic otevřeného.',
      arbMechs: ARB_MECH.map(m => ({
        name: m.name, how: m.how, icon: m.icon,
        used: ARB_ROWS.filter(r => mechOf(r) === m.id).length + '×'
      })),
      arbRows: ARB_ROWS.map(r => {
        const mid = mechOf(r), m = M[mid], v = done[r.id];
        return {
          what: r.what, area: r.area, days: r.days + ' dní otevřené',
          stake: r.stake ? this.kc(r.stake) + ' ve hře' : 'peníze v tom nejsou',
          optA: r.optA, optM: r.optM,
          lossA: r.lossA + ' z 5', lossM: r.lossM + ' z 5',
          wA: Math.round(r.lossA / 5 * 100) + '%', wM: Math.round(r.lossM / 5 * 100) + '%',
          note: r.note,
          mech: m ? m.name : 'Bez mechanismu',
          mechHow: m ? m.how : 'Dokud mechanismus nemá, vrací se to pořád dokola. Vyberte ho teď, ne až u toho budete stát.',
          mechIcon: m ? m.icon : 'ph-question',
          mechColor: m ? 'var(--g-acc)' : 'var(--g-mag)',
          border: v ? 'var(--g-ok)' : m ? 'var(--g-line2)' : 'var(--g-mag)',
          picks: ARB_MECH.map(x => ({
            label: x.name,
            weight: mid === x.id ? 500 : 400,
            bg: mid === x.id ? 'var(--g-acc-soft)' : 'transparent',
            fg: mid === x.id ? 'var(--g-acc-deep)' : 'var(--g-ink2)',
            set: () => { const prev = over; this.setState({ arbMech: Object.assign({}, over, { [r.id]: x.id }) }); this.toast('„' + r.what + '“ → ' + x.name, { icon: x.icon, undo: () => this.setState({ arbMech: prev }) }); }
          })),
          canRun: !!m && !v,
          runLabel: mid === 'delay' ? 'Odložit o týden' : 'Nechť rozhodne',
          run: () => run(r),
          settled: !!v,
          verdict: v ? v.pick : '',
          verdictWhy: v ? v.why : '',
          verdictMech: v ? 'Rozhodl ' + v.mech.toLowerCase() + ' · ' + v.when : '',
          open: () => this.setState({ route: 'x-rozhodnuti', dcTab: 'mem' })
        };
      }),
      arbSettled: Object.keys(done).length
        ? this.pl(Object.keys(done).length, 'rozpor už padl', 'rozpory už padly', 'rozporů už padlo') + ' mechanismem — bez jediného přesvědčování.'
        : 'Zatím nic nepadlo mechanismem. Všechno se pořád řeší ručně.'
    };
  },

  // Rozpočet překvapení: 3 % ročních výdajů bez plánu — a otázka, co bylo opravdu překvapení.
  surpVals: function () {
    const s = this.state;
    const srcOver = s.surpSrc || {}, moved = s.surpMoved || {};
    const extra = s.surpExtra || [];
    const all = SURP.draws.concat(extra);
    const draws = all.filter(d => !moved[d.id]);
    const srcOf = d => srcOver[d.id] || d.src;
    const SRC = { surprise: ['překvapení', 'tag-accent', 'var(--g-acc)'], omission: ['opomenutí', 'tag-accent-2', 'var(--g-mag)'], both: ['obojí', 'tag-neutral', 'var(--g-warn)'] };
    /*
     * Roční výdaje: ukázka je má napsané (964 000 Kč), dvojice je má v knize
     * výdajů za poslední rok (`RULEXP`). Bez nich je rozpočet nula a podíly
     * se nepočítají — jinak by „0 z 0" vyšlo jako NaN %.
     */
    const zaRok = SURP.spendYear || ((DESK().RULEXP || []).reduce((a, x) => a + (x[2] || 0), 0));
    const budget = Math.round(zaRok * SURP.pct / 100);
    const used = draws.reduce((a, d) => a + d.amount, 0);
    const sum = k => draws.filter(d => srcOf(d) === k).reduce((a, d) => a + d.amount, 0);
    const sS = sum('surprise'), oS = sum('omission'), bS = sum('both');
    const omPct = used ? Math.round((oS + bS / 2) / used * 100) : 0;
    const left = budget - used;
    return {
      surpBig: this.kc(used),
      surpBudget: this.kc(budget),
      surpHead: left >= 0
        ? 'Z ' + this.kc(budget) + ' zbývá ' + this.kc(left)
        : 'Přes rozpočet o ' + this.kc(-left),
      surpHeadColor: left >= 0 ? 'var(--g-ink)' : 'var(--g-mag)',
      surpNote: 'Tři procenta ročních výdajů, na která schválně není plán. Nedělá se z nich kategorie a nikdo je nemusí obhajovat. Jediná povinnost: u každého odběru je zdroj.',
      surpW: (budget ? Math.min(100, Math.round(used / budget * 100)) : 0) + '%',
      surpFill: left >= 0 ? 'var(--g-acc)' : 'var(--g-mag)',
      surpPct: (budget ? Math.round(used / budget * 100) : 0) + ' %',
      surpSplit: [
        { name: 'Skutečná překvapení', v: this.kc(sS), w: used ? Math.round(sS / used * 100) + '%' : '0%', color: 'var(--g-acc)' },
        { name: 'Záměrná opomenutí', v: this.kc(oS), w: used ? Math.round(oS / used * 100) + '%' : '0%', color: 'var(--g-mag)' },
        { name: 'Obojí zároveň', v: this.kc(bS), w: used ? Math.round(bS / used * 100) + '%' : '0%', color: 'var(--g-warn)' }
      ],
      surpVerdict: !draws.length ? 'Zatím žádný odběr. Až něco nečekaného přijde, zapište to sem i se zdrojem.'
        : omPct >= 40
        ? 'Opomenutí jsou ' + omPct + ' % odběrů. Tohle už není rozpočet překvapení — je to rozpočet zapomínání, a ten se dá naplánovat.'
        : 'Opomenutí jsou ' + omPct + ' % odběrů. Zbytek jsou věci, které opravdu nešly předvídat — na to ten rozpočet je.',
      surpVerdictColor: omPct >= 40 ? 'var(--g-mag)' : 'var(--g-ok)',
      surpRows: draws.slice().reverse().map(d => {
        const k = srcOf(d), sd = SRC[k];
        return {
          what: d.what, date: d.date, amount: this.kc(d.amount), note: d.note,
          src: sd[0], tagClass: sd[1], color: sd[2],
          border: k === 'omission' ? 'var(--g-mag)' : 'var(--g-line2)',
          picks: ['surprise', 'omission', 'both'].map(x => ({
            label: SRC[x][0],
            weight: k === x ? 500 : 400,
            bg: k === x ? 'var(--g-acc-soft)' : 'transparent',
            fg: k === x ? 'var(--g-acc-deep)' : 'var(--g-ink3)',
            set: () => { const prev = srcOver; this.setState({ surpSrc: Object.assign({}, srcOver, { [d.id]: x }) }); this.toast('„' + d.what + '“ → ' + SRC[x][0], { icon: 'ph-tag', undo: () => this.setState({ surpSrc: prev }) }); }
          })),
          canPlan: k !== 'surprise',
          plan: () => {
            const prev = moved;
            this.setState({ surpMoved: Object.assign({}, moved, { [d.id]: true }), budTab: 2 });
            this.toast('„' + d.what + '“ přesunuto do vyhrazených částek · ' + this.kc(d.amount) + ' ročně', { icon: 'ph-arrow-square-out', undo: () => this.setState({ surpMoved: prev }) });
          },
          origin: () => this.setState({ route: 'x-transakce' })
        };
      }),
      surpMovedLine: Object.keys(moved).length
        ? this.pl(Object.keys(moved).length, 'věc už z překvapení odešla', 'věci už z překvapení odešly', 'věcí už z překvapení odešlo') + ' do plánu. Rozpočet překvapení se tím uvolnil.'
        : 'Nic se z překvapení ještě nepřesunulo do plánu.',
      surpNewWhat: s.surpW || '', surpNewAmt: s.surpA || '',
      surpSetWhat: e => this.setState({ surpW: e.target.value }),
      surpSetAmt: e => this.setState({ surpA: e.target.value }),
      surpAddOff: !(s.surpW || '').trim() || !parseInt(s.surpA || '0', 10),
      surpAddOp: (s.surpW || '').trim() && parseInt(s.surpA || '0', 10) ? 1 : .45,
      surpAdd: () => {
        const amt = parseInt(s.surpA || '0', 10);
        const row = { id: 'sx' + Date.now(), what: (s.surpW || '').trim(), amount: amt, date: 'dnes', src: 'surprise', note: 'Zdroj zatím neurčený — dokud se neurčí, počítá se jako překvapení.' };
        const prev = extra;
        this.setState({ surpExtra: [row].concat(extra), surpW: '', surpA: '' });
        this.toast('Odběr zapsán · ' + this.kc(amt) + ' z rozpočtu překvapení', { icon: 'ph-lightning', undo: () => this.setState({ surpExtra: prev }) });
      }
    };
  },

  // Záznam verzí: jak se rozhodnutí měnilo od prvního nápadu a kdo koho posunul.
  // Pozor na jméno: verVals(cur) už patří verzím fotek v lightboxu.
  verzeVals: function () {
    const s = this.state;
    const extra = s.verSteps || {};
    const [jA, jM] = DVA(this), oba = KR(jA) + ' a ' + KR(jM);
    // Bez zapsaných rozhodnutí prázdný záznam — `VERS[0].id` shodil celou obrazovku.
    const pickId = s.verPick || (VERS[0] || {}).id;
    const row = VERS.find(v => v.id === pickId) || VERS[0] || { id: '', title: 'Zatím žádné rozhodnutí s verzemi', unit: '', money: false, route: 'x-rozhodnuti', steps: [] };
    const steps = row.steps.concat(extra[pickId] || []);
    const fmt = v => row.money ? this.kc(v) : v + ' ' + row.unit;
    const vals = steps.map(x => x.v);
    const min = vals.length ? Math.min.apply(null, vals) : 0, max = vals.length ? Math.max.apply(null, vals) : 0;
    const span = Math.max(1, max - min);
    const first = steps.length ? steps[0].v : 0, last = steps.length ? steps[steps.length - 1].v : 0;
    const byWho = {};
    steps.forEach((x, i) => { if (!i) return; byWho[x.by] = (byWho[x.by] || 0) + (x.v - steps[i - 1].v); });
    const movers = Object.keys(byWho).map(k => ({ name: k, d: byWho[k] })).sort((a, b) => Math.abs(b.d) - Math.abs(a.d));
    const maxAbs = Math.max.apply(null, movers.map(m => Math.abs(m.d)).concat([1]));
    const big = movers[0];
    return {
      verPickers: VERS.map(v => ({
        label: v.title,
        weight: v.id === pickId ? 500 : 400,
        color: v.id === pickId ? 'var(--g-ink)' : 'var(--g-ink2)',
        line: v.id === pickId ? 'var(--g-acc)' : 'transparent',
        count: this.pl(v.steps.length + (extra[v.id] || []).length, 'verze', 'verze', 'verzí'),
        go: () => this.setState({ verPick: v.id })
      })),
      verTitle: row.title,
      verHead: !steps.length ? 'Zatím bez verzí — první zapíšete níž' : 'Od ' + fmt(first) + ' k ' + fmt(last) + ' · ' + this.pl(steps.length, 'verze', 'verze', 'verzí'),
      verNote: 'Výsledek si každý pamatuje. Cestu k němu ne — a přesně tam je vidět, kdo ustoupil a o kolik. Tenhle záznam nikdo nepíše, skládá se ze zápisů.',
      verNet: steps.length < 2 ? '' : (last - first === 0 ? 'Skončili jste tam, kde jste začali' : 'Čistý posun ' + (last > first ? '+' : '−') + fmt(Math.abs(last - first))) + ' po ' + this.pl(steps.length - 1, 'změně', 'změnách', 'změnách') + '.',
      verSteps: steps.map((x, i) => {
        const prevV = i ? steps[i - 1].v : x.v;
        const d = x.v - prevV;
        return {
          v: fmt(x.v), by: x.by, when: x.when, why: x.why,
          num: 'v' + (i + 1),
          delta: !i ? 'první nápad' : d === 0 ? 'bez změny' : (d > 0 ? '+' : '−') + fmt(Math.abs(d)),
          deltaColor: !i ? 'var(--g-ink3)' : d > 0 ? 'var(--g-mag)' : d < 0 ? 'var(--g-ok)' : 'var(--g-ink3)',
          w: Math.max(4, Math.round((x.v - min) / span * 100)) + '%',
          fill: x.by === jA ? 'var(--g-acc)' : x.by === jM ? 'var(--g-mag)' : 'var(--g-ink2)',
          last: i === steps.length - 1,
          canBack: i !== steps.length - 1,
          back: () => {
            const prev = extra;
            const add = (extra[pickId] || []).concat([{ v: x.v, by: oba, when: 'dnes', why: 'Vrátili jste se k verzi ' + ('v' + (i + 1)) + ' z ' + x.when + '. Mezikroky zůstávají v záznamu — proto se k nim dá vrátit.' }]);
            this.setState({ verSteps: Object.assign({}, extra, { [pickId]: add }) });
            this.toast('Vráceno k ' + fmt(x.v) + ' · zapsáno jako nová verze', { icon: 'ph-clock-counter-clockwise', undo: () => this.setState({ verSteps: prev }) });
          }
        };
      }),
      verMovers: movers.map(m => ({
        name: m.name,
        d: (m.d > 0 ? '+' : m.d < 0 ? '−' : '') + fmt(Math.abs(m.d)),
        dir: (m.d === 0 ? 'nikam' : 'posun ' + (m.d > 0 ? 'nahoru' : 'dolů')),
        w: Math.round(Math.abs(m.d) / maxAbs * 100) + '%',
        fill: m.name === jA ? 'var(--g-acc)' : m.name === jM ? 'var(--g-mag)' : 'var(--g-ink3)'
      })),
      verBig: big
        ? 'Největší posun: ' + KR(big.name) + ' — ' + (big.d > 0 ? 'nahoru o ' : 'dolů o ') + fmt(Math.abs(big.d)) + '. Poslední slovo: ' + (steps[steps.length - 1].by === oba ? 'oba' : KR(steps[steps.length - 1].by)) + '.'
        : '',
      verNewVal: s.verV || '', verNewBy: s.verB || jA, verNewWhy: s.verY || '',
      verSetVal: e => this.setState({ verV: e.target.value }),
      verSetBy: e => this.setState({ verB: e.target.value }),
      verSetWhy: e => this.setState({ verY: e.target.value }),
      verAddOff: !parseInt(s.verV || '0', 10),
      verAddOp: parseInt(s.verV || '0', 10) ? 1 : .45,
      verAdd: () => {
        const v = parseInt(String(s.verV || '').replace(/\s/g, ''), 10);
        if (!v) return;
        const prev = extra;
        const kdo = s.verB === 'oba' ? oba : (s.verB || jA);
        const add = (extra[pickId] || []).concat([{ v: v, by: kdo, when: 'dnes', why: (s.verY || '').trim() || 'Bez zapsaného důvodu — za rok nikdo nebude vědět proč.' }]);
        this.setState({ verSteps: Object.assign({}, extra, { [pickId]: add }), verV: '', verY: '' });
        this.toast('Verze ' + fmt(v) + ' zapsána · ' + KR(kdo), { icon: 'ph-git-branch', undo: () => this.setState({ verSteps: prev }) });
      },
      verOpen: () => this.setState({ route: row.route, dcTab: 'mem' })
    };
  },

  // Odchod z aplikace: export bez druhého. Realistický, a nikdy nepoužitý.
  exitVals: function () {
    const s = this.state;
    const [jA, jM] = DVA(this);
    const who = s.exitWho === jA || s.exitWho === jM ? s.exitWho : jA;
    const off = s.exitOff || {};
    const made = s.exitMade || 0;
    const decs = s.decs === null || s.decs === undefined ? DESK().DEC_LIST : s.decs;
    /*
     * Platby a fotky z knihy a knihovny dvojice. Ukázka měla napsaných 1 284
     * plateb a 8 460 fotografií — a k nim 2,4 a 41 GB. Velikost fotek je
     * součet souborů v knihovně, jen když ho server zná; platby jsou text.
     */
    const G = DESK();
    const ukazka = !(G.DVOJICE || []).length;
    const statistiky = G.LIBSTATS || {};
    const cnt = k => k === 'veta' ? this.vetoList().filter(v => v.who === who).length
      : k === 'prom' ? this.promList().filter(p => p.who === who || p.to === who).length
      : k === 'tacit' ? (G.TACIT || []).length
      : k === 'forg' ? this.forgList().length
      : k === 'truth' ? this.truthList().length
      : k === 'dec' ? decs.length
      // Platby jsou řádky knihy (`TX`); `RULEXP` tu dřív počítalo vysvětlení pravidel.
      : k === 'money' ? (ukazka ? 1284 : (G.TX || []).length)
      : (ukazka ? 8460 : (statistiky.total || 0));
    // Skloňování přes this.pl, ne pevný genitiv — jinak vyjde „1 vet“.
    const UNITS = {
      veta: ['veto', 'veta', 'vet'],
      prom: ['slib', 'sliby', 'slibů'],
      tacit: ['pravidlo', 'pravidla', 'pravidel'],
      forg: ['odpuštění', 'odpuštění', 'odpuštění'],
      truth: ['sporná událost', 'sporné události', 'sporných událostí'],
      dec: ['rozhodnutí', 'rozhodnutí', 'rozhodnutí'],
      money: ['platba', 'platby', 'plateb'],
      photo: ['fotografie', 'fotografie', 'fotografií']
    };
    const unit = (k, n) => { const u = UNITS[k] || UNITS.photo; return this.pl(n, u[0], u[1], u[2]); };
    /*
     * Velikosti v `EXIT_PACK` jsou ukázkové („2,4 GB plateb"). U dvojice se
     * velikost neodhaduje: textové části mají pár kilobajtů a fotky do
     * balíčku nejdou — leží v úložišti a stahují se zvlášť.
     */
    const size = m => ukazka ? (m >= 1024 ? (m / 1024).toFixed(1).replace('.', ',') + ' GB' : m < 1 ? m.toFixed(1).replace('.', ',') + ' MB' : Math.round(m) + ' MB') : '';
    const on = EXIT_PACK.filter(p => !off[p.key]);
    const mb = on.reduce((a, p) => a + p.mb, 0);
    return {
      exitVaultOn: ukazka,
      exitWho: KR(who),
      exitPeople: [jA, jM].map(p => ({
        name: KR(p),
        weight: who === p ? 500 : 400,
        bg: who === p ? 'var(--g-acc-soft)' : 'transparent',
        fg: who === p ? 'var(--g-acc-deep)' : 'var(--g-ink2)',
        pick: () => this.setState({ exitWho: p })
      })),
      exitHead: 'Balíček pro ' + PAD(this, who, 'acc') + (ukazka ? ' · ' + size(mb) : ''),
      exitNote: 'Tohle není rozvod. Je to pojistka proti tomu, aby aplikace byla důvod zůstat. Kdo si může odejít se svou částí, zůstává dobrovolně.',
      exitHonest: 'Balíček je připravený od prvního dne. Vygenerován ' + made + '× — a doufáme, že to tak zůstane.',
      exitSize: ukazka ? size(mb) : this.pl(on.length, 'část', 'části', 'částí'),
      exitCount: this.pl(on.length, 'část', 'části', 'částí') + ' z ' + EXIT_PACK.length,
      exitRows: EXIT_PACK.map(p => ({
        name: p.name, note: p.note,
        count: unit(p.key, cnt(p.key)),
        size: size(p.mb),
        on: !off[p.key],
        mark: off[p.key] ? 'ph-square' : 'ph-check-square',
        color: off[p.key] ? 'var(--g-ink3)' : 'var(--g-acc)',
        dim: off[p.key] ? .5 : 1,
        toggle: () => this.setState({ exitOff: Object.assign({}, off, { [p.key]: !off[p.key] }) })
      })),
      exitShared: 'Co se nedělí: společné fotografie odejdou celé oběma. Z jedné vzpomínky se nedá vystřihnout jeden člověk — a aplikace se o to ani nebude snažit.',
      exitMake: () => this.setState({
        confirm: {
          title: 'Připravit balíček k odchodu?', btnBg: 'var(--g-ink)',
          body: (ukazka ? 'Vygeneruje se ' + size(mb) + ' pro ' : 'Stáhne se soubor s vybranými částmi pro ') + PAD(this, who, 'acc') + '. Druhý člověk se to nedozví — a to je záměr. Export nic nemaže a nic neruší.'
            + (ukazka ? '' : ' Fotky do souboru nejdou: originály stáhnete jako ZIP z výběru v knihovně.'),
          cta: 'Vygenerovat',
          // Skutečný soubor, ne maketa: obsah vybraných částí se poskládá
          // a stáhne. Fotografie zůstávají odkazem — ty by šly z API.
          go: () => {
            const body = {};
            on.forEach(p => {
              body[p.name] = p.key === 'veta' ? this.vetoList().filter(v => v.who === who)
                : p.key === 'prom' ? this.promList().filter(x => x.who === who || x.to === who)
                : p.key === 'tacit' ? DESK().TACIT
                : p.key === 'forg' ? this.forgList()
                : p.key === 'truth' ? this.truthList()
                : p.key === 'dec' ? decs
                : p.key === 'money' ? (ukazka
                  ? { pozn: 'Kompletní výpis plateb se stahuje z API — v prototypu jen počet.', plateb: cnt('money') }
                  : (G.TX || []).map(t => ({ datum: t[1], popis: t[2], kategorie: t[3], částka: t[4], účet: t[5] })))
                : { pozn: ukazka ? 'Originály fotografií se stahují z úložiště — v prototypu jen počet.' : 'Originály fotek jsou v úložišti galerie — stáhnete je jako ZIP z výběru v knihovně.', fotografií: cnt('photo') };
            });
            const pack = {
              pro: who,
              vytvořeno: new Date().toISOString(),
              ...(ukazka ? { velikost: size(mb) } : {}),
              části: on.map(p => p.name),
              poznámka: 'Export z aplikace Naše vzpomínky. Druhému člověku se to neoznámilo — to je záměr.',
              obsah: body
            };
            try {
              const blob = new Blob([JSON.stringify(pack, null, 2)], { type: 'application/json' });
              const url = URL.createObjectURL(blob);
              const a = document.createElement('a');
              a.href = url;
              a.download = 'odchod-' + KR(who).toLowerCase().normalize('NFD').replace(/[^a-z0-9]+/g, '') + '-' + new Date().toISOString().slice(0, 10) + '.json';
              document.body.appendChild(a);
              a.click();
              a.remove();
              setTimeout(() => URL.revokeObjectURL(url), 4000);
            } catch (e) {}
            this.setState({ confirm: null, exitMade: made + 1 });
            this.toast('Balíček stažen' + (ukazka ? ' · ' + size(mb) : '') + ' · nikomu se to neoznámilo', { icon: 'ph-download-simple' });
          }
        }
      }),
      exitVault: () => { this.setState({ route: 'x-trezor' }); this.toast('Balíček se ukládá vedle klíčů a smluv', { icon: 'ph-lock-key' }); }
    };
  },

  // Svědomí rozhodnutí: kdo to protlačil, kdo odmítl — a jak to čas zlehčil.
  svedVals: function () {
    const s = this.state;
    const adj = s.svedAdj || {};
    const rows = SVED.map(g => {
      const w = Math.max(0, Math.min(5, Math.max(0, g.w0 - g.months / 14) + (adj[g.id] || 0)));
      return Object.assign({}, g, { w: w });
    }).sort((a, b) => b.w - a.w);
    const [jA, jM] = DVA(this);
    const load = who => Math.round(rows.filter(r => r.who === who).reduce((a, r) => a + r.w, 0) * 10) / 10;
    const lA = load(jA), lM = load(jM), lB = load('oba');
    const pushed = who => rows.filter(r => r.who === who && r.kind.indexOf('protlačil') === 0).length;
    const maxL = Math.max(lA, lM, 1);
    const heavy = rows.filter(r => r.w >= 3.5);
    return {
      svedHead: heavy.length
        ? this.pl(heavy.length, 'rozhodnutí pořád tíží', 'rozhodnutí pořád tíží', 'rozhodnutí pořád tíží')
        : 'Nic z toho už netíží víc než napůl',
      svedNote: 'Každé rozhodnutí má i tuhle stranu: kdo ho protlačil, kdo ho odmítl a komu z toho zůstalo. Váha sama slábne — o ' + '1 bod za čtrnáct měsíců' + ' — pokud ji někdo ručně nevrátí zpátky.',
      svedBars: [
        { name: KR(jA) + ' nese', v: String(lA).replace('.', ','), w: Math.round(lA / maxL * 100) + '%', color: 'var(--g-acc)', note: 'protlačeno: ' + pushed(jA) },
        { name: KR(jM) + ' nese', v: String(lM).replace('.', ','), w: Math.round(lM / maxL * 100) + '%', color: 'var(--g-mag)', note: 'protlačeno: ' + pushed(jM) },
        { name: 'Nesete oba', v: String(lB).replace('.', ','), w: Math.round(lB / maxL * 100) + '%', color: 'var(--g-ink2)', note: 'rozhodnutí, za která nemůže nikdo sám' }
      ],
      svedLead: !rows.length ? 'Zatím nic zapsaného.'
        : lA !== lM
        ? KR(lA > lM ? jA : jM) + ' nese víc. Odmítnutí tíží dýl než souhlas a protlačená věc taky — tabulka níž ukáže, co z toho to je.'
        : 'Nesete to skoro stejně.',
      svedRows: rows.map(r => ({
        what: r.what, kind: r.kind, note: r.note,
        who: r.who === 'oba' ? 'oba' : KR(r.who),
        whoTag: r.who === jA ? 'tag-accent' : r.who === jM ? 'tag-accent-2' : 'tag-neutral',
        age: r.months < 12 ? r.months + ' měsíců zpátky' : Math.round(r.months / 12) + ' roky zpátky',
        cost: r.cost ? 'stálo to ' + this.kc(r.cost) : 'nestálo to peníze',
        v: r.w.toFixed(1).replace('.', ','),
        w: Math.round(r.w / 5 * 100) + '%',
        fill: r.w >= 3.5 ? 'var(--g-mag)' : r.w >= 2 ? 'var(--g-warn)' : 'var(--g-ink3)',
        state: r.w >= 3.5 ? 'pořád to tíží' : r.w >= 2 ? 'sedí to vzadu' : r.w > 0.3 ? 'už jen stopa' : 'spadlo to',
        stateColor: r.w >= 3.5 ? 'var(--g-mag)' : r.w >= 2 ? 'var(--g-warn)' : 'var(--g-ink3)',
        lighter: () => { const prev = adj; this.setState({ svedAdj: Object.assign({}, adj, { [r.id]: (adj[r.id] || 0) - 1 }) }); this.toast('„' + r.what + '“ — o bod lehčí', { icon: 'ph-feather', undo: () => this.setState({ svedAdj: prev }) }); },
        heavier: () => { const prev = adj; this.setState({ svedAdj: Object.assign({}, adj, { [r.id]: (adj[r.id] || 0) + 1 }) }); this.toast('„' + r.what + '“ — pořád to tíží. Zapsáno.', { icon: 'ph-anchor', undo: () => this.setState({ svedAdj: prev }) }); },
        talk: () => { this.setState({ route: 'x-nedele' }); this.toast('„' + r.what + '“ půjde na nedělní desetiminutovku', { icon: 'ph-chat-circle-dots' }); },
        open: () => this.setState({ route: 'x-rozhodnuti', dcTab: 'mem' })
      })),
      svedFoot: 'Rozhodnutí, které bylo v té době správné, může tížit stejně jako to špatné. Tahle tabulka to nerozsuzuje — jen to nedovolí zapomenout.'
    };
  },

  // Nejčastější začátek hádky: spouštěč, eskalace a co funguje místo toho.
  fightVals: function () {
    const s = this.state;
    const add = s.fsAdd || {}, tries = s.fsTry || {}, wins = s.fsWin || {};
    const rows = FIGHT_START.map(f => {
      const n = f.n + (add[f.id] || 0);
      const tn = f.antiN + (tries[f.id] || 0), tw = f.antiWin + (wins[f.id] || 0);
      return Object.assign({}, f, { n: n, tn: tn, tw: tw, cost: Math.round(n * f.esc * 10) / 10, rate: tn ? tw / tn : 0 });
    }).sort((a, b) => b.cost - a.cost);
    const top = rows[0];
    const [jA, jM] = DVA(this);
    const maxCost = Math.max.apply(null, rows.map(r => r.cost).concat([0.1]));
    const totalN = rows.reduce((a, r) => a + r.n, 0);
    const best = rows.slice().sort((a, b) => b.rate - a.rate)[0];
    return {
      fsBig: String(totalN),
      fsHead: !rows.length ? 'Zatím žádný zapsaný spouštěč' : 'Za půl roku ' + this.pl(totalN, 'začátek', 'začátky', 'začátků') + ' · ' + this.pl(rows.length, 'spouštěč', 'spouštěče', 'spouštěčů'),
      fsNote: 'Hádka nezačíná tématem. Začíná slovem, otázkou nebo tichem — a to se dá spočítat. Jakmile spouštěč má jméno, dá se na něj připravit odpověď dopředu.',
      fsPredict: top
        ? 'Předpověď: až padne „' + top.trig + '“, eskaluje to v ' + Math.round(top.esc * 100) + ' % případů. Co zabralo místo toho: ' + top.anti.toLowerCase() + ' — ' + top.tw + ' z ' + top.tn + '.'
        : '',
      fsBest: best ? 'Nejspolehlivější protilék je „' + best.anti.toLowerCase() + '“ — ' + Math.round(best.rate * 100) + ' % úspěšnost. Tenhle si zapamatujte první.' : '',
      fsRows: rows.map(f => ({
        trig: f.trig, kind: f.kind, anti: f.anti,
        who: f.who === 'oba' ? 'začínají oba' : 'začíná ' + KR(f.who),
        whoTag: f.who === jA ? 'tag-accent' : f.who === jM ? 'tag-accent-2' : 'tag-neutral',
        n: this.pl(f.n, 'krát', 'krát', 'krát'),
        esc: Math.round(f.esc * 100) + ' % eskaluje',
        escColor: f.esc >= 0.75 ? 'var(--g-mag)' : f.esc >= 0.6 ? 'var(--g-warn)' : 'var(--g-ink2)',
        w: Math.round(f.cost / maxCost * 100) + '%',
        fill: f.esc >= 0.75 ? 'var(--g-mag)' : 'var(--g-warn)',
        rate: Math.round(f.rate * 100) + ' %',
        rateW: Math.round(f.rate * 100) + '%',
        rateColor: f.rate >= 0.8 ? 'var(--g-ok)' : f.rate >= 0.6 ? 'var(--g-ink2)' : 'var(--g-mag)',
        tried: f.tw + ' z ' + f.tn + ' případů',
        again: () => { const prev = add; this.setState({ fsAdd: Object.assign({}, add, { [f.id]: (add[f.id] || 0) + 1 }) }); this.toast('„' + f.trig + '“ — zapsáno znovu · ' + (f.n + 1) + '×', { icon: 'ph-warning-circle', undo: () => this.setState({ fsAdd: prev }) }); },
        worked: () => { const pt = tries, pw = wins; this.setState({ fsTry: Object.assign({}, tries, { [f.id]: (tries[f.id] || 0) + 1 }), fsWin: Object.assign({}, wins, { [f.id]: (wins[f.id] || 0) + 1 }) }); this.toast('Zabralo to · úspěšnost protiléku roste', { icon: 'ph-check-circle', undo: () => this.setState({ fsTry: pt, fsWin: pw }) }); },
        failed: () => { const pt = tries; this.setState({ fsTry: Object.assign({}, tries, { [f.id]: (tries[f.id] || 0) + 1 }) }); this.toast('Nezabralo. Taky výsledek — protilék se možná nehodí na tenhle spouštěč.', { icon: 'ph-x-circle', undo: () => this.setState({ fsTry: pt }) }); },
        rule: () => { this.setState({ route: 'x-rozhodnuti', dcTab: 'exp' }); this.toast('„' + f.anti + '“ → mezi vypršovací domluvy na rok', { icon: 'ph-hourglass' }); }
      })),
      fsLoop: () => this.setState({ route: 'x-rozhodnuti', dcTab: 'loop' }),
      fsFoot: 'Spouštěč není vina. Je to informace o tom, kde je vaše konverzace nejtenčí — a jediné místo, kde má cenu měnit formu, ne obsah.'
    };
  },

  // Čtvrt-rok pro sebe: odloučení a devadesát minut bez agendy.
  quartVals: function () {
    const s = this.state;
    const plan = s.quartPlan || {}, talk = s.quartTalk || {}, took = s.quartTook || {};
    // Bez zapsaných oken čtyři prázdná — jinak by „Zapsat okno" hlásilo, že všechna stojí.
    const ctvrtleti = QUART.length ? QUART : ['Q1', 'Q2', 'Q3', 'Q4'].map((q, i) => ({ id: 'q' + (i + 1), q: q, when: null, a: null, m: null, talked: false, mins: 0, took: '', state: 'empty' }));
    const rows = ctvrtleti.map(q => {
      const p = plan[q.id] || {};
      const when = p.when || q.when;
      const mins = talk[q.id] !== undefined ? talk[q.id] : q.mins;
      const st = !when ? 'empty' : mins ? 'done' : 'booked';
      return Object.assign({}, q, { when: when, a: p.a || q.a, m: p.m || q.m, mins: mins, took: took[q.id] || q.took, state: st });
    });
    const doneRows = rows.filter(r => r.state === 'done');
    const avg = doneRows.length ? Math.round(doneRows.reduce((a, r) => a + r.mins, 0) / doneRows.length) : 0;
    const missing = rows.filter(r => r.state === 'empty');
    const next = rows.filter(r => r.state === 'booked')[0];
    const ST = { done: ['proběhlo', 'tag-accent', 'var(--g-ok)'], booked: ['zapsané', 'tag-neutral', 'var(--g-acc)'], empty: ['chybí', 'tag-accent-2', 'var(--g-mag)'] };
    return {
      quartHead: missing.length
        ? this.pl(missing.length, 'okno letos chybí', 'okna letos chybí', 'oken letos chybí')
        : 'Všechna čtyři okna letos stojí',
      quartNote: 'Každé tři měsíce se oba odloučí — víkend sám za sebe — a pak si sednou na devadesát minut bez agendy. Bez rozpočtu, bez plánu, bez seznamu. Nejde o obsah, jde o to, že se to stalo.',
      quartBig: doneRows.length + ' / 4',
      quartAvg: avg ? 'Průměrně jste mluvili ' + avg + ' minut z devadesáti domluvených.' : 'Zatím žádný rozhovor neproběhl.',
      quartNext: next ? 'Nejbližší okno: ' + next.q + ' · ' + next.when + '. Rozhovor po něm ještě není zapsaný.' : 'Žádné zapsané okno před vámi.',
      quartRows: rows.map(r => {
        const st = ST[r.state];
        return {
          q: r.q, when: r.when || 'bez data', a: r.a || 'nikdo nic nenaplánoval', m: r.m || 'nikdo nic nenaplánoval',
          state: st[0], tagClass: st[1], border: r.state === 'empty' ? 'var(--g-mag)' : 'var(--g-line2)',
          mins: r.mins ? r.mins + ' minut rozhovoru' : 'rozhovor neproběhl',
          minsColor: r.mins >= 90 ? 'var(--g-ok)' : r.mins ? 'var(--g-warn)' : 'var(--g-ink3)',
          w: Math.round(Math.min(100, r.mins / 90 * 100)) + '%',
          fill: r.mins >= 90 ? 'var(--g-ok)' : 'var(--g-warn)',
          took: r.took || '',
          hasTook: !!r.took,
          isEmpty: r.state === 'empty',
          canTalk: r.state === 'booked',
          talk90: () => { const prev = talk; this.setState({ quartTalk: Object.assign({}, talk, { [r.id]: 90 }) }); this.toast(r.q + ' · devadesát minut bez agendy proběhlo', { icon: 'ph-chat-circle-dots', undo: () => this.setState({ quartTalk: prev }) }); },
          talkShort: () => { const prev = talk; this.setState({ quartTalk: Object.assign({}, talk, { [r.id]: 45 }) }); this.toast(r.q + ' · 45 minut. Půlka je pořád víc než nic.', { icon: 'ph-clock', undo: () => this.setState({ quartTalk: prev }) }); },
          canTook: r.state === 'done' && !r.took,
          later: () => { this.setState({ route: 'x-domacnost', hsTab: 'later' }); this.toast('Co z rozhovoru vyšlo → Až budeme mít čas', { icon: 'ph-arrow-square-out' }); }
        };
      }),
      quartNewWhen: s.quartW || '', quartNewA: s.quartA || '', quartNewM: s.quartM || '',
      quartSetWhen: e => this.setState({ quartW: e.target.value }),
      quartSetA: e => this.setState({ quartA: e.target.value }),
      quartSetM: e => this.setState({ quartM: e.target.value }),
      quartAddOff: !(s.quartW || '').trim(),
      quartAddOp: (s.quartW || '').trim() ? 1 : .45,
      quartAdd: () => {
        const empty = rows.filter(r => r.state === 'empty')[0];
        if (!empty) { this.toast('Všechna čtyři okna už stojí', { icon: 'ph-info' }); return; }
        const prev = plan;
        this.setState({
          quartPlan: Object.assign({}, plan, { [empty.id]: { when: (s.quartW || '').trim(), a: (s.quartA || '').trim() || KR(DVA(this)[0]) + ' — bez plánu', m: (s.quartM || '').trim() || KR(DVA(this)[1]) + ' — bez plánu' } }),
          quartW: '', quartA: '', quartM: ''
        });
        this.toast(empty.q + ' zapsané · ' + (s.quartW || '').trim(), { icon: 'ph-calendar-plus', undo: () => this.setState({ quartPlan: prev }) });
      },
      quartTookVal: s.quartT || '',
      quartSetTook: e => this.setState({ quartT: e.target.value }),
      quartTookOff: !(s.quartT || '').trim(),
      quartTookOp: (s.quartT || '').trim() ? 1 : .45,
      quartSaveTook: () => {
        const cand = rows.filter(r => r.state === 'done' && !r.took)[0];
        if (!cand) { this.toast('Všechny proběhlé rozhovory už mají zápis', { icon: 'ph-info' }); return; }
        const prev = took;
        this.setState({ quartTook: Object.assign({}, took, { [cand.id]: (s.quartT || '').trim() }), quartT: '' });
        this.toast('Zapsáno k ' + cand.q + ' · jedna věta, nic víc', { icon: 'ph-note-pencil', undo: () => this.setState({ quartTook: prev }) });
      },
      quartSolo: () => this.setState({ klTab: 'klSolo' }),
      quartFoot: 'Devadesát minut bez agendy je nejdražší položka v celé aplikaci. Nedá se koupit, nedá se delegovat a nikdo si jich nevšimne, dokud nezmizí.'
    };
  },

  // Matice nezávislosti: co by jeden bez druhého nesl nejhůř.
  indepVals: function () {
    const s = this.state;
    const swap = s.indepSwap || {}, learn = s.indepLearn || {};
    const [jA, jM] = DVA(this);
    const rows = INDEP.map(i => ({
      ...i,
      holder: swap[i.id] ? (i.holder === jA ? jM : jA) : i.holder,
      pain: learn[i.id] ? Math.max(1, i.pain - 2) : i.pain
    }));
    const AX = ['peníze', 'duše', 'provoz'];
    const holds = who => rows.filter(r => r.holder === who);
    const painOn = who => holds(who).reduce((a, r) => a + r.pain, 0);
    const pA = painOn(jA), pM = painOn(jM);
    const hoursLeft = rows.filter(r => !learn[r.id]).reduce((a, r) => a + r.hours, 0);
    const worstFor = who => rows.filter(r => r.holder !== who).slice().sort((a, b) => b.pain - a.pain)[0];
    const wA = worstFor(jA), wM = worstFor(jM);
    return {
      indepHead: !rows.length ? 'Matice je prázdná — zapište, co kdo drží a jak moc by to bez něj bolelo'
        : 'Bez ' + PAD(this, jM, 'gen') + ': bolest ' + pM + ' z 30 · bez ' + PAD(this, jA, 'gen') + ': ' + pA + ' z 30',
      indepNote: 'Nezávislost není odchod. Je to schopnost přežít týden, kdy je druhý na kapačkách — a vědomí, co přesně by chybělo. Jediná chvilka pak má novou cenu.',
      indepBars: [
        { name: KR(jA) + ' drží', v: String(holds(jA).length) + ' věcí · bolest ' + pA, w: Math.round(pA / Math.max(pA, pM, 1) * 100) + '%', color: 'var(--g-acc)' },
        { name: KR(jM) + ' drží', v: String(holds(jM).length) + ' věcí · bolest ' + pM, w: Math.round(pM / Math.max(pA, pM, 1) * 100) + '%', color: 'var(--g-mag)' }
      ],
      indepWorst: (wA ? KR(jA) + ' bez druhého nejvíc na „' + wA.what.toLowerCase() + '“. ' : '') + (wM ? KR(jM) + ' na „' + wM.what.toLowerCase() + '“.' : ''),
      indepHours: !rows.length ? ''
        : hoursLeft
        ? this.pl(hoursLeft, 'hodina', 'hodiny', 'hodin') + ' předávání by celou matici vyrovnalo — a je to nejlepší investice v téhle aplikaci.'
        : 'Celá matice je vyrovnaná. Oba unesou všechno.',
      indepAxes: AX.map(ax => ({
        name: ax,
        items: rows.filter(r => r.axis === ax).map(r => ({
          what: r.what, note: r.note, fix: r.fix,
          holder: KR(r.holder),
          holderTag: r.holder === jA ? 'tag-accent' : 'tag-accent-2',
          pain: r.pain + ' z 5',
          painColor: r.pain >= 4 ? 'var(--g-mag)' : r.pain >= 3 ? 'var(--g-warn)' : 'var(--g-ink2)',
          w: Math.round(r.pain / 5 * 100) + '%',
          fill: r.pain >= 4 ? 'var(--g-mag)' : 'var(--g-warn)',
          hours: r.hours ? r.hours + ' h na předání' : 'nedá se předat za odpoledne',
          learned: !!learn[r.id],
          learnLabel: learn[r.id] ? 'předáno' : 'Naučit se to',
          learn: () => {
            if (learn[r.id]) return;
            const prev = learn;
            this.setState({ indepLearn: Object.assign({}, learn, { [r.id]: true }) });
            this.toast('„' + r.what + '“ předáno · bolest ' + r.pain + ' → ' + Math.max(1, r.pain - 2), { icon: 'ph-graduation-cap', undo: () => this.setState({ indepLearn: prev }) });
          },
          swap: () => { const prev = swap; this.setState({ indepSwap: Object.assign({}, swap, { [r.id]: !swap[r.id] }) }); this.toast('„' + r.what + '“ na měsíc přebírá ' + KR(r.holder === jA ? jM : jA), { icon: 'ph-user-switch', undo: () => this.setState({ indepSwap: prev }) }); },
          vault: () => { this.setState({ route: 'x-trezor' }); this.toast('Co k „' + r.what + '“ patří, má být v trezoru', { icon: 'ph-lock-key' }); }
        }))
      })),
      indepBus: () => this.setState({ hsTab: 'bus' }),
      indepFoot: 'Tahle matice se nečte proto, aby jeden odešel. Čte se proto, aby zůstávání nebylo z bezmoci.'
    };
  },

  // Síť důvěry: jediný signál z vnějšího světa dovnitř.
  trustVals: function () {
    const s = this.state;
    const called = s.trustCalled || {}, told = s.trustTold || {};
    const rows = TRUST.slice().sort((a, b) => (b.believes - b.worries) - (a.believes - a.worries));
    const believers = rows.filter(r => r.believes >= 4).length;
    const worriers = rows.filter(r => r.worries >= 3);
    const oneSided = rows.filter(r => r.kind === 'slyší jen jednu stranu');
    const KIND = { 'věří': 'tag-accent', 'věří s otázkou': 'tag-neutral', 'bojí se': 'tag-accent-2', 'slyší jen jednu stranu': 'tag-accent-2' };
    return {
      trustHead: !rows.length ? 'Síť zatím nikdo nezapsal' : this.pl(rows.length, 'člověk drží vaši síť', 'lidé drží vaši síť', 'lidí drží vaši síť') + ' · ' + this.pl(worriers.length, 'má obavy', 'mají obavy', 'má obavy'),
      trustNote: 'Váš vztah je pro pár lidí vnější infrastruktura — plánují podle něj vlastní život. To je jediný signál, který do téhle aplikace přichází zvenku, a nedá se vyrobit uvnitř.',
      trustBig: String(believers) + ' / ' + rows.length,
      trustWorry: worriers.length
        ? 'Kvůli vám se v noci budí ' + worriers.map(w => w.name.split(' — ')[0]).join(' a ') + '. Nemusíte s tím nic dělat — ale je dobré vědět, že to tak je.'
        : 'Nikdo se kvůli vám nebudí.',
      trustOneSide: oneSided.length
        ? oneSided.length + '× slyší někdo jen jednu stranu. Bez druhé si skládá vlastní verzi — a věří jí víc než vám.'
        : 'Každý, kdo o vás slyší, slyší obě strany.',
      trustRows: rows.map(t => ({
        name: t.name, signal: t.signal, kind: t.kind,
        tagClass: KIND[t.kind] || 'tag-neutral',
        side: t.side === 'oba' ? 'na obou stranách' : 'blíž ' + PAD(this, t.side, 'dat'),
        last: 'naposledy ' + t.last,
        lastColor: t.last.indexOf('týd') > 0 ? 'var(--g-mag)' : 'var(--g-ink3)',
        bel: t.believes + ' z 5', belW: Math.round(t.believes / 5 * 100) + '%',
        wor: t.worries + ' z 5', worW: Math.round(t.worries / 5 * 100) + '%',
        border: t.worries >= 3 ? 'var(--g-mag)' : 'var(--g-line2)',
        called: !!called[t.id],
        callLabel: called[t.id] ? 'ozvali jste se' : 'Ozvat se',
        call: () => {
          if (called[t.id]) return;
          const prev = called;
          this.setState({ trustCalled: Object.assign({}, called, { [t.id]: true }) });
          this.toast(t.name.split(' — ')[0] + ' · zapsáno mezi návštěvy a kontakty', { icon: 'ph-phone-call', undo: () => this.setState({ trustCalled: prev }) });
        },
        canTell: t.kind === 'slyší jen jednu stranu' || t.kind === 'věří s otázkou',
        told: !!told[t.id],
        tell: () => {
          if (told[t.id]) return;
          const prev = told;
          this.setState({ trustTold: Object.assign({}, told, { [t.id]: true }) });
          this.toast('Druhá strana doplněná · ' + t.name.split(' — ')[0] + ' už nemá jen půlku', { icon: 'ph-users-three', undo: () => this.setState({ trustTold: prev }) });
        },
        fam: () => this.setState({ hsTab: 'fam' })
      })),
      trustSpk: () => this.setState({ hsTab: 'spk' }),
      trustFoot: 'Tenhle seznam se nedá vylepšit uvnitř aplikace. Jediná funkční akce je zvednout telefon.'
    };
  },

  // Vypršovací domluvy: pravidla, která se sama smažou, když je neobnovíte.
  expVals: function () {
    const s = this.state;
    const ren = s.expRen || {}, dead = s.expDead || {}, et = s.expEt || {};
    // Odpočet běží podle skutečného kalendáře, ne podle pevného čísla v datech:
    // zítra zbývá o den méně, i když nikdo nic neudělá. Co dojde na nulu, je
    // vypršelé — server (galerie:expire) to jen zapíše, i když se nikdo nedívá.
    const today = new Date();
    const dayMs = 86400000;
    const parseCs = str => {
      const p = String(str).split('.').map(x => parseInt(x.trim(), 10));
      return p.length === 3 ? new Date(p[2], p[1] - 1, p[0]) : null;
    };
    const rows = EXPIRE.map(e => {
      const made = parseCs(e.made);
      const term = 365 * (1 + (ren[e.id] || 0));
      const left = made ? Math.round(term - (today - made) / dayMs) : e.days + (ren[e.id] || 0) * 365;
      const eternal = et[e.id] !== undefined ? et[e.id] : e.eternal;
      return { ...e, eternal: eternal, days: Math.max(0, left), dead: !!dead[e.id] || (!eternal && left <= 0) };
    }).sort((a, b) => (a.eternal ? 1 : 0) - (b.eternal ? 1 : 0) || a.days - b.days);
    const live = rows.filter(r => !r.dead && !r.eternal);
    const soon = live.filter(r => r.days <= 60);
    const near = live[0];
    return {
      expHead: soon.length
        ? this.pl(soon.length, 'domluva vyprší do dvou měsíců', 'domluvy vyprší do dvou měsíců', 'domluv vyprší do dvou měsíců')
        : 'Nic nevyprší do dvou měsíců',
      expNote: 'Pravidlo s datem se nemusí rušit — stačí ho neobnovit. Nikdo pak nemá pocit, že něco porušil, a nikdo nemusí přiznat, že už mu to nevyhovuje. Trvalé zůstávají jen ty, které trvalé být mají.',
      expBig: String(live.length),
      expNear: near
        ? 'Nejbližší: „' + near.rule + '“ za ' + near.days + ' dní. Pokud ji neobnovíte, přestane existovat — a nikdo nebude mít pocit, že ji porušil.'
        : 'Žádná domluva s datem neběží.',
      expEternalLine: this.pl(rows.filter(r => r.eternal).length, 'domluva je trvalá', 'domluvy jsou trvalé', 'domluv je trvalých') + '. Ty se neobnovují a nevyprší — a je jich schválně málo.',
      expRows: rows.map(r => ({
        rule: r.rule, why: r.why, made: 'domluveno ' + r.made,
        eternal: r.eternal,
        state: r.dead ? 'vypršelo' : r.eternal ? 'trvalá' : r.days <= 30 ? 'vyprší za ' + this.pl(r.days, 'den', 'dny', 'dní') : 'platí ' + this.pl(r.days, 'den', 'dny', 'dní'),
        tagClass: r.dead ? 'tag-neutral' : r.eternal ? 'tag-accent' : r.days <= 60 ? 'tag-accent-2' : 'tag-neutral',
        color: r.dead ? 'var(--g-ink3)' : r.eternal ? 'var(--g-ink)' : r.days <= 60 ? 'var(--g-mag)' : 'var(--g-ink)',
        strike: r.dead ? 'line-through' : 'none',
        dim: r.dead ? .55 : 1,
        border: r.dead ? 'var(--g-line2)' : r.eternal ? 'var(--g-acc)' : r.days <= 60 ? 'var(--g-mag)' : 'var(--g-line2)',
        w: r.eternal ? '100%' : Math.round(Math.min(100, r.days / 365 * 100)) + '%',
        fill: r.eternal ? 'var(--g-acc)' : r.days <= 60 ? 'var(--g-mag)' : 'var(--g-ink3)',
        renewed: (ren[r.id] || 0) ? (ren[r.id] || 0) + '× obnoveno' : 'nikdy neobnoveno',
        canRenew: !r.eternal && !r.dead,
        renew: () => { const prev = ren; this.setState({ expRen: Object.assign({}, ren, { [r.id]: (ren[r.id] || 0) + 1 }) }); this.toast('„' + r.rule + '“ obnoveno na další rok', { icon: 'ph-arrows-clockwise', undo: () => this.setState({ expRen: prev }) }); },
        letGo: () => this.setState({
          confirm: {
            title: 'Nechat vypršet?', btnBg: 'var(--g-ink)',
            body: '„' + r.rule + '“ se za ' + r.days + ' dní sama smaže. Nikdo ji nezruší, nikdo ji neporuší. Zmizí a zůstane jen v historii.',
            cta: 'Nechat vypršet',
            go: () => { const prev = dead; this.setState({ confirm: null, expDead: Object.assign({}, dead, { [r.id]: true }) }); this.toast('„' + r.rule + '“ vypršelo · bez viníka', { icon: 'ph-hourglass-low', undo: () => this.setState({ expDead: prev }) }); }
          }
        }),
        canEternal: !r.eternal && !r.dead,
        makeEternal: () => { const prev = et; this.setState({ expEt: Object.assign({}, et, { [r.id]: true }) }); this.toast('„' + r.rule + '“ je od teď trvalá · bez data', { icon: 'ph-infinity', undo: () => this.setState({ expEt: prev }) }); },
        canDrop: r.eternal,
        dropEternal: () => this.setState({
          confirm: {
            title: 'Sundat trvalost?', btnBg: 'var(--g-mag)',
            body: '„' + r.rule + '“ dostane datum a za rok vyprší, pokud ji neobnovíte. U některých pravidel je to úleva. U jiných je to zpráva sama o sobě.',
            cta: 'Dát tomu datum',
            go: () => { const prev = et; this.setState({ confirm: null, expEt: Object.assign({}, et, { [r.id]: false }), expRen: Object.assign({}, ren, { [r.id]: 1 }) }); this.toast('„' + r.rule + '“ má teď datum · rok', { icon: 'ph-calendar-x', undo: () => this.setState({ expEt: prev }) }); }
          }
        }),
        tacit: () => this.setState({ dcTab: 'tacit' })
      })),
      expDeadLine: Object.keys(dead).length
        ? this.pl(Object.keys(dead).length, 'domluva už vypršela', 'domluvy už vypršely', 'domluv už vypršelo') + '. Nikdo je neporušil — jen přestaly platit.'
        : 'Zatím nic nevypršelo.',
      expFoot: 'Pravidlo, které nikdo neobnoví, nebylo potřeba. Pravidlo, které se obnovuje pětkrát, má být trvalé — a je to vidět v tabulce, ne v hádce.'
    };
  }
  };
})();
