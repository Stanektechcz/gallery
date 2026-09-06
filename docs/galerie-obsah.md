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
| 2 | Knihovna | `PERSONS`, `MEMS`, `STORY`, `STORYMS`, `DUP_GROUPS`, `YBCH`, `VIS_ROWS` | `media_items`, `people`, `tags`, `albums`, `generated_memories`, `duplicate_groups` | zbývá |
| 3 | Plánování | `CALEV`, `EVSEED`, `ATASKS`, `LATER_ITEMS`, `PROMISES`, `PATIENCE` | `calendar_events`, `shared_todos`, `event_tasks` | zbývá |
| 4 | Domácnost | `HOUSE_CHORES`, `HOUSE_LOG`, `HOUSE_WEEK`, `HOUSE_DUES`, `HOUSE_INV`, `PANTRY` | **chybí tabulky** | zbývá |
| 5 | Cesty a místa | `TRIPS`, `NOWTRIP`, `PLACES`, `REVISIT`, `WEATHER`, `RWEATHER` | `trips`, `places`, `trip_*` | zbývá |
| 6 | Vztah | `DEC_LIST`, `DEC_COOL`, `PAST_DEC`, `ARB`, `VERSIONS`, `TACIT`, `SPEAK`, `PM_*`, `SPOR_*`, `VETO_*` | **chybí tabulky** | zbývá |
| 7 | Zdraví a cyklus | `CYC_BASE`, `CYC_TODAY`, `CYC_STARTS`, `CYC_SHARE`, `KL_*` | `cycle_days`, `cycle_settings`, **část chybí** | zbývá |
| 8 | Sdílení a systém | `GV_*`, `GUEST_Q`, `VAULT_ITEMS`, `OFFPACKS`, `KAPS` | `shared_links`, `guest_uploads`, **trezor chybí** | zbývá |

## Co bude potřebovat nové tabulky

Aplikace nemá kam uložit: domácnost (práce, rotace, spíž, inventář), mechanismy
vztahu (rozhodnutí, arbitr, tiché dohody, protokol nesouhlasu, veto), trezor
a část klidu a pohody. Ty vzniknou ve svých krocích — dřív ne, aby se nezaložily
tabulky podle dohadu o tvaru.

## Zásady

1. **Existující obsah se přizpůsobuje prototypu, ne naopak.** Transformace je na
   serveru; prototyp dostane přesně ty klíče, které čte.
2. **Jedna pravda.** Kolekce ze serveru se nikdy neukládá do stavu páru — stav drží
   jen to, co dvojice změnila v prototypu.
3. **Strop na každou kolekci.** Řádků tolik, kolik obrazovka ukáže.
4. **Bez dat se nic nerozpadne.** Prázdná tabulka znamená prázdný seznam
   s hláškou, ne chybu.
