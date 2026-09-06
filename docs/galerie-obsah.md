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

## Pořadí

Podle toho, kde už skutečný obsah je a kde na něm záleží:

| # | Skupina | Kolekce | Zdroj | Stav |
| --- | --- | --- | --- | --- |
| 1 | **Finance** | `TX`, `BUD`, `FIN`, `INCOMES`, `SHARED`, `ENV`, `DISP`, `EST`, `ANTI`, `INFL`, `SEASON`, `RECON`, `CAS_ROWS`, `PAPER_ROWS`, `TRIPCOST` | `budgets`, `budget_category_limits`, `transactions`, `finance_categories`, `wallets`, `shared_expenses` | hotovo |
| 2 | **Knihovna** | `DAYS`, `PHOTOS`, `ALBUMS`, `ATREE`, `PERSONS`, `DUP_GROUPS`, `YBCH`, `NAVCNT`, `TOTAL`, `MOBIL` | `media_items`, `media_variants`, `people`, `media_person`, `tags`, `albums`, `album_media`, `duplicate_groups` | hotovo |
| 3 | **Plánování** | `CALEV`, `ATASKS`, `LATER_ITEMS`, `AL.doneTasks`, `EVSEED` | `calendar_events`, `event_participants`, `event_reminders`, `shared_todos`, `life_events` | hotovo |
| 4 | **Domácnost** | `HOUSE_CHORES`, `HOUSE_LOG`, `HOUSE_WEEK`, `HOUSE_DUES`, `HOUSE_INV`, `PANTRY` | `house_chores`, `house_chore_log`, `house_dues`, `house_inventory`, `house_pantry`, `house_week(_capacity)` | hotovo |
| 5 | **Cesty a místa** | `TRIPS`, `TRIP_BY_TITLE`, `NOWTRIP`, `PLACES`, `PLACE_BY_TITLE` | `trips`, `trip_days`, `trip_activities`, `trip_expenses`, `trip_budget_limits`, `trip_packing_items`, `trip_document_checks`, `travel_journal_entries`, `places`, `place_plans`, `place_notes` | hotovo |
| 6 | **Vztah** | `DEC_LIST`, `ARB`, `VERSIONS`, `DEC_COOL`, `SPOR_MINE`, `SPOR_THEIRS`, `VETO_USED`, `VETO_PROP` | `couple_decisions`, `couple_decision_revisions`, `couple_cooling_purchases`, `couple_disagreement_points`, `couple_veto_proposals`, `couple_vetoes` | hotovo |
| 7 | **Zdraví a cyklus** | `CYC_BASE`, `CYC_STARTS`, `KL_DAYS`, `KL_MOOD` | `cycle_days`, `cycle_settings`, `wellbeing_moods` | hotovo |
| 8 | Sdílení a systém | `GV_*`, `GUEST_Q`, `VAULT_ITEMS`, `OFFPACKS`, `KAPS` | `shared_links`, `guest_uploads`, **trezor chybí** | zbývá |

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

## Plánování: co zůstává ve stavu páru

`PROMISES` (sliby) a `PATIENCE` (kolikrát se to muselo připomínat) **zůstávají
ve stavu páru** a do databáze se nepřenášejí. Není to opomenutí: prototyp je
umí zakládat, uzavírat i rušit po dohodě a ukládá je do `/api/state`, který je
sdílený mezi oběma partnery a přežije zavření prohlížeče. Tabulka, do které by
nikdo jiný nepsal, by přidala druhý zdroj pravdy a žádnou funkci — a to je
přesně to, co zásada „jedna pravda" zakazuje.

Kolekce téhle skupiny jsou **výchozí hodnota**, ne živý pohled. Jakmile dvojice
v prototypu upraví událost nebo přesune úkol, drží si vlastní seznam ve stavu
(`evList`, `xBoard`, `hsLater`) a ten má přednost — tak je prototyp napsaný.
Zápis zpátky do `calendar_events` a `shared_todos` je samostatná vrstva
(obdoba `AdminVeStavu`) a čeká na svůj krok.

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

## Co bude potřebovat nové tabulky

Domácnost už tabulky má (krok 4). Zbývají mechanismy vztahu (rozhodnutí, arbitr,
tiché dohody, protokol nesouhlasu, veto), trezor a část klidu a pohody. Vzniknou
ve svých krocích — dřív ne, aby se nezaložily tabulky podle dohadu o tvaru.

Platí u nich totéž pravidlo jako u domácnosti: **tabulka bez zápisu je horší než
žádná tabulka.** Kde prototyp obsah jen drží ve svém stavu a nikdo jiný v aplikaci
ho nevlastní, musí spolu s tabulkou vzniknout i cesta zpátky — jinak se stav
a databáze po prvním kliknutí rozejdou.

## Zásady

1. **Existující obsah se přizpůsobuje prototypu, ne naopak.** Transformace je na
   serveru; prototyp dostane přesně ty klíče, které čte.
2. **Jedna pravda.** Kolekce ze serveru se nikdy neukládá do stavu páru — stav drží
   jen to, co dvojice změnila v prototypu.
3. **Strop na každou kolekci.** Řádků tolik, kolik obrazovka ukáže.
4. **Bez dat se nic nerozpadne.** Prázdná tabulka znamená prázdný seznam
   s hláškou, ne chybu.
