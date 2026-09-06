# Napojení obsahu prototypu na databázi

Prototyp kreslí z `window.GalerieData` — 180 kolekcí, které dosud pocházely
z `galerie-data.js`, tedy z ukázkových dat. Tenhle dokument je plán, jak je nahradit
skutečným obsahem, a zápis toho, co je hotové.

## Inventura

| | Počet | Co s tím |
| --- | --- | --- |
| Katalogy rozhraní | 53 | **zůstávají statické** — názvy obrazovek, ikony, měsíce, typy událostí, prázdné stavy. Nejsou to data dvojice. |
| Kolekce obsahu | 127 | patří do databáze |
| Řádků obsahu v ukázce | 823 | |

## Jak se obsah dostane na obrazovku

Dokument prototypu si při načtení **rozebere všech 180 kolekcí do konstant**
(`const { TX, BUD, … } = window.GalerieData`). Pozdější `GalerieData.TX = …` proto
nikam nedojde — obrazovka drží původní pole. Kolekce se tedy **vyměňuje na místě**:
u pole se vymění obsah, u objektu se přepíšou klíče. Konstanta ukazuje pořád na
tentýž objekt a při dalším překreslení se objeví skutečná data.

Klíče, které server nepošle, **zůstávají ukázkové**. `FIN` má vedle účtů ještě
`upcoming`, `alerts`, `rules` a `imports`; kdyby se objekt vyprázdnil, obrazovka
spadne na `undefined.filter`.

Výjimkou jsou kolekce, které server dodává **celé**. Ty vypisuje poskytovatel
v `uplne()` a odpověď je nese vedle dat; klient v nich smí smazat i to, co
nepřišlo. Zatím je tak označený jen `PERSONS`: nechat vedle skutečných tváří
ukázkovou Kláru znamená ukazovat dvojici někoho, kdo neexistuje.

Skalární kolekce (číslo, řetězec) takhle vyměnit nejdou — u nich zůstává hodnota
z načtení stránky. Zatím se to týká jen `INCOMES`, které se posílá i v `BUD.income`.

### Dvě věci, bez kterých to platilo jen napůl

Výměna na místě funguje jen tehdy, když se konstanta v dokumentu a nádoba,
do které server zapisuje, **opravdu potkají**. Dlouho se nepotkávaly.

*Runtime prototypu spouští `galerie-data.js` opakovaně* a pokaždé přiřadí nový
objekt s novými poli. Konstanty ale vznikly jediným rozebráním v okamžiku, kdy
se dokument překládal. Když po tom rozebrání přišlo další načtení, ukazovaly
konstanty na pole předchozí generace a server doplňoval data do nové — do polí,
na která se už nikdo nedíval. Poznat to šlo jen na datech, která dorazí pozdě:
po obnovení stránky obrazovka ukazovala skutečné řádky, ale zápis udělaný za
běhu se objevil až po dalším obnovení. Vypadalo to jako pomalý server. Hlavička
proto obsah nového načtení **přelévá do staré nádoby** a tu vrací do nového
objektu; všechny generace pak sdílejí táž pole.

*Šťouchnutí k překreslení bylo prázdné.* Volalo se `dispatchEvent(new Event('resize'))`,
jenže obsluha v prototypu zní `if (w !== this.state.vw) this.setState(...)` —
a šířka se při dotažení dat nemění nikdy. Data se tedy objevila teprve při
prvním kliknutí, které aplikaci překreslilo kvůli něčemu jinému. Vypadalo to,
že to funguje, protože se na obrazovku obvykle přišlo kliknutím. Spolehlivá
cesta vede přes společnou datovou vrstvu (`galerie-store.js`), na kterou je
prototyp přihlášený a v jejíž obsluze volá `setState` bez podmínky.

Prototyp čte `GalerieData` **synchronně při vykreslení** — nemůže na server čekat.
Ze serveru se proto data **přimíchávají do už existujícího objektu**, stejně jako
u administrace a postranního panelu: hodnota se čte přes přístupovou vlastnost,
která vrací serverovou verzi, jakmile dorazí, a do té doby ukázkovou.

```
GET /api/data/{skupina}  →  { data: { TX: [...], BUD: {...} } }
```

Skupiny, ne jedna velká odpověď: obrazovka financí nepotřebuje čekat na kuchařku.
Každá kolekce má strop na počet řádků — obrazovky ukazují desítky položek, ne
tisíce, a stažení celé knihovny do prohlížeče by aplikaci zabilo.

**Ukázková data zůstávají jako záloha.** Když skupina nedorazí (chyba sítě, prázdná
databáze), obrazovka se nerozpadne. Prázdný seznam a rozbitá aplikace vypadají
z pohledu člověka stejně, a jedno z toho jde spravit obnovením stránky.

## Kde to doopravdy stojí

Změřeno v prohlížeči proti běžícímu serveru, ne odhadem:

| | Počet |
| --- | --- |
| Klíčů v `window.GalerieData` | **190** — z toho 9 jsou pomocné funkce, ne data |
| Kolekcí celkem | **181** |
| Obsluhuje server | **142** (140 přes `/api/data`, `ADMIN` a `STORAGE` přes přístupovou vrstvu) |
| Z toho kolekcí, které prototyp má | **119** |
| Katalogy rozhraní — zůstávají statické záměrně | ~53 (`SCEN` a `RITUALS` se ukázaly být číselníky) |
| Přihlašovací záslepky prototypu — **daty se stát nesmí** | 6 |
| **Obsah dvojice, který ještě není napojený** | **0** |

Dvacet jedna ze sto čtyřiceti kolekcí prototyp v `GalerieData` vůbec nemá — mřížku fotek
(`PHOTOS`, `DAYS`, `ALBUMS`, `ATREE`), čísla u nabídky (`NAVCNT`, `TOTAL`),
měnu, spíž a odkazy si dokument vyráběl sám ve funkcích. Server je dodává
navíc a přepínače v dokumentu je berou přednostně.

Ze stavu páru se pořád ukládají **čtyři obsahové klíče** (`quar`, `emLog`,
`hsVisits`, `pmMine`). `quar` je mezi nimi jen formálně — prototyp do něj
nikdy nezapisuje; `emLog` píše server sám do protokolu; `pmMine` nemá
v prototypu obrazovku, kde by se dal změnit.

Zachycených je **čtyřicet pět**. Všechny případy, kdy stav přebíjel skutečná
data nebo kdy se rozhodnutí nedostalo dál než do prohlížeče, jsou vyřešené.

## Pořadí

Podle toho, kde už skutečný obsah je a kde na něm záleží:

| # | Skupina | Kolekce | Zdroj | Stav |
| --- | --- | --- | --- | --- |
| 1 | **Finance** | `TX`, `BUD`, `FIN.accounts`, `INCOMES`, `SHARED`, `MENA` | `budgets`, `budget_category_limits`, `transactions`, `finance_categories`, `wallets`, `shared_expenses` | hotovo — **rozbory zbývají**, viz níž |
| 2 | **Knihovna** | `DAYS`, `PHOTOS`, `ALBUMS`, `ATREE`, `PERSONS`, `DUP_GROUPS`, `YBCH`, `NAVCNT`, `TOTAL`, `MOBIL` | `media_items`, `media_variants`, `people`, `media_person`, `tags`, `albums`, `album_media`, `duplicate_groups` | hotovo |
| 3 | **Plánování** | `CALEV`, `ATASKS`, `LATER_ITEMS`, `AL.doneTasks`, `EVSEED` | `calendar_events`, `event_participants`, `event_reminders`, `shared_todos`, `shared_todo_lists`, `life_events` | hotovo, **píše i zpátky** |
| 4 | **Domácnost** | `HOUSE_CHORES`, `HOUSE_LOG`, `HOUSE_WEEK`, `HOUSE_DUES`, `HOUSE_INV`, `PANTRY` | `house_chores`, `house_chore_log`, `house_dues`, `house_inventory`, `house_pantry`, `house_week(_capacity)` | hotovo |
| 5 | **Cesty a místa** | `TRIPS`, `TRIP_BY_TITLE`, `NOWTRIP`, `PLACES`, `PLACE_BY_TITLE` | `trips`, `trip_days`, `trip_activities`, `trip_expenses`, `trip_budget_limits`, `trip_packing_items`, `trip_document_checks`, `travel_journal_entries`, `places`, `place_plans`, `place_notes` | hotovo |
| 6 | **Vztah** | `DEC_LIST`, `ARB`, `VERSIONS`, `DEC_COOL`, `SPOR_*`, `VETO_*`, `PROMISES`, `NUDGES`, `PATIENCE` | `couple_decisions`, `couple_decision_revisions`, `couple_cooling_purchases`, `couple_disagreement_points`, `couple_veto_proposals`, `couple_vetoes`, `couple_promises`, `couple_nudges`, `couple_nudge_reminders` | hotovo, **píše i zpátky** |
| 7 | **Zdraví a cyklus** | `CYC_BASE`, `CYC_STARTS`, `KL_DAYS`, `KL_MOOD` | `cycle_days`, `cycle_settings`, `wellbeing_moods` | hotovo |
| 8 | **Sdílení a systém** | `SHARES`, `GUEST_Q`, `KAPS`, `VAULT_ITEMS`, `OFFPACKS` | `shared_links`, `guest_uploads`, `time_capsules`, `media_items.is_hidden` | hotovo |
| 9 | **Zprávy a hlasovky** | `MSGS`, `MSGFILES`, `AMSG` | `chat_messages` | hotovo |
| 10 | **Kuchařka** | `RECIPES`, `RECIPE_BY_TITLE` | `recipes`, `recipe_ingredients`, `recipe_steps`, `recipe_cooking_sessions` | hotovo |
| 11 | **Dárky a přání** | `GIFT_WISHES`, `GIFT_BUYS`, `GIFT_IDEAS`, `GIFT_OCC` | `gift_ideas`, `gift_budgets` | hotovo |
| 12 | **Deník a milníky** | `ADIARY.diary`, `ADIARY.ms`, `ADIARY.cycleLog` | `journal_entries`, `relationship_milestones`, `cycle_days` | hotovo |
| 13 | **Pravidla a vzpomínky** | `RULEDEF`, `RULOG`, `MEMS` | `automation_rules`, `automation_runs`, `generated_memories` | hotovo |
| 14 | **Finanční rozbory** | `ENV`, `INFL`, `SEASON`, `TRIPCOST` | `transactions`, `budget_goals`, `trip_expenses` | hotovo |
| 15 | **Úklid knihovny** | `QUAR`, `AGRID`, `PJOBS` | `media_items.is_archived`, `photo_books`, `duplicate_groups` | hotovo, **píše i zpátky** |
| 16 | **Štítky a lidé v záložkách** | `ATAGS`, `APEOPLE` | `tags`, `media_tag`, `people` | hotovo |
| 17 | **Záložky a sloupce financí** | `ATX`, `ABARS.bud/year/res/fc` | `transactions`, `finance_recurring`, `bank_connections`, `budget_category_limits` | hotovo |
| 18 | **Systém** | `DATA_HEALTH`, `SECLIFE`, `ABARS.health/risk` | `wallets`, `media_items`, `cycle_days`, `storage_connections`, `jobs`, `failed_jobs` | hotovo |
| 19 | **Sloupce úzkého rozvržení** | `ABARS.cap`, `ABARS.cycle` | `house_week(_capacity)`, `cycle_days` | hotovo |
| 20 | **Přepínače nastavení** | `AFORMS` | `bank_connections`, `finance_settings`, `user_settings`, `legacy_plans` | hotovo, **píše i zpátky** |
| 21 | **Datování skenů** | `DATING` | `media_items` — sousední soubor, tentýž import, album, přístroj | hotovo, **píše i zpátky** |
| 22 | **Rok v číslech** | `ABARS.zprCisla` | tytéž tabulky jako „kdo sekci živí", jen po letech | hotovo |
| 23 | **Co se ty dny dělo** | `KL_EV` | `calendar_events`, `trips`, `budget_category_limits` | hotovo — posílá se jen se zapsanou náladou |
| 24 | **Klid a pohoda** | `KL_EN`, `KL_ATTN`, `KL_TASKS`, `KL_ASK_LOG`, `KL_ASK_NOW` | `wellbeing_energy`, `wellbeing_attention`, `wellbeing_tasks`, `wellbeing_answers` | hotovo, **píše i zpátky** |
| 25 | **Příběh a výstupy** | `STORY`, `STORYMS`, `PORDERS`, `EM_ITEMS`, `EM_LOG`, `PAPER_ROWS`, `GV_C`, `ABARS.tier` | `couple_story_*`, `print_orders`, `emergency_access_*`, `paper_backup_rows`, `guest_comments`, `watch_titles` | hotovo, **píše i zpátky** |
| 26 | **Mechanismy pro dva** | `FAV`, `FORGIVEN`, `ANTI`, `ML_LOAD`, `FAMILY`, `TRUTHS`, `PAUSE_LOG`, `PAUSE_PLAN`, `TICHO` | `couple_favours`, `couple_forgiven`, `couple_anti_budget`, `couple_mental_load`, `couple_family_contacts`, `couple_truths`, `couple_pause` | hotovo, **píše i zpátky**; `TICHO` se počítá |
| 27 | **Odvozené — bez vlastní tabulky** | `HOURS`, `RECON`, `CAS_ROWS`, `COSTMEAN`, `DELAY`, `EST`, `SURPRISE`, `CONFLICTS`, `DISP`, `SOLO` | `media_items`, `house_chore_log`, `house_dues`, `budget_category_limits`, `transactions`, `drive_conflicts`, `couple_disagreement_points`, `event_participants` | hotovo — počítá se, neukládá |
| 28 | **Rozhodování** | `BUS`, `PM_DEC`, `PM_MINE`, `PM_THEIRS`, `PM_HIST`, `PAST_DEC`, `PAST_CASES`, `REVISIT` | `couple_bus_items`, `couple_premortems`, `couple_premortem_risks`, `couple_past_cases`, `couple_decision_inputs` | hotovo, **zapisuje se formulářem** (`/api/zaznamy/…`) |
| 29 | **Úložiště a koš** | `DISK`, `TRASH`, `DVOJICE` | `storage_connections`, `media_items`, `media_variants`, `gallery_space_user` | hotovo, **maže i na Disku** (`/api/kos/…`, `/api/uloziste/prenest`) |
| 30 | **Předpověď a horizont** | `P60`, `HORIZON` | `finance_recurring`, `wallets`, `transactions` | hotovo — počítá se, neukládá |
| 31 | **Rozhodl čas** | `AUTO_DEC` | `couple_cooling_purchases`, `shared_todos` | hotovo — tři vzorce, žádná nová tabulka |
| 32 | **Účet radosti** | `JOY` | `calendar_events.activity_kind`, `wellbeing_moods`, `transactions` | hotovo — počítá se, **zařazení události zapisuje dvojice** |
| 33 | **Kdo to vyřídil** | `VIS_ROWS`, `SPEAK` | `couple_outreach_log`, `couple_family_contacts` | hotovo, **zapisuje se formulářem** (`/api/zaznamy/vyrizeno`) |
| 34 | **Mlčky platná pravidla** | `TACIT` | `house_chore_log`, `transactions`, `calendar_events`, `couple_cooling_purchases` | hotovo — čtyři hledače, žádná nová tabulka |
| 35 | **Předpověď** | `WEATHER` | Open-Meteo přes `FreeTravelDataService`, poloha z `media_items` | hotovo — počítá se, tři hodiny v cache |
| 36 | **Tisk** | `PORDERS`, `POSTEPS` | `print_orders` | hotovo, **objednávka se zapisuje** (`/api/tisk/…`) |
| 37 | **Vzkazy hostů** | `GV_C` | `guest_comments`, `shared_links.allow_comments` | hotovo, **píše host** (`POST /s/{token}/vzkaz`) |

## Knihovna: co se muselo změnit v dokumentu

Mřížka fotek se v prototypu **nebrala z dat vůbec** — dokument si ji vyráběl sám
ze šesti napsaných dnů (`days()`, `photos()`). Napojení proto znamenalo i pár
zásahů do dokumentu; každý drží původní chování, když server nic nepošle:

- `albums[1]` jako záložka pro detail alba. Se sedmi napsanými alby to fungovalo,
  se dvěma skutečnými byl druhý prvek `undefined` a **celá aplikace spadla**
  na `album.name`. Teď je za tím ještě `albums[0]`.
- `AMISS` byl seznam ukázkových identifikátorů, podle kterého úklid poznával
  fotky bez data a bez místa. O skutečných fotkách nevěděl nic, takže hlásil
  nulu. Nahradil ho `mChybi(p, …)`, který se ptá fotky samotné (`miss`).
- Podalba se v detailu **vymýšlela** (`Zadar, Krka, Plitvice…`) podle počtu
  potomků. Teď přicházejí ze skutečné hierarchie (`children`), když existuje.
- Náhledy: dlaždice kreslila barevný přechod z čísla. `bg` teď nese hodnotu pro
  `background`, takže `url(…) center/cover` sedne beze změny značek. Kde
  zmenšenina není, posílá server týž přechod, jaký si dokument počítal sám.
- Odznaky v nabídce a součet na úvodní obrazovce byly napevno v katalogu
  (`24 316`, `318`, `2`, `16`). Teď je počítá server (`NAVCNT`, `TOTAL`).
- Telefonní rozvržení mělo knihovnu v modulových konstantách, kam se nedalo
  dosáhnout. Drží ji ve `window.GalerieMobil`, takže se dá vyměnit na místě.

Náhled má **podepsanou adresu** mimo `auth:sanctum`: dlaždici stahuje prohlížeč
jako obrázek v CSS, kam hlavičku `Authorization` nepřidá, a token v adrese by
zůstal v historii i v přístupovém logu. Podpis platí pro jediný soubor a končí
na konci zítřejšího dne — tedy ve stejný okamžik pro všechny dlaždice, aby si
je prohlížeč mohl nechat v paměti.

## Plánování: co píše zpátky

### Cesta zpátky: `PlanovaniVeStavu`

Kalendář a úkoly píšou i zpátky — a je to **opatrnější** zápis než u ostatních
skupin, protože `calendar_events` a `shared_todos` **vlastní aplikace sama**.
Ptají se na ně připomínky, automatizace, cesty i výroční přehled; naplnit je
vymyšlenými řádky z ukázky by bylo horší než nezapsat nic.

Z toho plynou tři pravidla:

- **Ukázkové řádky se neimportují.** Nová událost i nový úkol mají v prototypu
  vlastní předponu (`ev-n`, `-n`, `-r`); napsané řádky (`ev0`, `all0-0`, `w1`)
  ji nemají a přejdou se. Do nástěnky, na které není jediný skutečný úkol, se
  nezapisuje vůbec.
- **Maže se jen to, co server sám poslal.** Události se odstraňují jen uvnitř
  okna (−90 až +400 dní); klient může mít v paměti starší seznam z doby, kdy
  okno leželo jinde. Úkol se navíc nemaže, jen ruší — „uklidit hotové" nemá
  znamenat ztrátu historie.
- **Nástěnka domácnosti je výřez, ne celý seznam.** Zásah v ní neruší úkoly,
  které do domácnosti nepatří.

Aby se zápis měl kam trefit, nesou řádky ze serveru tři pole navíc:
`[co, kdo, termín slovy, hotovo, **identifikátor**, **termín datem**,
**priorita**]`. Bez identifikátoru by se úprava neměla kam zapsat, bez data by
se termín musel hádat z popisku a bez priority by každý úkol po termínu skončil
jako „spěchá", protože si ji prototyp z popisku dopočítává sám. Prototyp čte
první čtyři pole a napsaného řádku se to netýká.

**Sloupec je termín.** Sloupce nástěnky jsou odvozené z data, takže přetažení
karty do „Někdy" termín zruší a do „Tento týden" ho nastaví — jinak by karta po
obnovení skočila zpátky. Popisek termínu je volný text; překládá se jen to, co
prototyp sám nabízí („dnes", „zítra", „za týden", názvy dnů, `20. 9.`), a co
přeložit nejde, nechá termín být. Když se popisek proti serveru nezměnil, drží
se **uložený čas** — klientovi jde ven jen den a vracet ho zpátky by z „do
dvanácti" udělalo půlnoc.

Připomenutí se překládá oběma směry: čtyři možnosti dialogu ↔ okamžik
v `event_reminders`. Zrušené se maže, jinak by chodilo dál.

## Domácnost: první skupina, která píše i zpátky

Domácnost je jediná oblast, kterou **nevlastní žádný jiný modul** — vznikla
v prototypu a nikde jinde v aplikaci není. Kdyby dostala jen tabulky a čtení,
dopadlo by to hůř než dosud: po prvním kliknutí by se stav páru a databáze
rozešly. **Tabulka, do které nikdo nepíše, je horší než žádná tabulka.**

Zápis proto vede přes `DomacnostVeStavu`, obdobu `AdminVeStavu`: klíče `chores`,
`choreLog`, `dues` a `inv` se ze stavu vyzvednou, provedou v databázi a ze stavu
**vyhodí**. Při dalším načtení si je prototyp vezme z `/api/data/domacnost`.

Tři místa, kde to nejde dělat naivně:

- **Klient si v běžícím sezení pamatuje vlastní identifikátory** (`c1`, `q3`),
  protože jeho stav se překreslí až po obnovení stránky. Řádky se proto hledají
  podle uuid **i** podle `client_id`; jinak druhá změna v témž sezení založí
  duplikát.
- **První dotek prázdné domácnosti ji založí** z toho, co bylo na obrazovce.
  Dvojice s tím rozdělením právě pracovala; zahodit ho by znamenalo, že jejich
  klik po obnovení stránky zmizí.
- **Historie práce se neimportuje.** Prototyp posílá celý seznam, takže při
  prvním kliknutí přijde i dvacet ukázkových řádků. Zapisuje se jen ten, který
  má podpis čerstvého kliknutí (první v pořadí, popisek „právě teď") — jinak by
  měly všechny dnešní čas a statistika posledního měsíce by lhala hned v první
  vteřině. „Vzít zpět" takový záznam zase smaže.

Lhůta, která ze seznamu zmizí, se **nemaže**: dostane `settled_at`. Rok co rok
se ptáme, kdy naposledy byla STK.

## Cesty: co se neposílá a proč

**Počasí (`WEATHER`) zůstává napsané.** Aplikace předpověď odnikud nebere;
vymyslet ji by znamenalo tvrdit dvojici na cestě něco o obloze nad nimi. Totéž
platí pro západ slunce v běžící cestě. `RWEATHER` je slovník receptů k počasí —
katalog rozhraní, ne obsah.

**`REVISIT` sem nepatří.** Klíče jsou identifikátory rozhodnutí (`r1`, `r2`),
takže se posílá se skupinou Vztah, ne s cestami.

Cesty a místa jdou ven jako **úplné kolekce** i s rejstříky `TRIP_BY_TITLE`
a `PLACE_BY_TITLE`. Prototyp si je staví při načtení z ukázkových dat, takže by
po výměně ukazovaly na klíče, které už neexistují.

### Tři záložky, které se skutečnými daty padaly

`renderVals()` se počítá při **každém** překreslení, takže tyhle řádky neshodily
jen svou obrazovku, ale celou aplikaci:

- `TRIPS[s.tripId] || TRIPS.chorvatsko`
- `PERSONS[s.personId] || PERSONS.Makinka`
- `PLACES[s.placeId] || PLACES.skblin`

Se sedmi napsanými cestami to fungovalo. Se skutečnými nemusí žádná „chorvatsko"
existovat — a `Makinka` neexistuje u nikoho, kdo se tak nejmenuje. Za každou
záložkou je teď první dostupný záznam a prázdná struktura jako poslední pojistka.

## Vztah: tabulku dostalo jen to, co jde měnit

Prototyp má patnáct mechanismů vztahu, ale upravovat jde **pět**: paměť
rozhodnutí, rozvahu před nákupem, protokol nesouhlasu a veto banku (návrhy
i použití). Právě ty mají tabulku. Tiché dohody, kdo mluví za nás, premortem
a druhý názor od minulosti zůstávají v katalogu — obrazovka, na které by se daly
změnit, v prototypu není, a tabulka bez zápisu je horší než žádná.

Dvě věci se **odvozují z rozhodnutí**, ne z vlastního seznamu:

- **Arbitráž** (`ARB`) jsou rozhodnutí, která mají arbitra a klíč („poslední
  slovo", „kdo to používá víc").
- **Záznam verzí** (`VERSIONS`) jsou revize rozhodnutí. Vznikají samy: když se
  rozhodnutí označí za změněné, uloží se znění, které do té chvíle platilo.
  Původní zápis se nepřepisuje — právě proto, aby za rok bylo vidět, co jste si
  tehdy mysleli.

**Protokol nesouhlasu vypadá jinak pro každého z dvojice.** „Moje podmínky"
a „jeho podmínky" jsou tytéž řádky obrácené, takže se dělí podle přihlášeného
člověka, ne podle uloženého sloupce — a partnerova strana je konečně jeho
skutečná, ne napsaná ukázka.

Nic z toho se **nemaže**: rozhodnutí, které zmizí ze seznamu, je změněné,
rozvaha zavřená, lhůta vyřízená. Veto navíc nese datum, ne popisek — vrací se
po dvanácti měsících a bez data by se nedalo spočítat, kolik jich komu zbývá.

### Sliby, žádosti a trpělivost

**Slib, který zmizí ze seznamu, je zrušený po dohodě — ne nedodržený.** Ten
rozdíl je celý smysl té sekce a prototyp ho umí říct jen tím, že řádek odebere;
v databázi zůstává jako `released` a do statistiky „dodrženo z pěti" se nepočítá.

Stav `late` se **neukládá**, odvozuje se z data. Uložený by po termínu pořád
tvrdil, že slib platí — a „čtyři dny po termínu" je pravda jen ten den, kdy se
to čte. Termín drží databáze dvakrát: slovy (`do pátku` — to člověk vysloví)
i datem (bez něj se nedá spočítat, o kolik je po termínu).

**Trpělivost není vlastní seznam, ale pohled na žádosti mezi partnery.** Kdo si
co vyžádal, kdo to má na starost a kolikrát se mu to za poslední měsíc muselo
připomenout. Proto dostaly tabulku žádosti (`couple_nudges`) a jejich připomínky
(`couple_nudge_reminders`) — a trpělivost se z nich počítá:

- Připomínka je **záznam s časem, ne čítač.** Obrazovka mluví o posledním měsíci
  a z čísla se měsíc vyčíst nedá. Do stavu se vejde jen počet, takže server
  zapisuje **rozdíl** proti tomu, co má uložené; jinak by z jedné byly tři.
- Co **převzalo pravidlo**, se nemá nikomu připomínat, a proto to v přehledu
  není. Prototyp si to pamatuje podle textu úkolu (`patAuto`); v databázi je to
  `automated_at` na žádosti.
- Odmítnutá žádost není nesplněný slib a do trpělivosti nepatří.

Jedna změna v dokumentu to vyžádala: tlačítko **„Připomenout" dosud jen ukázalo
hlášku** a nikam nic nezapsalo, takže celý přehled trpělivosti stál na čísle,
které nikdo nikdy nezapsal. Teď připomínku i započítá — hláška zůstala stejná.

## Cyklus: soukromý zápis, ne společný obsah

Kalendář cyklu je zápis **jednoho člověka**. Aplikace na to má nastavení sdílení
(`cycle_settings.share_level`) a poskytovatel ho drží:

| Úroveň | Co partner uvidí |
| --- | --- |
| `none` | nic — ani termíny |
| `dates` | kdy čekat a kolikátý den je; **žádné příznaky, nálada, bolest ani poznámka** |
| `full` | celý deník |

Svůj vlastní zápis vidí člověk vždycky. Poslat partnerovi všechno „protože jsou
pár" je přesně to, čemu se ta volba vyhýbá.

Začátky cyklů se **odvozují ze zapsaných dnů**, ne z druhého seznamu: počítá se
z nich délka cyklu i odhad toho příštího, takže dvě pravdy by znamenaly dva různé
odhady na jedné obrazovce. Zápis se ukládá pod přihlášeného člověka — cizí den
nejde přepsat ani omylem.

Nálada dostala vlastní tabulku (`wellbeing_moods`), protože ji jde zapsat jedním
klikem a celá obrazovka „Klid a pohoda" na její čtrnáctidenní křivce stojí.
Chybějící den je `null`, ne nula: „nezapsáno" a „bylo mi mizerně" nejsou totéž.

`CYC_TODAY` je **skalár** a vyměnit se nedá — patří k témuž seznamu jako
`INCOMES`. Zbytek (`FLOWS`, `PHASES`, `CYC_SYMPTOMS`, `CYC_MOODS`, `CYC_SHARE`,
`KL_HELP`, `KL_QUESTIONS`) jsou katalogy rozhraní, ne obsah dvojice.

## Trezor: tabulka nakonec nebyla potřeba

Plán počítal s tím, že trezor dostane vlastní tabulku. Při psaní se ukázalo, že
by byla druhou pravdou: „dát do trezoru" znamená schovat položku z mřížky, mapy
i hledání, a na to má aplikace sloupec `is_hidden`, který knihovna už
respektuje. Trezor se proto **počítá z toho, co v něm doopravdy je** — ze
skrytých položek po albech. Vlastní seznam by tvrdil „48 fotek v trezoru"
i poté, co je někdo vrátil zpátky.

Zápis vede přes `TrezorVeStavu`: klíč `vaultAdded` se ze stavu vyzvedne
a promítne do `is_hidden`. Bez toho by fotku schoval jen prohlížeč toho, kdo
klikl — a druhý z dvojice by ji dál viděl v mřížce, což je u trezoru dost
podstatný rozdíl.

Dvě věci téhle skupiny zůstávají v katalogu:

- **`GV_C` a `GV_VOICE_POOL`** (komentáře a hlasovky od hostů). `media_comments`
  má cizí klíč na uživatele, takže babiččin komentář nemá kam. Vlastní tabulku
  dostane, až bude mít kdo psát — prototyp je jen ukazuje.
- **Odkaz na jednu položku a výběr** se popisuje obecně („Jedna položka",
  „Výběr položek"). `shared_links` drží jen `target_type` a `target_id`, takže
  víc než to by byl dohad.

Zapečetěná kapsle jde ven **bez textu**. Celý smysl je, že se otevře v den, na
který se čeká, a obsah v prohlížeči by se dal přečíst kdykoli.

## Zprávy a kuchařka: dvě věci, které se ukázaly až u dat

**Tělo zprávy je v databázi šifrované** (`'body' => 'encrypted'`). Přímý dotaz
přes dotazovač vrátí base64 — do chatu by šel místo věty ciphertext. Čte se
proto přes model. Prázdné bubliny (zbytky po hrách a zrušených přílohách) se
neposílají; v chatu by vypadaly jako výpadek.

**`who` není iniciála jména, ale strana.** Prototyp porovnává `m.who === 'A'`
a myslí tím „moje". Posílá se proto `A` za přihlášeného a `M` za toho druhého —
jinak by si každý z dvojice četl vlastní zprávy jako cizí.

V kuchařce se **historie a čísla počítají z vaření**, ne ukládají: „naposledy
12. 8." a „9/10 · 3 vaření" jsou pohled na `recipe_cooking_sessions`. Cena za
porci jde ven jen tehdy, když ji někdo u vaření doopravdy zapsal — odhadnout ji
ze surovin by znamenalo vymyslet číslo, podle kterého se dvojice rozhoduje, co
uvaří. Surovina bez množství má `null`, ne nulu: prototyp podle toho pozná „sůl
dle chuti" a číslo u ní vůbec nekreslí.

`MSGREPLIES` (nabídnuté rychlé odpovědi) zůstává katalogem — nejsou to zprávy
dvojice, ale texty tlačítek.

## Rozhodování: čtyři formuláře na to, co aplikace vědět nemůže

Sekce rozhodování je skoro celá odvozená — mlčky platná pravidla, kdo mluví,
co se rozhodlo samo. Čtyři věci se ale odvodit nedají a nikdy nepůjdou:

| Kolekce | Co to je | Tabulka |
| --- | --- | --- |
| `BUS` | co umí jen jeden z nich | `couple_bus_items` |
| `PM_DEC`, `PM_MINE`, `PM_THEIRS`, `PM_HIST` | pre-mortem: čeho se kdo bojí | `couple_premortems`, `couple_premortem_risks` |
| `PAST_DEC`, `PAST_CASES` | druhý názor od vlastní minulosti | `couple_past_cases` |
| `REVISIT` | na jakých vstupech rozhodnutí stálo | `couple_decision_inputs` |

Zápis jde **vlastní cestou, ne stavem**: prototyp tyhle kolekce čte jako
konstanty z `GalerieData`, takže by je patch stavu neměl kam vrátit.

```
POST /api/zaznamy/{bus|bus-zapsano|premortem|riziko|pripad|vstup}
  →  { ok: true, zprava: "…", data: { BUS: [...], PM_DEC: [...] } }
```

Odpověď nese **celou skupinu znovu** a hlavička ji navleče do kolekcí
(`GalerieObsahNavlec`). Čekat na další `GET /api/data/rozhodovani` nejde —
ta odpověď má půlminutovou paměť a nový zápis by se objevil se zpožděním.

Vedle `data` nese odpověď na akci ještě `prazdne` — seznam kolekcí, které po ní
**zbyly prázdné**. Poskytovatel prázdné kolekce neposílá, což je při načtení
stránky správně (prázdná obrazovka a rozbitá aplikace vypadají stejně), ale
u odpovědi na akci to znamená „nezměnilo se nic": po vrácení poslední položky
z koše by na obrazovce zůstal řádek, který už neexistuje. Skládá to `VraciObsah`
z rozdílu mezi `uplne()` a klíči, které opravdu přišly.

Tři věci, které se u toho ukázaly:

*Jedna tabulka na otázku i na případ.* `PAST_DEC` a `PAST_CASES` je jedna
tabulka; případ s hodnocením je zkušenost, případ bez něj otázka, která se
teprve rozhoduje. Dvě tabulky by znamenaly přepisovat řádek při zavření
a ztratit, že to byla táž věc.

*Směr se nepočítá do sloupce.* U `REVISIT` se „nahoru/dolů" spočítá z obou
hodnot při čtení. Uložený sloupec by se při opravě čísla rozešel se svými
hodnotami a šipka by ukazovala opačně než text vedle ní.

*`PM_MINE` je sloupec toho, kdo se dívá.* Nejdřív se řadilo podle vlastníka
prostoru — jenže v prostoru, který založil ten druhý, pak člověk viděl vlastní
obavu ve sloupci partnera, tedy přesně tam, kam se u pre-mortemu dívat nemá.
Server řadí podle přihlášeného člověka a prototyp stejně, podle
`window.GALERIE_USER`. To bylo do té doby vždycky `null` — hlavička s tím
počítala, ale nikdo jí to nepředával.

## Úložiště: obrazovka, která tvrdila, že je záloha hotová

„Připojeno — adrian.stanek@gmail.com. Poslední úspěšná synchronizace dnes
v 8:12 · 24 316 originálů bezpečně uloženo." Celá obrazovka úložiště byla
napsaná v designovém souboru — e-mail, čas, počty, rozdělení kapacity
i varování o třech nepřenesených souborech. Dvojici, která Google Disk
připojený nemá, tvrdila, že jsou její fotky ve dvou kopiích. To není zastaralé
číslo, to je nepravda o záloze.

Stav připojení se hledá **toutéž cestou jako ve zbytku aplikace**
(`DriveConnectionResolver`): podle členů prostoru, ne podle `gallery_space_id`,
který je v té tabulce z větší části prázdný. Rozbité připojení se přiznává —
vypršelý token je pro dvojici horší stav než žádný účet, protože si myslí,
že zálohu má.

Čtyři dlaždice se stavem originálů **rozdělují celou knihovnu**. Podle sloupce
se stavem to nešlo: položky se stavem mimo výčet se mezi dlaždicemi ztratily
a součet neseděl s počtem fotek. Rozhoduje proto `drive_file_id` — stav sám
o sobě je jen tvrzení, a záznam, který o sobě říká „synced", ale nemá k čemu
se vrátit, je přesně ten případ na čtvrté dlaždici.

Koš byl na tom stejně: čtyři vymyšlené řádky a dialog slibující, že se odstraní
i originály z Disku, načež se nesmazalo nic. Maže se přes `MediaPurger`, tutéž
službu jako druhé rozhraní — dvě implementace by znamenaly dvě místa, kde se dá
zapomenout na kopii v cloudu.

## Co ještě není napojené

Tohle **není** katalog rozhraní — je to obsah dvojice, který se pořád kreslí
z `galerie-data.js`. Seřazeno podle toho, co je hotové nejdřív: první skupina
má tabulky i data, poslední je potřeba teprve vymyslet.

Nezbývá nic. Poslední tři se ukázaly být tři různé věci a každá potřebovala
jiné řešení — viz níž.

## Poslední čtyři: dva chybějící sloupce

`JOY`, `VIS_ROWS`, `TACIT` a `SPEAK` se dlouho nedaly napojit — ne proto, že by
chyběl nápad, jak je spočítat, ale proto, že v databázi nebyl **jeden konkrétní
údaj**. Doplnily se dva:

| Sloupec | Co bez něj nešlo |
| --- | --- |
| `calendar_events.activity_kind` | co to za společnou věc vlastně bylo. `type` (`event`, `birthday`) říká, jak se to chová v kalendáři, ne jestli to byla snídaně mimo domov nebo návštěva u rodiny |
| `couple_outreach_log` (nová tabulka) | kdo to vyřídil. Tabulka kontaktů zná jen „naposledy" a „jak často", takže se z ní nedalo spočítat, kolik hodin to komu sebralo ani kdo za koho mluví s úřady |

**Účet radosti** (`JOY`) teď počítá přesně to, co obrazovka slibuje: hodiny
z kalendáře, útratu z transakcí, zdvih nálady proti průměru všech dnů. Útrata
se bere jen ze **dnů, kdy se dělo jen tohle jedno** — den, ve kterém je
randíčko i velký nákup do bytu, aplikace rozdělit neumí, a přiřadit celou
útratu oběma by znamenalo tvrdit, že randíčko stálo čtyři tisíce. Zařazení je
nepovinné; nezařazená událost se do výpočtu nedostane, což je správně —
nezařazené není „nic", je to „nevíme co".

**Neviditelná práce** (`VIS_ROWS`) a **kdo mluví za koho** (`SPEAK`) jsou týž
protokol viděný dvakrát: první je jeho část navázaná na kontakt s rodinou,
druhý týž protokol seskupený po oblastech. `asked_partner` je jediná věc, která
se odvodit nedá — jestli se ten, kdo to vyřizoval, předem zeptal druhého. Bez
ní by obrazovka tvrdila buď že se ptá vždycky, nebo nikdy, a obojí by byla lež.

**Mlčky platná pravidla** (`TACIT`) nový sloupec nepotřebovala. Obrazovka o sobě
říká „vzorce, které aplikace našla" — tak se čtyřmi hledači opravdu hledají:

| Hledač | Nad čím | Co najde |
| --- | --- | --- |
| dělba práce | `house_chore_log` | „Kdo dělá A, nedělá B" mezi pracemi téhož dne |
| klid na peníze | `transactions.created_at` | hodina, po které se do financí skoro nesahá |
| velký nákup | `transactions` + `couple_cooling_purchases` | jestli má výdaj nad devátým desetilem předem rozvahu |
| den bez plánu | `calendar_events` | den v týdnu, který zůstává skoro vždy prázdný |

Dva prahy platí pro všechny: vzorec se ukáže, jen když měl aspoň osm
příležitostí projevit se a drží aspoň ve třech případech z pěti. Pod tím to není
tichá dohoda, to je náhoda — a půlka testů hlídá právě tohle. Vyhýbání se hlásí
jen v jednom směru; „kdo vaří, neuklízí kuchyň" a „kdo uklízí kuchyň, nevaří"
je totéž dvakrát.

## Poslední tři: tři různé věci

`WEATHER`, `POSTEPS` a `GV_VOICE_POOL` vypadaly jako jedna skupina
„nenapojitelných". Nebyly.

**`WEATHER`** potřebovala zdroj, který aplikace už měla. `FreeTravelDataService`
volá Open-Meteo u cest; chybělo jen **místo**. Kde dvojice bydlí, se nikde
nezadává, tak se bere **medián polohy jejich fotek** za posledního půl roku:
kde se nejčastěji fotí, tam se nejčastěji vaří. Medián, ne průměr — dva týdny
u moře by průměr odtáhly do Jaderského moře a aplikace by radila podle počasí,
které nikdo nemá za oknem. Souřadnice se zaokrouhlují na dvě desetiny stupně;
ven neodchází přesnější poloha, než je k odpovědi potřeba. Věta pod teplotou
se skládá **jen z čísel, která přišla** — ukázka měla „první opravdu letní den
týdne" o dni, který ještě nebyl.

Režim dne je jedna ze **čtyř** hodnot (`déšť`, `chladno`, `teplo`, `horko`),
protože jen ty čtyři umí obrazovka vážit proti `fits` v katalogu receptů.
Pátá by znamenala den, ke kterému se nenajde nic.

**`POSTEPS`** je katalog — čtyři názvy kroků zásilky. Skutečný problém byl
jinde: `print_orders` se v celé aplikaci **jen četla**. Tlačítko u fotoknihy
hlásilo „Kniha odeslána do tisku" a nikde nevznikl záznam. Aplikace s tiskárnou
nemluví a nepředstírá to — zapíše objednávku ve stavu „Přijato" s **odhadem**
termínu (`due_estimated`, aby datum nevypadalo jako slib) a stav posouvá ten,
komu přijde potvrzení. Popisky teď chodí ze serveru, aby se nerozešly s indexem
`step` v databázi, a bez objednávek se neposílají vůbec.

**`GV_VOICE_POOL`** byl pool osmi napsaných přepisů, ze kterého si náhled hosta
bral jeden a vkládal ho do seznamu vzkazů, jako by ho babička opravdu řekla —
s hláškou „Hlas nahrán a přepsán". Aplikace řeč na text nepřevádí vůbec.

Skutečná díra: `guest_comments` se **taky jen četla**. Host, který otevře
sdílený odkaz, neměl jak nechat vzkaz. Chyběly dva sloupce —
`shared_links.allow_comments` (přepínač, který prototyp má, ale ukládal se jen
do prohlížeče) a `guest_comments.audio_path` (hlasovka bez souboru je řádek
tvrdící, že babička něco řekla, a nejde si to poslechnout). `body` je nově
nullable: prázdný řetězec by znamenal „host nic neřekl", což je něco jiného
než „řekl to hlasem a přepis nemáme".

`POST /s/{token}/vzkaz` je **jediná cesta v galerii, která přijímá zápis bez
přihlášení**. Píše se jen tam, kde to dvojice povolila; vypršelý odkaz ani
odkaz chráněný heslem bez ověření nepustí; limit je desetkrát za minutu.

Náhled hosta přestal vyrábět obsah. Ukazuje, co host uvidí, a řekne to —
nahrát vzkaz může host na svém odkazu, ne dvojice v náhledu.

## Zapsané formulářem

Čtyři věci se počítat nedají a nikdy nedaly: kdo umí přepnout bojler, čeho se
kdo u rozhodnutí bojí, jak dopadl podobný případ před dvěma lety a na jakém
čísle rozhodnutí stálo. Ty mají skupinu `rozhodovani`, vlastní tabulky
a formuláře — viz výš.

## Číselníky, které vypadají jako obsah

`SCEN` a `RITUALS` se dlouho počítaly mezi nenapojené kolekce. Nejsou to data
dvojice, jsou to **číselníky zabudovaných funkcí**: přepínač scénáře se váže na
konkrétní větev ve výpočtu `p60Calc` (`income`, `loan`, `save`, `parent`),
rituál na obrazovku aplikace (`x-uklid`, `x-milniky`, `x-cesty`). Nový řádek by
byl přepínač, který nic nepřepne. Co je u nich obsah dvojice, je jen zapnutí,
a to se ukládá do stavu (`finScen`, `rtOn`) už dneska.

`GV_VOICE_POOL` a `POSTEPS` k číselníkům přibyly nakonec — první byl pool
napsaných přepisů pro náhled hosta (a přepis aplikace nedělá), druhý jsou
čtyři názvy kroků zásilky, které teď chodí ze serveru, aby se nerozešly
s `step` v databázi. Obojí je popsané v „Poslední tři" nahoře.

## Co zůstalo v katalogu — a proč

Co v katalogu zůstalo, tam zůstalo z jednoho z těchhle tří důvodů:

| Důvod | Kolekce |
| --- | --- |
| **Není to obsah dvojice** — katalog rozhraní | `FLOWS`, `PHASES`, `CYC_SYMPTOMS`, `CYC_MOODS`, `CYC_SHARE`, `KL_HELP`, `KL_QUESTIONS`, `RWEATHER`, `PLACE_KEY`, `EVKIND`, `PKIND`, `SETROWS`, … (~51 katalogů) |
| **Přihlašovací záslepky prototypu** | `LOCKMAIL`, `LOCKPIN`, `LOCKPWD`, `LOCKREC`, `LOCKWHO`, `VAULT_PWD` |
| **Odvozené v dokumentu** | `AMISS`, `CYC_TODAY`, `GRAF` |

Přihlašovací záslepky jsou zvláštní případ: **daty se stát nesmí.** Jsou to
heslo a PIN napsané v souboru, aby se dal prototyp ukázat. S nasazeným
backendem ověřuje přihlášení server (`api.signIn`) a tyhle konstanty už nic
neřídí — udělat z nich tabulku by znamenalo uložit hesla do databáze v čitelné
podobě.

Ze stavu páru se zachytává **čtyřicet pět obsahových klíčů**. Zbylé čtyři
(`quar`, `emLog`, `hsVisits`, `pmMine`) se do stavu pořád ukládají a jsou
popsané výš. Co ve stavu zůstat **má**, je jen zobrazení: otevřená záložka,
rozepsaný neodeslaný text, zvolený měsíc v kalendáři.

Pravidlo, které to celé řídí: **tabulka bez zápisu je horší než žádná tabulka.**
Kde prototyp obsah drží ve svém stavu, vzniká i cesta zpátky:

| Vrstva | Co zapisuje | Proč |
| --- | --- | --- |
| `PlanovaniVeStavu` | události, nástěnka, „až budeme mít čas" | tabulky vlastní modul — bez zápisu má dvojice dva kalendáře |
| `DomacnostVeStavu` | dělba práce, lhůty, byt | nevlastní je nikdo; bez zápisu se stav a databáze rozejdou |
| `VztahVeStavu` | rozhodnutí, rozvahy, protokol, veto, sliby, žádosti, připomínky | totéž |
| `ZdraviVeStavu` | zapsané dny cyklu, nálada | cyklus vlastní modul |
| `TrezorVeStavu` | co je schované z knihovny | jinak fotku schová jen jeden prohlížeč |
| `UklidVeStavu` | karanténa, slučování duplicit, datování skenů | „Pustit" jinak zmizí jen tomu, kdo klikl, a originál leží dál na disku; „datováno na 1988" zmizelo ze seznamu a `taken_at` zůstalo prázdné |
| `PravidlaVeStavu` | automatizace a její historie | `state.rules \|\| RULEDEF` — jedno přepnutí vypínače navždy zastínilo skutečná pravidla |
| `RozboryVeStavu` | sezónní fondy | totéž u `state.season \|\| SEASON`; fond měl v Rozpočtech jiný stav než ve své vlastní obrazovce |
| `ZpravyVeStavu` | odeslané zprávy | „Zpráva odeslána" — a nikam se neodeslala; druhý o ní nevěděl a po zavření záložky zmizela |
| `DarkyVeStavu` | přání, nápady, chystané dárky | totéž; nákup navíc musí zůstat soukromý toho, kdo ho pořizuje |
| `KapsleVeStavu` | zapečetěné vzkazy | dopis na příští rok přežije výměnu telefonu jen v databázi |
| `NastaveniVeStavu` | přepínače formulářů (`sw`) | „sync každé čtyři hodiny" jinak změní jen barvu; napojení se dál řídí databází |
| `KlidVeStavu` | mapa energie, rozpočet pozornosti, odpovědi | mapa je o okně, kdy mají sílu **oba** — druhý se k ní nedostal |
| `PribehVeStavu` | kapitoly, nouzový přístup, papírový list | „soukromé zápisy se nouzově neodemknou" musí platit i na druhém zařízení |
| `MechanismyVeStavu` | laskavosti, odpuštěné, anti-rozpočet, rodina, dvě pravdy | nebylo to ztracené, ale nešlo se na to zeptat |
| `AdminVeStavu` | administrace ze staršího klienta | záchranná síť |

Vrstvy, které skutečnost **vracejí, ale neukládají** (pravidla, historie běhů,
sezónní fondy), ji zároveň označí jako `docasne`. Klient si ji díky tomu
nepřidá do lokální kopie: uložená by se při dalším spuštění postavila před
data ze serveru — tedy přesně to, čemu se tahle vrstva vyhýbá.

Kde tabulky vlastní jiný modul (kalendář, úkoly, cyklus), je zápis **opatrnější**:
ukázkové řádky se neimportují a maže se jen to, co server sám poslal.

`CYC_TODAY` a `INCOMES` jsou **skaláry**, které se na místě vyměnit nedají;
`INCOMES` se posílá i v `BUD.income`, `CYC_TODAY` je fixní „dnešek" prototypu.

## Zásady

1. **Existující obsah se přizpůsobuje prototypu, ne naopak.** Transformace je na
   serveru; prototyp dostane přesně ty klíče, které čte.
2. **Jedna pravda.** Kolekce ze serveru se nikdy neukládá do stavu páru — stav drží
   jen to, co dvojice změnila v prototypu.
3. **Strop na každou kolekci.** Řádků tolik, kolik obrazovka ukáže.
4. **Bez dat se nic nerozpadne.** Prázdná tabulka znamená prázdný seznam
   s hláškou, ne chybu.
5. **Formulář jen tam, kam patří.** Tabulka bez zápisu je horší než žádná
   tabulka — ale zápis na místě, kde obrazovka slibuje výpočet, je horší než
   obojí. Než se přidá pole k vyplnění, přečíst, co ta obrazovka o sobě říká.

## Jména dvojice

Prototyp na zhruba sedmdesáti místech porovnával se jmény `'Adrian'`
a `'Makinka'` — barvy štítků, sloupce, filtry, počty, přepínače. U dvojice,
která se jmenuje jinak, z toho vycházel šedý štítek, prázdný sloupec a věty
typu „Požádat Klára".

Kdo ti dva jsou, říká server v kolekci `DVOJICE` (skupina `system`) ze členů
prostoru; **první je ten, kdo se dívá**, protože polovina vět je psaná z jeho
pohledu. Odvození z nálady dvou zůstalo jako záloha, ale spoléhat se na ně
nešlo: dokud si ji nikdo nezapsal, padalo se zpátky na ukázková jména. Dva
stejně pojmenovaní členové dostanou pořadové číslo — bez něj by se slili do
jednoho a půlka obrazovek by počítala práci jednoho z nich dvakrát.

V dokumentu na to jsou čtyři pomocníci: `dva()`, `druhy()`, `tagKdo()`
a `barvaKdo()`.

**Pády se odvozují z pravidel**, ne z výčtu (`sklon(jmeno, pad)`). Česká jména
se skloňují pravidelně: Kláru/Kláře/Kláry, Adriana/Adrianovi, Marka/Markovi,
Jiřího/Jiřímu. Jméno, které do žádného vzoru nepadne, zůstane v prvním pádě —
horší čeština než správný pád, ale lepší než pád špatný, a věty kolem jsou
psané tak, aby to unesly.

**Minulý čas s rodem je nahrazený přítomným.** „Práci převzala Makinka" se
u cizího jména napsat nedá, protože rod se z něj odvodit nedá; „práci přebírá
Klára" říká totéž a nepotřebuje ho. `rodPripona()` vrací `null` právě pro
jména, u kterých se rod určit nedá — věta se pak musí přeformulovat, ne
uhodnout.
