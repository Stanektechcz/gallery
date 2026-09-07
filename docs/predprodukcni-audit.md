# Předprodukční audit

Stav k 7. 9. 2026. Co je opravené, co zbývá a co se úmyslně nechalo tak.

Metoda: nálezy se **ověřovaly útokem nebo v prohlížeči**, ne čtením kódu.
U každého je napsané, jak se pozná, že platí.

## Opraveno v prvním průchodu

### Heslo šlo zkoušet donekonečna

`POST /login` neměl žádný limit. Dvanáct pokusů za sebou prošlo bez zdržení
a bez zámku — ověřeno s platným tokenem proti CSRF, který si útočník vezme
z přihlašovací stránky stejně jako prohlížeč.

Stejně na tom bylo `POST /s/{token}/verify`, tedy heslo ke sdílenému odkazu.
To je horší cíl než heslo k účtu: bývá kratší a chrání fotky, které dvojice
někomu poslala.

Limit dostalo **osm veřejných cest**:

| Cesta | Limit | Proč |
| --- | --- | --- |
| `POST /login` | 10/min | zkoušení hesla |
| `POST /login/overeni` | 15/min | druhý faktor má vlastní limit na účet, tenhle je na adresu |
| `POST /s/{token}/verify` | 10/min | heslo ke sdílenému odkazu |
| `POST /forgot-password` | 5/min | zaplavení cizí schránky a zjišťování, které adresy mají účet |
| `POST /reset-password` | 10/min | zkoušení tokenu |
| `POST /registrace` | 5/min | zakládání účtů |
| `POST /invite/{token}` | 10/min | zkoušení pozvánky |
| `POST /s/{token}/upload` | 30/min | zaplnění disku hostem |

Ověření po opravě: desátý pokus projde, jedenáctý vrátí 429.

### Předpověď proti živé službě

Ověřeno proti Open-Meteo s funkčním certifikátem. Vrátilo pět skutečných dní
a hned ukázalo chybu, kterou test s podvrženými daty neodhalil: **32 °C
s odpolední přeháňkou vycházelo jako „déšť"** a obrazovka nabízela teplou
polévku. Teplota rozhoduje; přeháňka na chuti nic nemění.

### Kategorie k zařazení transakce

Braly se z `galerie-data.js`. Dvojici, která si kategorie přejmenovala nebo
přidala, aplikace nabízela cizí jména — a zapsané zařazení pak mířilo na
kategorii, kterou v účetnictví nemá. `TXCATS` teď chodí z `finance_categories`,
bez příjmových a bez schovaných.

### Podnadpis časové osy

„Sedm let, 24 316 vzpomínek" napevno. U dvojice se čtyřmi fotkami z jednoho
roku to bylo tvrzení o cizí knihovně.

## Opraveno ve druhém průchodu

### 1. Pět tabulek, ze kterých se jen četlo

| Tabulka | Kolekce | Jak se to zapisuje |
| --- | --- | --- |
| `couple_story_milestones` | `STORYMS` | dialog „Přidat milník" na ose příběhu |
| `house_week`, `house_week_capacity` | `HOUSE_WEEK` | ± u každého dne, poznámka a návrat ke kalendáři |
| `wellbeing_tasks` | `KL_TASKS` | řádek „přidat" a „Hotovo" pod mapou energie |
| `watch_titles` | `AL.films`, `ABARS.tier` | přidání titulu, hvězdičky, pásmo v žebříčku |
| `drive_conflicts` | `CONFLICTS` | příkaz `gallery:process-drive-changes` |

Kapacita týdne se teď opravdu **počítá z kalendáře**, jak obrazovka slibuje:
volný čas je bdělé okno (7–23) mínus hodiny, na kterých v kalendáři něco je.
Co aplikace vědět nemůže — dojíždění, směna, která se nikam nezapsala — jde
přepsat, a to přepsání se uloží. `autoA`/`autoM` si pamatují, co říkal
kalendář, aby se k němu dalo vrátit.

Rozpory nevznikají zápisem člověka, ale synchronizací: webhook z Disku ukládal
změny do `drive_changes` se stavem `pending` a **nikdo je nikdy nezpracoval**.
Fotka smazaná na Disku tak v aplikaci zůstala jako platná. Příkaz běží každých
pět minut a rozpor se dá z obrazovky vyřešit — dřív to přepsalo jediné pole
ve stavu prohlížeče a při dalším načtení se rozpor vrátil.

Strany rozporu se u těch z Disku jmenují „V knihovně" a „Na Disku", ne „moje"
a „jeho": u smazaného souboru nemá žádného lidského původce a označit za něj
někoho z dvojice by byla lež.

### Dvě věci v datové vrstvě, kvůli kterým se zápisy tiše ztrácely

Vyšly najevo při ověřování bodu 1 a týkaly se **celé aplikace**, ne jen těch
pěti tabulek:

- `galerie-api.js` se spouštěl podruhé (runtime prototypu své skripty načítá
  znovu) a založil **druhou frontu s vlastním číslem revize**. Obě psaly do
  `/api/state` na střídačku a ta pozadu dostávala od serveru 409: její zápis
  se zahodil a nikde po tom nezůstala stopa.
- Odpověď serveru se nikomu neoznamovala. Obrazovka tak nikdy nepřevzala
  identifikátor, který přidělila databáze, a při další změně poslala řádek
  znovu jako nový — z jednoho kliknutí vznikla druhá kopie celého seznamu.

Poznalo se to takhle: v prohlížeči se přidal úkol, označil se jiný jako hotový
a v tabulce byly obě dvě sady řádků.

### 2. Sdílení odkazem se neukládalo

Vytvoření a úprava odkazu žily jen ve stavu prohlížeče: adresa byla náhodná
čtyři písmena, token se nikde nezaložil a po odhlášení odkaz zmizel. Byla to
největší díra ze všech, protože o téhle jedné věci ta obrazovka celá je.

Odkaz teď vzniká v `shared_links`, jde upravit i zneplatnit, a s ním se dá
zapnout přepínač vzkazů, který na `allow_comments` čekal. Heslo se při úpravě
**nepředvyplňuje**: stálo tam napsané „letnizadar", takže dvojice viděla heslo,
které k odkazu nepatří, a uložením jiné změny by ho nastavila.

### 3. Host svůj vzkaz neuviděl

A hůř: na sdílené stránce neměl **jak ho napsat**. Cesta pro zápis existovala
a nevedl na ni žádný ovládací prvek. Stránka teď má pole na vzkaz a ukazuje,
co už kdo napsal — schované vzkazy ne, „schovat" znamená schovat.

### 4. Falešný přehrávač videa

Místo videa byl obrázek s namalovaným pruhem přehrávání a časem „0:42 z 2:04"
— pro každé video stejným. Dvě tlačítka pod ním neměla obsluhu, protože nebylo
co ovládat. Teď je tam `<video>` s podepsanou adresou, která odpovídá po
částech (`206`), takže jde přeskakovat.

Panel s informacemi u toho přestal tvrdit, že každá fotka je HEIC 4032 × 3024
z iPhonu 15 Pro při ƒ/1,8 · 1/240 s · ISO 64. Co aplikace o snímku neví, ten
řádek prostě nemá.

### 5. Content-Security-Policy

Ověřeno útokem: vložený `<script src>` z cizí adresy, odeslání dat pryč
i sledovací pixel prohlížeč odmítl. Devatenáct obrazovek přitom projde bez
jediného porušení.

`'unsafe-inline'` a `'unsafe-eval'` v politice zůstávají a je to napsané
i v kódu: běh prototypu má skripty přímo v dokumentu a JSX se překládá
v prohlížeči Babelem. Bez toho se aplikace nespustí vůbec. Zbytek je zamčený —
`object-src 'none'`, `base-uri 'self'`, `form-action 'self'`.

### 6. Service worker po nasazení

Verze paměti byla napsaná napevno (`v4`), takže se skořápka po nasazení
neuklidila: ikony, písma a balík designového systému se podávaly staré, dokud
si někdo nevymazal paměť prohlížeče. Verze se teď počítá z časů souborů
prototypu a manifestu sestavení.

Otevřená karta si o novou verzi řekne jednou za hodinu a po převzetí se načte
znovu — dvojice, která má aplikaci pořád otevřenou na druhém monitoru, jinak
viděla starou verzi celé dny. Při **první** návštěvě se nenačítá: tam žádná
výměna není.

### 7. Kontrola konfigurace před nasazením

Přestala být větou v dokumentu:

```bash
php artisan galerie:pred-nasazenim
```

Skončí nenulově, když je zapnuté ladění, chybí `APP_KEY`, `APP_URL` nejede
přes HTTPS, sezení chodí po nešifrovaném spojení, fronta běží v režimu `sync`
nebo chybí dokument prototypu či sestavené rozhraní. Zbytek jen vypíše.

### 8. Pint na celém stromě

466 souborů. Mění se jen tvar — `git diff -w` je z devíti tisíc řádků na
necelých sedm a zbytek jsou závorky kolem jednořádkových `if`. Ve vlastním
commitu, protože se prolne s každou otevřenou větví.

## Ověřeno jako v pořádku

| Co | Jak se to ověřilo |
| --- | --- |
| Vzkaz od hosta nejde zneužít ke skriptu | uložený `<script>` i `<img onerror>` se vykreslí jako text; `window.__XSS` zůstalo `false` |
| Platební oznámení nevěří tomu, co přišlo | `settle()` se doptá brány; pole z požadavku samy o sobě nic nemění |
| Webhook Disku ověřuje kanál | porovnává `X-Goog-Channel-Token` s uloženým |
| `PUT storage/{path}` z frameworku | vyžaduje podepsanou adresu, jinak 404/403 |
| Náhledy mřížky a video | podepsaná adresa na jediný soubor a den, ne token v URL |
| Bezpečnostní hlavičky | `nosniff`, `SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, CSP, HSTS přes HTTPS |
| Tlačítka bez obsluhy | z 1268 žádné |
| Průchod aplikací | 52 obrazovek bez chyby v konzoli a bez porušení CSP |
| Testy | 1159 zelených |

## Opraveno ve třetím průchodu

### `xRows` — sklad, do kterého se z většiny nepsalo

Šestnáct z třiceti dvou klíčů teď chodí ze skutečných tabulek a zpátky do nich:
`datesSaved`, `gifts`, `ticket`, `travelInbox`, `recipes`, `weekMenu`, `voice`,
`tripsPlanned`, `tripsPast`, `placesWish`, `placesVisited`, `accounts`, `films`,
`inbox`, `tagMerge`, `vault`. Zápis obstarává `SeznamyVeStavu`: vezme si z patche
jen klíče, které tabulku mají, a zbytek nechá projít do stavu — vyhodit celý
`xRows` kvůli sousedovi by znamenalo ztratit nákupní seznam.

Dva z nich chodí **i prázdné**, protože prázdno je u nich odpověď, ne rozbitá
obrazovka:

- `tagMerge` nabízel sloučit `#jidlo + #jídlo`. Takové dva štítky vedle sebe
  existovat nemůžou — `tags` má jednoznačný index na `(gallery_space_id, slug)`
  a obě pravopisné podoby mají tentýž slug.
- `vault` psal u každé složky „šifrováno". Aplikace obsah trezoru **nešifruje**;
  schová ho před mřížkou, hledáním a sdílením.

Cestou se ukázalo, že `AutoTagCommand` hledal štítek podle jména, ne podle slugu:
u prostoru, kde už „jidlo" existuje, by návrh „jídlo" spadl na omezení databáze
a shodil celou dávku.

### Konflikt při souběžném zápisu se přizná

Server teď porovnává revize **po klíčích**: zapíše všechno, o co se ti dva
nepřetahují, a v odpovědi pošle `strety` — seznam klíčů, které zahodil. Datová
vrstva z toho udělá událost `galerie-stret` a obrazovka ukáže hlášku. Obrazovka,
která se sama vrátí o krok zpět a mlčí, je horší než přiznaný střet.

### Trezor zamykala konstanta z veřejného souboru

Nejhorší nález celého auditu. Obrazovka „Trezor" porovnávala zadané heslo
s `VAULT_PWD` z `galerie-data.js` — ze souboru, který server podá komukoli —
a pod kolonkou ho **sama vypisovala**. Druhé ověření „kódem z aplikace" se
kontrolovalo jen na šest číslic, takže prošlo `000000`. Patnáctiminutové sezení
bylo číslo v paměti karty: k souborům nedosáhlo a dalo se přepsat v konzoli.
Za tou zdí pak nebyl obsah dvojice, ale čtyři napsané složky včetně „Skeny pasů".

Přitom skutečný zámek v aplikaci existoval celou dobu: `vault_unlocked_until`
v sezení a `ProtectVaultMedia` na výdeji médií. Nový `TrezorController` je jeho
druhé okno — heslo do galerie proti `users.password`, tentýž klíč v sezení,
neúspěšný pokus do auditu. Zamčený trezor **neposílá, co je v něm**.

Náhledy se u trezoru záměrně neposílají: jinde chodí jako podepsaná adresa
s platností do konce zítřka, což by z nejcitlivějších souborů udělalo odkazy
fungující bez přihlášení a přežívající zamčení.

Ověřeno v prohlížeči: `zadar2026` neotevře nic, správné heslo ano, odpočet
přežije obnovení stránky a po zamčení obsah z odpovědi zmizí.

### Přidání do trezoru trezor vyprazdňovalo

`TrezorVeStavu` bere `vaultAdded` jako **úplný** seznam a co v něm není, vrací
do knihovny. Obrazovka ho ale začínala prázdný, takže první přesun poslal jediné
id — a všechno ostatní se odemklo a vrátilo do mřížky. Teď se seznam odpíchne od
toho, co poslal server. Ověřeno: po přesunu druhé fotky mají obě `is_hidden = 1`.

### „Přeskočit zámek" a heslo natištěné na zamčené obrazovce

Na přihlašovací obrazovce stálo tlačítko, jehož vlastní hláška zněla „v prototypu
jde dovnitř i bez kódu" — jedno klepnutí otevřelo celý archiv bez hesla i bez
kódu, o dva řádky pod slibem, že se dovnitř nedostane nikdo. Pryč je z obou
rozvržení, spolu s:

- porovnáním hesla proti `LOCKPWD` (bez serveru se přihlásit nedá),
- nápovědou „Prototyp — heslo zadar2026 pro oba účty",
- nápovědou vypisující kódy obou lidí i obnovovací kód,
- hláškou, která obnovovací kód psala do oznámení.

`LOCKWHO` a `LOCKMAIL` teď dodává server ze členů prostoru: přihlašovací kolonka
už nepředvyplňuje `adrian.stanek@gmail.com` cizí dvojici.

### Dědictví slibovalo, co aplikace neumí

Formulář „Přístup a dědictví" hlásil „Aktivní: Klára Staňková
(klara@example.com) se dostane k archivu po 180 dnech bez přihlášení" — jméno,
které nikdo nezadal, a pojistka, kterou v aplikaci nic nedělá. Předvyplněný
důvěrník je pryč a text říká, co se doopravdy stane: přání se uloží a ukáže,
předání zařídí člověk.

### Kolekce ze dvou skupin si přepisovaly navzájem

`AL` posílá knihovna i systém. Hlavička si data drží v ploché mapě pro chvíli,
kdy runtime prototypu načte `galerie-data.js` podruhé — a prostým přiřazením
si tam skupiny přepisovaly navzájem, takže po druhém průchodu se polovina
klíčů vrátila na ukázková data. Poznat se to dalo jen na časování.

### Kód zámku aplikace patří člověku, ne souboru

Šestimístný kód se porovnával v prohlížeči s `LOCKPIN` z `galerie-data.js` —
s kódy **obou** partnerů napsanými ve veřejném souboru. Obrazovka je navíc
sama vypisovala v nápovědě nad klávesnicí („Prototyp — Adrian 240613, Makinka
190522, obnovovací kód zadar-2026-oba"), takže kód partnera měl každý po ruce.
A protože porovnání běželo na klientovi, dal se zámek otevřít i bez kódu.

Kód si teď nastavuje každý sám a leží u jeho účtu jako haš (`users.app_lock_pin`,
`app_lock_recovery`, obojí `hashed`, obojí v `$hidden`). Nevidí ho partner,
nevidí ho odpověď serveru a z databáze se přečíst nedá — dá se jen ověřit.
Ven chodí jedině `ZAMEK = { nastaveno, delka, zmeneno }`, aby obrazovka věděla,
jestli má kód chtít.

`ZamekController` k tomu drží čtyři cesty: `stav`, `nastav` (první kód proti
heslu do galerie, další proti tomu starému), `over` a `obnov`. Obnovovací kód
se vydává **jednou**, hned po nastavení, a po použití se spotřebuje i s kódem
zámku — jednorázová záloha, ne druhé trvalé heslo na papíře. Při přepisu se
odpouští, jak ho člověk opíše z papíru: malá písmena i mezery místo pomlček.

Dvě věci, které z toho vyplynuly:

- **Zamykat se dá jen tím, čím se dá odemknout.** Dokud si člověk kód
  nenastavil, nezamyká se — ani po nečinnosti, ani tlačítkem. Dřív to nevadilo,
  protože kód „měl" každý; teď by ho automatické zamčení vystrnadilo z vlastní
  galerie až do dalšího přihlášení heslem.
- **Limit na hádání se sdílel s běžným klepáním.** `ThrottleRequests` si bez
  třetího parametru klíčuje pokusy jen podle uživatele a adresy, takže všechny
  cesty ve skupině měly **jedno počítadlo**: pár minut prohlížení fotek by
  vyčerpalo limit 5/min na obnovovací kód. Každá tvrdší cesta má teď vlastní
  předponu.

Ověřeno v obou rozvrženích: kód partnera cizí zámek neotevře, kód z ukázky
(`240613`) taky ne, obnovovací kód z ukázky (`zadar-2026-oba`) taky ne, a po
použití toho pravého se aplikace otevře a rovnou nabídne nastavit nový.

### Přidání do trezoru trezor vyprazdňovalo — i mimo obrazovku trezoru

Táž chyba jako výš seděla ještě ve dvou hromadných akcích („Do trezoru"
u výběru a hromadná úprava): obě četly `s.vaultAdded || []`, takže první použití
poslalo serveru jen právě vybrané a všechno ostatní z trezoru odemklo. Všechna
tři místa teď berou úplný seznam z `vaultIds()`.

## Co zbývá

### Zbylých šestnáct klíčů `xRows`

`shopping`, `snoozed`, `inboxDone`, `datesGen`, `balancing`, `story`, `print`,
`orders`, `dupes`, `users`, `jobs`, `api`, `tarify` a další žijí dál jen ve
stavu. Není to tichá lež — data se ukládají a přežijí zavření záložky —, ale
nedá se na ně zeptat odjinud: nákupní seznam neuvidí připomínka.

## Co se nechalo úmyslně

| Kolekce | Proč |
| --- | --- |
| `LOCKPIN`, `LOCKREC`, `LOCKPWD`, `VAULT_PWD` | v ukázce zůstávají, ale **nic je už nečte**; testy hlídají, že ani jedno z těch hesel nic neotevře |
| ~53 katalogů rozhraní | názvy obrazovek, měsíce, ikony, prázdné stavy — nejsou to data dvojice |
| `AMISS`, `CYC_TODAY`, `GRAF` | dopočítává si je dokument sám |

A jedno pravidlo, které platí napříč: **prázdná kolekce se neposílá**, takže
dokud dvojice nic nemá, kreslí obrazovka ukázku. První zápis pak ukázková data
propíše do databáze — je to daň za to, že prázdná obrazovka a rozbitá aplikace
vypadají z pohledu člověka stejně. U akcí je to naopak: odpověď nese seznam
`prazdne`, aby vyřešený rozpor nebo zneplatněný odkaz zmizel hned.
