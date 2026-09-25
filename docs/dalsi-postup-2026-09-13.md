# MAKI Gallery — jak pokračovat (13. 9. 2026)

Stav po bezpečnostním auditu a dodělávkách z 13. 9. 2026. Seznam je seřazený
podle toho, co blokuje ostatní. U každé položky je důvod, ne jen úkol.

---

## 1. Nejdřív: nasadit (blokuje všechno ostatní)

Na produkci nic z tohoto kola neběží. SSH na server je z vývojového počítače
odmítnuté a `git push` z relace se nepodařil, takže:

1. Na vývojovém počítači: `git push origin main`
2. Na serveru: `cd /www/wwwroot/gallery.stanektech.cz && ./deploy.sh`

Nasazení samo spustí tři nové migrace:

| Migrace | Co udělá na produkci |
|---|---|
| `sjednotit_ucty_dvojice` | Makinka → **Makinka Kubíčková**, e-maily **info@stanektech.cz** a **marketa@stanektech.cz** (hesla beze změny; obsazenou adresu nepřepíše) |
| `partner_dvojice_neni_host` | druhý člen s výchozí rolí `viewer` dostane `editor` — jinak by ho nová brána rolí nepustila dovnitř |
| `create_shopping_list_items` | tabulka pro ručně připsané položky nákupu |

`composer.lock` se změnil (league/commonmark 2.10.1), deploy proto spustí
`composer install`.

Třetí kolo (bod 2c) přidává jednu migraci: `add_note_to_transactions`
(sloupec `note` u plateb — poznámka k platbě z galerie).

Čtvrté kolo (bod 2d) přidává migraci `smazat_hesla_ze_stavu_dvojice` — smaže
ze sdíleného stavu hesla a kódy, které tam počítač dřív poslal (viz 2d),
nastavení zámku a staré místní řádky telefonu (2e). Páté kolo (2e) migraci
nepřidává.

Druhé kolo (galerie, viz bod 2b) žádnou migraci nepřidává. Po nasazení je ale
potřeba **jednou dogenerovat náhledy** — do té doby se chodily vydávat originály
(náhledy kvůli chybě v Intervention Image 4 nevznikaly vůbec):

```
php artisan gallery:thumbnails
```

### Po nasazení zkontrolovat

- [ ] `php artisan gallery:thumbnails` doběhl; mřížka se načítá rychle a fotka
      v prohlížeči je ostrá.
- [ ] Přihlášení obou na počítači i telefonu novými adresami.
- [ ] Výpis „Ukázková data v databázi" na konci `deploy.sh`. Po přečtení
      smazat řádky z ukázky: `php artisan gallery:ukazkova-data --smazat`.
- [ ] Rozhodnout o testovacích receptech „Marry Me Chicken" a „Zapečená kuřecí
      prsa" (založila je migrace ze 15. 7.; kontrola ukázkových dat je nehlásí).
- [ ] Mapa (špendlíky, popisky), koš (vyhodit a vrátit fotku), menu na týden
      → nákupní seznam.
- [ ] `php artisan gallery:doctor` — fronta a plánovač běží.

### Změny chování, o kterých mají oba vědět

- **Přihlášení zařízení vyprší po 90 dnech bez použití** (`GALLERY_TOKEN_IDLE_DAYS`,
  `0` = nikdy). Telefon používaný denně se neodhlásí.
- **Dvoufázové ověření platí i v aplikaci.** Kdo ho má zapnuté (staré
  rozhraní `/prehled`), dostane po hesle dotaz na kód.
- **Host** (role „host" v administraci) se do aplikace dvojice nepřihlásí —
  ani do starého rozhraní (2h) — vidí jen odkazy, které dostane. Tak to
  administrace vždycky popisovala.
- **Klíč k API „jen čtení"** opravdu jen čte.
- **Telefon teď opravdu ukládá.** Do 13. 9. neposlal na server ani jeden
  zápis stavu (třída měla dvakrát `componentDidUpdate`, druhá přepsala první).
  Nastavení, štítky, srdíčka a další změny z telefonu se nově propíšou i do
  počítače. Po nasazení pár dní hlídat počet `PATCH /api/state` v logu (WAF).
- **Smazání vzkazu hosta a sloučení osob se ptá** — obojí je natrvalo.
- **Fotka přesunutá do trezoru přestane být vidět všude hned** — i přes
  odkazy vydané dřív (oblíbené, archiv, chat, sdílená stránka).
- **Nový zápis deníku na počítači začíná jako soukromý** (jako v aplikaci);
  sdílí se zrušením zaškrtnutí. Dopis do budoucnosti se ukládá jako časová kapsle.
- **Trezor na telefonu chce heslo do galerie** (dřív tlačítko „Face ID" bez ověření).
- **Nastavení ukazuje jen to, co funguje** — sekce Import, Ticho a Konec
  aplikace u dvojice nejsou; jméno, e-mail, heslo a fotka se mění přímo tam.

---

## 2. Co se v tomhle kole opravilo

| Commit | Obsah |
|---|---|
| `54dc4ed2` | Účty dvojice, kontrola ukázkových dat v `deploy.sh` |
| `039da9d6` | Koš na serveru, deník a série ze skutečných dat, tablet bez přetékání |
| `7e361990` | **Bezpečnost** — viz níže |
| `1b8824ee` | Menu na týden, nákupní seznam a zařazení transakcí se ukládají do knihy |
| `eedc2528` | Telefon: kapsle, dárky a rozvaha nelžou; limit stahování přes odkaz |

Bezpečnostní opravy (`7e361990`), od nejvážnější:

1. **`mapa.html` spouštěla cizí skript.** Brala zprávy od libovolného okna
   a jméno místa vkládala jako HTML; cizí stránka ji mohla otevřít a přečíst
   token z `localStorage`. Opraveno a ověřeno v prohlížeči.
2. **Odebraný přístup šel obejít novým přihlášením** (heslo i otisk).
3. **`/sanctum/token` obcházel dvoufázové ověření.**
4. **Host viděl deník, finance, trezor** a mohl smazat společný stav.
5. **Klíč „jen čtení" zapisoval.**
6. **Tokeny neměly platnost.**
7. **HTML převlečené za RAW (`.cr2`) se nahrálo** a vydávalo jako stránka
   na adrese galerie. Teď se odmítne a soubory jdou s CSP `sandbox`.
8. **Nahrávání velkého videa shodilo zbytek API na 429** (sdílené počítadlo limitu).
9. Stav nad 1 MB se odmítne, celý stav smaže jen vlastník, Comgate „PAID"
   se přijme jen s referencí a částkou té platby, commonmark bez upozornění.

Testy: 1262 PHP testů, všechny prošly. Detektor překryvů a přetékání prošel
161 stránek počítače (600/768/1024 px) a všechny obrazovky telefonu
(320–430 px), s plnými i prázdnými daty.

---

## 2b. Druhé kolo — galerie (13. 9. odpoledne)

| Commit | Obsah |
|---|---|
| `8bbfe5b2` | `/files` jen s podpisem nebo pro člena prostoru; sdílená stránka neposílá celý model fotky, koš ani trezor |
| `8ed80ceb` | Náhledy vznikají (Intervention Image 4), ostrý prohlížeč fotky, **otočení a výřez se ukládají** (s návratem k originálu), správa alb (přejmenovat, přesunout, titulní fotka, sloučit, smazat/obnovit) |
| `8ab8dc6f` | **Telefon ukládá stav**; hromadné stažení výběru jako ZIP; sdílení, alba a štítky z telefonu na serveru |
| `76571c8b` | Vzkazy hostů: skrýt / smazat / přilepit jako popisek platí i na sdíleném odkazu |
| `896bd8ad` | Lidé: přejmenování, skrytí a sloučení osob v databázi |
| `8e14dd3f` | Album osoby opravdu vznikne; odložení připomenutí na týden (ne navždy) |
| `ca39e126` | Ruční platba, rychlý úkol a zápis do deníku z telefonu do databáze; bez vymyšlené cenové historie míst a ukázkového cíle spoření |
| `f86061d9` | **Bezpečnost:** originál z trezoru jen s odemčeným trezorem; strop nahrávání po částech; sdílení funguje i hostům přihlášeným do jiné galerie; recept/hodnocení nevydá fotky z trezoru |
| `c142c567` | **Bezpečnost:** podepsané adresy fotky, která odešla do trezoru, přestanou platit |
| `aa73f860` | Výběry ze shody zakládají album, výroční album opravdu přidá návrh tisku |

Nové cesty API (všechny za přihlášením dvojice, jen vlastní prostor):
`POST media/archiv`, `POST media/{uuid}/uprava`, `PATCH|DELETE alba/{uuid}`
+ `presunout|titulni|obnovit|sloucit`, `PATCH|DELETE vzkazy-hostu/{uuid}`
+ `prilepit`, `PATCH osoby/{id}` + `sloucit`, `POST platby/rucne`,
`POST rychle/denik`, `POST rychle/ukol`.

Testy: **1308 PHP testů**, všechny prošly (s GD). V prohlížeči ověřeno na
vývojovém serveru: sdílení a zneplatnění odkazu z telefonu, založení, doplnění
a smazání alba z telefonu, štítky a srdíčka z telefonu až do databáze,
moderace vzkazu (skrýt, přilepit, odlepit, smazat), rychlý zápis deníku
a úkolu, hláška bez účtu u platby.

---

## 2c. Třetí kolo — žádná tlačítka naprázdno (13. 9. večer)

Cíl: žádná hláška „uloženo / odesláno / sloučeno", za kterou se nic nestalo,
žádná ukázková data u dvojice, žádné prázdné klepnutí.

| Commit | Obsah |
|---|---|
| `80a42210` | Finance z galerie do knihy (poznámka, rozpočet, opakování, rozdělení, plánované platby, převody, limity, cíle, vklady, vyrovnání), sdílení cyklu v databázi, tisk, připomínka druhému do telefonu, úpravy fotky z telefonu |
| `9ca26e4d` | Místa (nový cíl, „byli jsme", poznámka), cesty (výdaj, bod programu, posun), dárky a zbylé „zatím neumíme" |
| `fc118fb0` | **Deník na počítači do databáze** (nový, úprava, smazání; cizí soukromý zápis nejde změnit ani najít; smazaný se nevrací), hlasovky přehrávají skutečnou nahrávku a mažou se na serveru, **sloučení štítků**, poděkování za práci v domácnosti upozorněním, detail řádku místo prázdné hlášky, administrace bez falešného seznamu |
| `7f3a1170` | **Telefon:** trezor odemyká server heslem (dřív „Face ID" bez ověření), domácnost / rozhodnutí / sliby / rodina / žádosti / kapsle se ukládají, inbox a nákupní seznam do databáze, skutečný export (tisk fotoknihy, ZIP originálů, CSV plateb, itineráře), synchronizace a offline bez simulace, instalace a upozornění doopravdy |
| `e65a6a7a` | **Nastavení ze skutečného stavu** (jméno a e-mail, heslo, profilová fotka přes API účtu; úložiště, odkazy a zařízení spočítané; sekce bez funkce pryč), balíček k odchodu bez vymyšlené velikosti |
| `0a203b84` | U dvojice se nedá „prohlížet jako druhý" (dárky, první spuštění) |

Nové cesty API: `POST|PATCH|DELETE denik`, `POST stitky/sloucit`,
`POST pripomenout` (s `druh: podekovani`), `finance/*`, `mista*`,
`cesty/{id}/vydaj|program`, `cesty/program/{id}/posunout`. Galerie nově volá
i existující `PATCH v1/profil`, `PUT v1/profil/heslo`, `POST v1/avatar`
a `DELETE v1/voice-notes/{uuid}`.

Testy: **1345 PHP testů**, všechny prošly. V prohlížeči ověřeno na vývojovém
serveru: deník (nový soukromý → sdílený → smazaný), přehrání hlasovky, trezor
na telefonu se špatným heslem, nákupní seznam z telefonu až do databáze
a zpět, inbox z telefonu, jméno v nastavení, chybné současné heslo, detail
řádku seznamu.

---

## 2d. Čtvrté kolo — co jedno zařízení posílalo druhému (13. 9. noc)

Nejvážnější nález: **počítač ukládá do společného stavu každý klíč**, který
nemá výslovně vyřazený, a druhé zařízení si ho při synchronizaci převezme.

| Commit | Obsah |
|---|---|
| `b83fe341` | Neúplné formuláře řeknou, co chybí; přetažená událost v kalendáři mění skutečné datum |
| `fcc4d7cd` | Načtení bez tří 404 (`/{{ mapSrc }}` apod.) a bez chyb SVG v konzoli |
| `b47ce5f9` | Dokument galerie se při otevření stahuje jednou (service worker + ETag/304) |
| `d46897fd` | Ikony Phosphor z unpkg s kontrolou integrity (SRI) — bezpečnost bod 3 hotový |
| `61646857` | **Bezpečnost:** heslo do galerie z dialogu kódu, kódy zámku, obnovovací kód, heslo k odkazu a e-mail zámku už neodcházejí do stavu; odpočet trezoru nezapisuje každou sekundu; migrace smaže uložené |
| `e49de9b9` | **Bezpečnost:** pokusy o heslo k trezoru u účtu (ne v sezení), prodlužující se uzavření, platí i pro `/vault/unlock` — bod 11 hotový |
| `36e2d907` | Záložky, otevřený detail, měsíc, výběry a šířka okna se nesdílí (Makinka přepnula záložku a Adrianovi se přepnula taky); hlasování ve Společných výběrech pod jménem a jen za sebe, i z telefonu |
| `18cfaec1` | Kapsle „otevřít společně", potvrzení kolečka, hvězdičky filmů a sporné body: každý jen za sebe (server hvězdičky ani body druhého nepřijme) |
| `4f35a897` | **Bezpečnost:** kód zámku počítá pokusy u účtu (jako trezor); zámek trezoru hlídá fotku i v druhém prostoru účtu a s tokenem bez sezení nepadá na 500; prostor dvojice je pevně výchozí/nejstarší |
| `76825bad` | Telefon: rituály bez vymyšlených poznámek a odpočtu, Úklid → „Porovnat série" nepadá |
| `61cd90c4` | Otázka na dva: rozepsaná odpověď a „odesláno" už nejdou druhému (dřív mu odemkly cizí odpověď dřív, než napsal svou); rozepsané texty z polí (dopis, dárek, překvapení…) se nesdílí; telefon ukazuje skutečné odpovědi |
| `0d45a39e` | Motiv, panel, velikost náhledů a řazení patří zařízení (v prohlížeči), ne dvojici |
| `43fbbd31` | Kapacita týdne, obálka, čas pro sebe, otázka dne, kdo mluví za nás a verze pravdy: server dával `a` zakladateli prostoru, obrazovka ho popsala jménem toho, kdo se dívá — **Makinka viděla Adrianova čísla pod svým jménem a její oprava kapacity se zapsala jemu** |

Pravidlo pro další úpravy prototypu: nový stavový klíč na počítači — patří
dvojici (data), nebo zařízení (navigace, formulář, odpočet, heslo)? Zařízení →
`persistSkip`; tajné → i `CoupleState::NEUKLADAT`. Data o osobě pod jménem,
nikdy pod `A`/`M` — ty jsou na každém zařízení opačně.

### Změny chování, o kterých mají oba vědět (2d)

- Po obnovení stránky se aplikace otevře na výchozích záložkách — poslední
  záložka se už nepamatuje přes společný stav (dřív ji „pamatoval" i partner).
- Ve Společných výběrech, u kapsle „otevřít společně" a u kolečka hlasuje
  a potvrzuje každý na svém zařízení; tlačítko „Oba ano" je jen v ukázce.
- Trezor po třech špatných heslech zavře na 30 s, pak 1, 2, 4… až 15 minut;
  smazání cookies počítadlo nevynuluje.

---

## 2e. Páté kolo — co obrazovka čte a nikdo neposílá (13. 9. pozdě večer)

Nový druh kontroly: šablona se projde proti hodnotám, které jí aplikace
opravdu dává — jen viditelné větve, v 266 stavech počítače, na 79 obrazovkách
telefonu a po kliknutí na všechno, co otevírá dialog nebo list (s plnými
i prázdnými daty a s prázdnou knihovnou). Chybějící pole se totiž vykreslí
jako prázdno bez jediné chyby v konzoli.

| Commit | Obsah |
|---|---|
| `9d915eb5` | **Nastavení zámku patří zařízení** — „Zamknout při spuštění: Vypnuto" na jednom zařízení vypínalo zámek i druhému |
| `f05cf23f` | Prázdné sekce řeknou, co v nich je (finance, nedělní deset minut, kniha příběhu, itinerář) |
| `5070df35` | Vnořené záložky bez „NaN %" a bez rozborů z prázdných dat |
| `430b3144` | **Bezpečnost:** náhled fotky v koši a obrázek ze smazané zprávy přestanou platit i přes dřív vydanou adresu — bod 12 hotový |
| `ee2ad787` | Úklid starých místních řádků telefonu ze stavu (migrace z 2d) — bod 13 hotový |
| `dcc3278f` | Dvoufázové ověření: políčko na přihlašovací obrazovce místo dialogu prohlížeče — bod 5.3 hotový |
| `78c0c8d6` | Testy hlídají pravidla dokumentů prototypu (`sc-camel-`, `persistSkip`, SRI, políčko 2FA) |
| `12f8d88c` | **Rozpočet a obálka se zakládají z galerie** (dřív slepá ulička „založte ho v Rozpočtech"); pruhy v Přehledech a v rozpočtu na telefonu se konečně vyplní; volba druhu události v kalendáři nebyla vidět; hlasovky bez tlačítek naprázdno; telefon nepadá na „Útraty" u cesty; prázdný deník otevře nový zápis |
| `87659c81` | **„Přidat" v kalendáři s prázdnou knihovnou shodilo aplikaci**; „Fotky z tohoto dne" jsou opravdu z toho dne; Co dnes uvařit bez receptů bez mrtvých tlačítek; panel chatu ukazuje snímky z bublin |

Nové cesty API: `POST finance/rozpocet/zalozit` (společný měsíční rozpočet,
limity z průměru tří celých měsíců útrat, zároveň původní plán) a
`POST finance/rozpocet/obalka` (limit obálky, kategorii „Obálka pro sebe"
založí, když žádná obálka není). Žádná nová migrace.

Testy: **1370 PHP testů**, všechny prošly (z toho 4 nové pro rozpočet a obálku).

### Změny chování, o kterých mají oba vědět (2e)

- **Zámek aplikace se nastavuje na každém zařízení zvlášť** (zamknout při
  spuštění, automatické zamčení, otisk). Po nasazení si ho každý nastaví
  znovu — ze společného stavu se nepřebírá, platí bezpečné výchozí hodnoty.
- Kdo nemá rozpočet, založí ho tlačítkem v Rozpočtech (počítač) nebo ve
  Financích (telefon). Limity kategorií bez útrat začínají na nule a mění
  se tlačítky − / + u kategorie.

---

## 2f. Šesté kolo — co server mazal podle staré kopie (13. 9. v noci)

Průchod tlačítek uvnitř dialogů a čtení převodníků stavu do tabulek
(`app/Services/Provoz/*VeStavu`) ukázal nejvážnější nález celého dne:
**server bral seznam z prohlížeče jako úplný a mazal, co v něm chybělo.**
Seznam v prohlížeči je ale kopie z doby načtení (data se během dne
neobnovují), často s limitem.

| Commit | Obsah |
|---|---|
| `1fdb6995` | **Kalendář a úkoly:** úprava jedné události mazala z databáze události, které přidal ten druhý, cesta nebo automatizace, i ty nad limitem seznamu; **každé uložení kalendáře rozeslalo znovu už doručené připomínky**; nový úkol/událost se zakládaly při každém odeslání znovu; první úkol dvojice se nezapsal nikdy; první odškrtnutí odznačilo všechno hotové; kategorie úkolů jako skutečné seznamy v databázi |
| `a12483a9` | **Trezor:** „Do trezoru" u zamčeného trezoru (nebo z druhého zařízení) vrátilo celý trezor do mřížky, hledání a sdílených odkazů; vrátit z trezoru teď jde jen výslovně a jen s odemčeným trezorem. **Přání, dárky, sliby, žádosti, rozvahy nákupů, záznamy prací, závazky, kapitoly, milníky, nouzový přístup, papírová záloha, pravidla, rodina, laskavosti a antirozpočet:** maže se jen to, co prohlížeč sám odebral (`__odebrane`) |
| `7d4d4860` | **Filmy, seriály, watchlist:** řádek se poznal podle pořadí (`films-3`) — po přidání či smazání titulu druhým dopadlo hodnocení na jiný film, starší seznam titul zdvojil a mazání trefilo vedle; teď podle uuid titulu |

Pravidlo pro další převodníky: **nikdy nemazat podle toho, co v odeslaném
seznamu chybí.** Prohlížeč posílá rozdíl (`OdebraneVStavu`, u kalendáře
a nástěnky `evZmenene/evZrusene`, `xBoardZmenene/xBoardZrusene`), nové
řádky se párují s identifikátorem z prohlížeče.

Testy: **1393 PHP testů**, všechny prošly. V prohlížeči ověřeno: kategorie
(založit, úkol do ní, přesuny tam a zpět bez zdvojení, smazání), událost
(přidat, dvakrát upravit, smazat, vrátit Zpět — v databázi pořád jedna),
přání přidané a odebrané na počítači i telefonu, film odebraný, přidaný a vrácený Zpět i přidaný a později smazaný. Kontrola šablon proti hodnotám znovu čistá (266 stavů).

### Změny chování, o kterých mají oba vědět (2f)

- „Uklidit hotové" na nástěnce úkoly archivuje — z nástěnky zmizí,
  v záložce Hotovo zůstanou.
- Vrátit fotku z trezoru do knihovny jde jen s odemčeným trezorem.

---

## 2g. Sedmé kolo — obsah během dne, worker a podstránky (14. 9. ráno)

| Commit | Obsah |
|---|---|
| `f8b87cf9` | **Obsah se obnovuje i během dne** — viditelná karta každé čtyři minuty a po návratu z pozadí (ne s čekajícím zápisem, otevřeným dialogem ani kurzorem v políčku); změny toho druhého jsou vidět bez obnovení stránky a kopie seznamů z odpovědí na zápis se po obnovení zahodí |
| `055d9221` | **Worker:** paměť jen pro statické soubory — zneplatněný sdílený odkaz dřív v prohlížeči dvojice dál ukazoval fotky z paměti a staré rozhraní dostávalo o krok starší data |
| `1ccd73e1` | Test: všech 99 podstránek bez parametru (i starého rozhraní) se otevře bez chyby serveru, přihlášenému i nepřihlášenému; všech 89 obrazovek starého rozhraní existuje a je v sestavení |
| `e8e3ec1e` | **Seznamy:** starší opis v kartě už nepřepíše úpravu druhého u jiné položky téhož seznamu — prohlížeč posílá jen změněné položky (`__zmenene`), server nezměněné nepřepisuje |

Ověřeno v prohlížeči na vývojovém serveru celé jádro galerie: nahrání fotky,
náhled a velký náhled, koš a vrácení, sdílený odkaz (host bez GPS, po
zneplatnění 404), trvalé odstranění; průchod tlačítek v listech telefonu
(79 obrazovek) bez kliknutí naprázdno. Testy: **1398 PHP testů**, všechny prošly.

Co zůstává: úprava **téže** položky oběma v rozmezí pár minut — platí poslední uložení.

---

## 2h. Osmé kolo — host a zadní vrátka (14. 9.)

| Commit | Obsah |
|---|---|
| `60767575` | **Host galerie bez dat dvojice.** API galerie hosta odmítalo, ale starší API `v1` kontrolovalo jen klíč (deník, finance, chat, knihovna, předplatné), stránky starého rozhraní vydávaly alba, koš, trezor i export přímo ze serveru a `/files/media/{uuid}/…` dalo originál každému členovi prostoru i bez podpisu — tedy i po zrušení sdíleného odkazu. Host teď v `v1` dostane jen svůj profil, fotku a upozornění, ze starého rozhraní se odhlásí s důvodem na přihlašovacím formuláři, heslem se do něj nepřihlásí a soubory má jen z podepsané adresy |
| `1632d4d6` | **Telefon: trvalé odstranění z koše** — dřív jen „Obnovit". Ptá se dvojím klepnutím, protože mizí i originál |

Ověřeno: 1406 PHP testů (nové: host v `v1`, ve starém rozhraní, při
přihlášení a v `/files`), v prohlížeči na telefonu nahrání → koš → „Smazat"
→ „Opravdu smazat" → `POST /api/kos/odstranit`, koš prázdný, v databázi
odstraněno; kontrola šablony telefonu bez chybějících polí.

### Změny chování, o kterých mají oba vědět (2h)

- Host (role „host" v administraci) se nepřihlásí ani do starého rozhraní
  `/prehled` — dozví se proč. Sdílené odkazy mu fungují dál.

---

## 2i. Deváté kolo — odmítnutý zápis bez smyčky (14. 9.)

Zápis stavu, který server odmítl, se posílal znovu každé čtyři vteřiny
**navždy** — i když ho server odmítl natrvalo. Prošlé přihlášení (90 dní bez
použití, odvolané zařízení), odebraný přístup nebo příliš velký zápis tak
z každé otevřené karty dělaly smyčku 15 požadavků za minutu (stejný vzorec,
kvůli kterému firewall už jednou adresu dvojice zablokoval). Čekající zápis
navíc zastavil dotazy na změny toho druhého. Service worker nechával
odmítnuté zápisy ve frontě a posílal je při každém probuzení s tokenem
z doby, kdy vznikly.

Teď:
- **401/403** — token se zahodí, počítač i telefon ukážou přihlášení
  s hláškou „Přihlášení na tomhle zařízení skončilo" (u odebraného přístupu
  důvod ze serveru); zápisy počkají a po přihlášení (heslem i otiskem) odejdou.
- **413/422/400** — patch se pošle po klíčích; klíč, který server nevezme
  ani sám, se zahodí a aplikace řekne „Poslední změna se neuložila".
- **Výpadek a chyby serveru** — další pokus za 4 s, 8 s, … nejvýš 2 minuty.
- Worker: „Odeslat" posílá aktuální přihlášení; natrvalo odmítnutý zápis
  z fronty zmizí. Telefon nově hlásí i střet o tutéž věc (dřív se potichu
  vrátil o krok zpět).
- **Hromadné nahrávání** se po 401/403 zastaví — dřív zkoušelo každý zbylý
  soubor dvakrát (u 500 fotek tisíc odmítnutých požadavků).

| Commit | Obsah |
|---|---|
| `ec5e4a62` | Zápis stavu: 401/403 → přihlášení, 413 → po klíčích, výpadek → prodleva; worker bez věčné fronty; střet na telefonu |
| `83886e9d` | Hromadné nahrávání se bez přihlášení zastaví |

Ověřeno v prohlížeči: neplatný token → přihlašovací obrazovka s hláškou
(počítač i telefon), zápis bez přihlášení 0× odeslán, po vrácení tokenu
hned `PATCH 200`; zápis přes 1 MB → malý klíč prošel, velký jednou 413,
hláška a žádný další pokus; nahrání 6 souborů bez přihlášení → 3 požadavky
místo 12. Testy: **1409 PHP testů**, všechny prošly.

### Změny chování, o kterých mají oba vědět (2i)

- Když přihlášení na zařízení skončí (90 dní bez použití, „odhlásit ostatní
  zařízení" z jiného zařízení), aplikace hned ukáže přihlášení a zahodí
  kopii dat v prohlížeči (2j) — dřív vypadala normálně a nic se neukládalo.
  Změny udělané před tím odejdou po přihlášení, pokud se karta mezitím nezavře.

---

## 2j. Desáté kolo — zapomenuté heslo a paměť prohlížeče (14. 9.)

**Zapomenuté heslo nemělo cestu.** Přihlašovací obrazovka aplikace na obnovu
nikam neodkazovala, e-mail chodil anglicky („Reset Password Notification"),
po obnovení zůstala přihlášená všechna zařízení (i to, kvůli kterému člověk
heslo měnil) a formulář tvrdil „odkaz byl odeslán" i tam, kde e-maily nechodí.

- Počítač i telefon: odkaz **Zapomenuté heslo?** pod přihlášením.
- E-mail česky; odkaz platí 60 minut.
- Nové heslo aspoň 10 znaků (jako při změně v Nastavení); **odhlásí všechna
  zařízení** (klíče aplikace i sezení starého rozhraní), zapíše se do protokolu.
- Po změně zpět do aplikace s hláškou „Heslo je změněné — přihlaste se novým
  heslem".
- Odpověď formuláře neprozradí, jestli k adrese účet existuje.
- Když server e-maily neposílá (`MAIL_MAILER=log`/`array`), stránka to řekne
  místo formuláře a `gallery:doctor` hlásí varování. Nové heslo pak nastaví
  správce serveru: `php artisan gallery:ucet adresa@… --heslo`.

**Paměť prohlížeče mezi účty.** Obsah z API se ukládal na 30–60 s
(`private, max-age`), ale `Vary` měl jen `X-Inertia` — po odhlášení jednoho
a přihlášení druhého na témž počítači prohlížeč podával obsah prvního
(i soukromé zápisy deníku) a s neplatným tokenem vracel data z paměti. Teď
`Vary: Authorization`. Worker navíc ukládal i odpovědi 401 (přepsaly dobrou
kopii pro offline) a po odvolání zařízení v něm data zůstávala — ukládá jen
úspěšné, po 401 kopii smaže; datová vrstva po odhlášení zahodí kopii stavu.

| Commit | Obsah |
|---|---|
| `e28a2fad` | Zapomenuté heslo: odkaz z aplikace, český e-mail, odhlášení všech zařízení, poctivá stránka bez e-mailů, `gallery:doctor` |
| `9e1e385f` | `Vary: Authorization`; worker bez kopie 401 a bez dat po odhlášení; datová vrstva zahodí kopii stavu |

Ověřeno v prohlížeči: odkaz na obou přihlašovacích obrazovkách vede na
formulář; bez e-mailů hláška místo formuláře; stránka nového hesla s limitem
10 znaků a návratem do aplikace; `?heslo=zmeneno` → hláška na počítači
i telefonu; neplatný token → mezipaměť dat i kopie stavu pryč, přihlašovací
obrazovka jen s ukázkovými adresami; tatáž adresa s jiným tokenem 401 místo
uložené 200. Testy: **1416 PHP testů**, všechny prošly.

### Změny chování, o kterých mají oba vědět (2j)

- Obnova zapomenutého hesla odhlásí všechna zařízení — na ostatních se
  ukáže přihlášení.
- Po nasazení: `php artisan gallery:doctor` — řádek `MAIL_MAILER delivers
  e-mail`. Při WARN obnova hesla e-mailem nefunguje.

---

## 2k. Jedenácté kolo — zrušení účtu se opravdu provede (14. 9.)

Nastavení starého rozhraní (Účet → Vaše data) nabízelo zrušení účtu
s textem „Po čtrnácti dnech se smaže profil, deník i zprávy — nevratně".
Žádost se ale jen zapsala do předvoleb a **nic ji nikdy neprovedlo** — účet
i data žily dál.

- Nová úloha `gallery:zrus-ucty` (plánovač denně 4:40, v administraci
  „Zrušení účtů po lhůtě"; `--nanecisto` jen vypíše, koho by se týkala).
- Po lhůtě smaže, co člověk sám napsal nebo namluvil: zápisy deníku, zprávy
  (i přiložené soubory), hlasovky, jeho reakce; odhlásí všechna zařízení
  (klíče, sezení, otisky, odběr upozornění); odebere ho z galerie a profil
  anonymizuje (jméno, e-mail, heslo, fotka, dvoufázové ověření, kód zámku).
  Fotky zůstávají v galerii dvojice. Zapíše se do protokolu.
- Vlastník galerie účet zrušit nemůže (nejdřív předá vlastnictví) — úloha ho
  přeskočí i u starší žádosti.
- Text v nastavení teď říká přesně tohle.

Ověřeno: testy (před lhůtou nic, `--nanecisto` nic, po lhůtě jen data toho
člověka, soubory pryč, odvolaná žádost se neprovede, vlastník přeskočen,
úloha v plánovači); v administraci úloha „denně 4:40".

**„Odhlásit ostatní" ve starém rozhraní** rušilo jen sezení prohlížeče
a hlásilo „Všechna ostatní zařízení byla odhlášena" — telefon s aplikací
(přihlášený klíčem) zůstal přihlášený. Teď ruší i klíče, stejně jako
tlačítko v aplikaci, a zapíše se do protokolu.

Historie zabezpečení účtu (staré rozhraní → Účet) nově ukazuje i obnovu
hesla e-mailem, zvýrazněnou jako událost, kterou je dobré zkontrolovat.

| Commit | Obsah |
|---|---|
| `1a6a93a3` | `gallery:zrus-ucty` — zrušení účtu po lhůtě; vlastník galerie chráněn; obnova hesla v historii zabezpečení |
| `6e3a4f71` | „Odhlásit ostatní" ve starém rozhraní ruší i klíče aplikace |

Testy: **1421 PHP testů**, všechny prošly.

### Po nasazení (2k)

- `php artisan schedule:list` — mezi úlohami `gallery:zrus-ucty` (denně 4:40).
- `php artisan gallery:zrus-ucty --nanecisto` — vypíše, jestli nějaký účet
  na zrušení čeká (dnes by neměl žádný).

---

## 2l. Dvanácté kolo — Zprávy na telefonu a čas dvojice (22. 9.)

Porovnání, které serverové akce volá počítač a které telefon, ukázalo
největší díru: **telefon neměl zprávy**. Hovor dvojice šel na telefonu
otevřít jen přes Přehled → Všechny obrazovky jako náhled posledních 14
bublin — bez psaní, bez hlasovek k poslechu a bez fotek.

- **Telefon → Více → Zprávy a hlasovky**: celé vlákno po dnech, bubliny
  s textem, fotkou a přehrávačem hlasovky; psaní, nahrání hlasovky
  (mikrofon), poslání fotky; smazání vlastní zprávy (dvojím klepnutím);
  během odesílání bublina „odesílá se…"; vlákno se na otevřené obrazovce
  obnovuje každých 20 s a sjede na konec nad lištu pro psaní.
- Posílá se stejně jako z počítače (`/v1/chat`); hlasovka se napřed nahraje
  jako záznam a do hovoru jde odkaz na něj (`voice_note`), takže ji jde
  přehrát na obou zařízeních.
- **Počítač: „Smazat zprávu"** mazalo jen v místním seznamu a hlásilo
  „smazána u obou" — po obnovení se zpráva vrátila a u druhého nezmizela
  nikdy. Teď se ptá a maže na serveru; tlačítko je jen u vlastních zpráv
  (cizí server smazat nedovolí).
- **Čas v aplikaci byl UTC.** Obsah pro obrazovky formátoval okamžiky
  v UTC: zpráva odeslaná v 11:09 ukazovala 9:09, koš „dnes v 20:53" ve
  22:53, těsně po půlnoci patřilo „dnes" ke včerejšku. Nový
  `App\Support\Cas` převádí okamžiky (`created_at`, smazáno, synchronizace,
  spuštění pravidla…) do pásma dvojice (`APP_TIMEZONE`, výchozí
  Europe/Prague) — zprávy, koš, protokol, domácnost, pravidla, vztah,
  sdílení, finance, příběh, plánování a přehled dne. Časy zadané podle
  hodin (začátek akce, EXIF fotky) se neposouvají. Ukládá se dál v UTC.

Ověřeno v prohlížeči (telefon): otevření z menu Více, odeslání textu
(`POST /api/v1/chat 201`, „odesílá se…", pak ve vlákně), čas 11:09 = 11:09,
fotka ve vlákně (obrázek se načte), hlasovka s přehrávačem, smazání vlastní
zprávy dvojím klepnutím; v rámu 375 × 760 lišta pro psaní nad navigací
a poslední zpráva nad lištou. Počítač: cizí zpráva bez tlačítka Smazat,
dialog „Smazat zprávu?", `DELETE /api/v1/chat/… 200`. Nahrávání mikrofonem
v panelu prohlížeče nejde (mikrofon je zablokovaný) — pokrývá ho test
cesty `voice_note`.

Také opraven test, který by od 18. 9. 2026 sám padal (datum akce napevno
v minulosti).

| Commit | Obsah |
|---|---|
| `f4898d55` | Zprávy na telefonu; `/v1/chat` přijímá `voice_note`; mazání zprávy na počítači na serveru a jen u vlastních |
| `a0e1ebad` | `App\Support\Cas` — okamžiky v pásmu dvojice (`APP_TIMEZONE`) v obsahu pro obrazovky |
| `ae026f73` | Test připomínky s pevným „teď" |

Testy: **1428 PHP testů**, všechny prošly.

### Co zůstává (2l)

- Datum (bez času) u některých okamžiků ve starších částech obsahu (např.
  „přidáno 3. 9.") se počítá v UTC — liší se jen mezi půlnocí a 2:00.
- Odesílání zpráv z telefonu bez signálu se neukládá do fronty — ukáže
  chybu a text vrátí do pole.

---

## 2m. Třinácté kolo — cesty, vzkazy hostů a poctivé prázdné stavy (22. 9.)

**Cesty se zakládaly jen naoko.** Dialog „Nová cesta" na počítači ukládal
cestu jen do sdíleného stavu (`myTrips`) a sliboval, že se „hned objeví
v Travel inboxu". Server ji neznal: telefon ji neviděl a zapsat k ní útratu
nebo program nešlo — obojí potřebuje skutečnou cestu. Telefon navíc cestu
založit neuměl vůbec a bez cest ukazoval na hlavní záložce bílou plochu.

- Počítač: dialog zakládá cestu na serveru (`POST /v1/trips`) s datem od–do
  (místo věty „září 2026") a rozpočtem; rozpracovaná cesta z dřívějška se
  při uložení přestěhuje na server.
- Telefon: na záložce Cesty tlačítko **Nová cesta** (formulář název,
  destinace, od, do, rozpočet) a prázdný stav s vysvětlením.
- Telefon: **bod programu cesty** (dřív „zatím jen na počítači") — výběr dne
  z rozsahu cesty a nepovinný čas; zapíše se do programu u obou.
- Celkový rozpočet z dialogu se ukáže u cesty, dokud nemá limity po
  kategoriích (dřív se neukázal nikde).
- Telefon: **„Splněno" u bodu programu** se ukládá k bodu na serveru
  (`/cesty/program/{id}/hotovo`) — dřív jen v jednom telefonu, druhý ani
  počítač o tom nevěděli.

**Vzkazy hostů na telefonu** šly jen číst; hlasový vzkaz odkazoval „přehrát
ve Sdílených na počítači". Teď přehrávač a akce Skrýt/Zveřejnit, Přilepit
k fotce a Smazat (dvojím klepnutím).

**Prázdné stavy, které slibovaly nemožné:** „Rozpoznávání [tváří] běží
v telefonu/zařízení" — aplikace tváře nerozpoznává; „Kontrola [duplicit] běží
každou noc (ve 4:40)" — běží jednou týdně. Texty říkají pravdu.

Ověřeno v prohlížeči: telefon — založení cesty z formuláře (`POST
/api/v1/trips 201`, cesta v seznamu „9. – 11. října 2026 · odjezd za 17
dní"), bod programu na 2. den v 10:30 (`POST /api/cesty/{n}/program 201`,
v programu „Sobota 17. 10."), vzkaz hosta skrýt → zveřejnit → smazat;
počítač — dialog s daty, `POST /api/v1/trips 201`, u cesty „Rozpočet
12 000 Kč"; odškrtnutí bodu programu na telefonu (`POST
/api/cesty/program/{id}/hotovo 200`, v databázi `done`). Testovací cesty
a vzkaz z vývojové databáze uklizeny.

| Commit | Obsah |
|---|---|
| `4ba1b931` | Cesty na serveru z obou rozvržení (dialog s daty, telefon: nová cesta a bod programu), rozpočet cesty; vzkazy hostů na telefonu; poctivé prázdné stavy |
| `07fffb82` | „Splněno" u bodu programu cesty na serveru |
| `4c6e9e41` | Telefon: album přejmenovat a stáhnout jako ZIP; odznaky inboxů a den cesty v menu Více ze skutečných dat |

Testy: **1430 PHP testů**, všechny prošly.

---

## 2n. Čtrnácté kolo — čísla z ukázky, čas dvojice a výpis z banky (22. 9.)

Průchod všech obrazovek obou rozvržení (počítač 57 tras × záložky, telefon
59 položek menu Více × záložky) hledal jména a místa z ukázky, mrtvá
tlačítka a čísla, která nesedí. Ukázková data v kódu už nikde nezůstala
(co zbylo, je ukázka v **databázi** — viz 1), mrtvé tlačítko žádné.

**Odznaky a čísla z ukázky u dvojice:**
- Menu (panel na počítači, Více na telefonu): Koš „4", Sdílené „5",
  Plánování „3", Zprávy „2", Cesta právě nyní „den 5" — server teď počítá
  koš, platné odkazy, úkoly dnes a po termínu a den běžící cesty, i když
  knihovna ještě nemá fotky; zprávy odznak nemají (nepřečtené se nesledují).
- Hlavička stránky na počítači: Lidé „2 návrhy", Plánování „3 na dnes",
  Pravidla „4 aktivní", Úklid „16 nálezů", Zprávy „2 nové" z katalogu —
  teď ze skutečných dat, jinak nic. Panel už nekreslí „Vzpomínky 0".
- Telefon, úvodní dlaždice: „Do konce měsíce −1 825 Kč · zbývá
  v rozpočtu" zeleně — teď „utraceno nad příjmy" (varovně), „zbývá z příjmů"
  nebo „tento měsíc bez plateb"; počet v knihovně z celé knihovny.
- Tvary slov u čísel („3 položek", „31 nezařazená", „2 fotek z okolí",
  „1 hodin"…) na obou rozvrženích; nová pomůcka `tvar()`.

**Čas dvojice (Praha) i u zápisů.** Popisky úkolů „dnes/zítra/po termínu"
a hranice „Tento týden" se počítaly podle UTC (ve 23:30 UTC byl úkol na
dnešek „zítra"). Deník, rychlý zápis z telefonu, ruční a plánovaná platba,
vyrovnání a záznamy mechanismů dostávaly mezi půlnocí a druhou ranní
včerejší datum a deník odmítal dnešní datum jako budoucí. Všude
`Cas::dnes()`.

**Náhledy, které server nevydá.** Aktivita na úvodní obrazovce nesla
adresu náhledu trvale smazané fotky (404 v konzoli) a stejně by nesla
fotku z koše nebo trezoru; totéž chat. Týdenní přehled a světový itinerář
nepočítají fotky v trezoru (země ze skryté fotky prozrazovala polohu).

**Výpis z banky do Transakcí (nová funkce).** Transakce slibovaly „Import
z Revolutu ústí sem", ale import výpisu (starý bankovní modul) do knihy
plateb nikdy nedošel. Teď na kartě účtu v Přehledu financí **Nahrát výpis**
(CSV, XLS, XLSX): server výpis přečte, odduplikuje a nové pohyby zapíše na
účet jako nezařazené platby (záložky Nezařazené a Importované). Opakovaný
nebo překrývající se výpis nic nezdvojí, platby v jiné měně než účet se
vynechají (toast to řekne), převody mezi vlastními účty jdou mimo rozpočet,
poplatek se zapíše k platbě. `POST /api/finance/import`, 10× za minutu.

Ověřeno v prohlížeči: odznaky v panelu (Knihovna 4, Úklid 2, Sdílené 2,
ostatní bez čísla) i v menu Více; hlavičky stránek; dlaždice telefonu;
založení účtu, `Nahrát výpis` přes klienta API (2 platby v Nezařazených,
druhé nahrání „Nic nového", nečitelný soubor → věta o chybějícím záhlaví).
Testovací účet a bankovní řádky z vývojové databáze uklizeny.

| Commit | Obsah |
|---|---|
| `ed6a4a4a` | Odznaky menu Koš, Sdílené, Plánování a Cesta ze skutečných dat (i bez fotek); bez „Vzpomínky 0" |
| `17ad31e8` | Hlavičky stránek bez ukázkových čísel; úkoly „dnes" podle Prahy |
| `6deed2b1` | Úvodní dlaždice telefonu a souhrn knihovny: poctivá čísla a tvary |
| `1c18a7dc` | Náhledy jen u fotek, které server vydá; trezor mimo týdenní přehled; tvary slov |
| `c1a8cf7a` | Výpis z banky do Transakcí (nahrání u účtu, deduplikace, jiná měna, převody); české „upraveno před…" |
| `ae0e6fcf` | Zápisy s dnešním datem podle Prahy (deník, platby, vyrovnání, mechanismy) |

Testy: **1445 PHP testů**, všechny prošly.

## 2o. Patnácté kolo — co nešlo začít: dělba, rozhodnutí, lidé, promítání (22. 9.)

Průchod prázdných obrazovek (kolik textu obrazovka má) ukázal funkce, které
dvojice s prázdnými daty neměla **jak začít** — obrazovka byla jen pro
prohlížení toho, co vzniklo jinde (ukázka, staré rozhraní).

- **Domácí práce** (Domácnost → Dělba): nový `POST/DELETE
  /api/domacnost/prace` — název, jak často, minuty, kdo ji má (já, druhý,
  spolu). Počítač: „Přidat práci" a koš u řádku; telefon: „Přidat práci".
  Převodník stavu už nezaloží znovu práci, kterou někdo odebral.
- **Rozhodnutí a sliby na telefonu**: „Zapsat rozhodnutí" a „Zapsat slib"
  (dřív jen čtení, bez zápisu z počítače prázdná plocha).
- **Kdo je na fotce**: „+ Osoba" v detailu fotky na počítači i telefonu;
  osoba se podle jména najde, nebo založí, a objeví se v Lidech. Dřív šlo
  osobu označit jen ve starém rozhraní.
- **Promítání**: bez oblíbených se promítá dvanáct nejnovějších (dřív
  prázdno); telefon ukazuje skutečné fotky výběru a „Spustit promítání"
  listuje každých pět vteřin (Zastavit v horní liště).
- **Výpis z banky i z telefonu** (Finance → Nahrát výpis z banky) a známé
  obchody se zařadí samy podle dřívějších plateb (poslední zařazení
  rozhoduje; toast řekne, kolik se zařadilo a kolik zbývá).

**Chyby:**
- `GalerieObsahNavlec` dával `MOBIL` z odpovědi na akci do `GalerieData`,
  kde ho telefon nečte — co telefon ukazuje ze své kopie (dělba, cesty,
  alba), se po akci objevilo až po obnovení stránky.
- Album „Beskydy → Pustevny" (šipka v názvu) se bralo jako podalbum:
  „0 hlavních alb", ve stromu jako „Pustevny", v navigaci jako album
  v „Beskydy" a detail do něj bral fotky všech alb začínajících „Beskydy".
  Server teď posílá rodiče a hloubku.
- Prázdné záložky Lidé (Potvrzení/Návrhy/Skryté) a Tagy (Návrhy) bez
  vysvětlení; text u návrhů štítků netvrdí, že je aplikace tvoří.
- „S Makinka" v hlavičce chatu → „S Makinkou" (7. pád).

Ověřeno v prohlížeči na obou rozvrženích (testovací řádky vždy uklizeny):
dělba — přidání dialogem (2× týdně, ikona odpadu), „Mám hotovo" (záznam
a předání druhému na serveru), odebrání s potvrzením, přidání z telefonu
s okamžitým překreslením; rozhodnutí a slib z telefonu v databázi;
osoba na fotce z počítače i telefonu (vznik osoby, vazba, odebrání);
promítání 1 z 4 → 2 z 4 → Zastavit bez jediného `PATCH /api/state`;
výpis z banky — tlačítko u jednoho účtu, list účtů u dvou.

| Commit | Obsah |
|---|---|
| `0a2aadb5` | Telefon: výpis z banky se nahraje z Financí |
| `c3844a73` | Import výpisu: známé obchody se zařadí podle dřívějších plateb |
| `574b0eb0` | Odpověď na akci plní i kopii telefonu (MOBIL) |
| `e2f1f24c` | Domácí práce jde založit a odebrat (počítač i telefon) |
| `5b8ad4d6` | Telefon: rozhodnutí a sliby jdou zapsat, prázdné stavy |
| `07879ac2` | Promítání: výběr podle fotek, bez oblíbených nejnovější, na telefonu jde pustit |
| `4bdd1989` | Telefon: „S Makinkou" v hlavičce chatu |
| `f0e50c19` | Alba: šipka v názvu nedělá z alba podalbum |
| `24512ad2` | Lidé: osobu jde označit na fotce; prázdné záložky Lidé a Tagy |

Testy: **1456 PHP testů**, všechny prošly. Nic se nemigruje.

## 2p. Šestnácté kolo — co se tvářilo uložené: stav, oznámení, cesty (22. 9.)

Průchod akcí, které hlásily úspěch, ale na serveru nic nezměnily (nebo
změnily něco jiného), a obrazovek, kde u dvojice nešlo nic přidat.

- **Vynulovaná kopie nesmaže data**: `fam: null` ve stavu smazalo na serveru
  všechny kontakty z rodiny, `wishes: null` všechna přání (převodník dělal
  z `null` prázdný seznam). Opraveno ve všech převodnících se seznamy.
- **Domácnost**: lhůty a platby, kontakty z rodiny a věci v bytě jdou přidat
  na počítači i telefonu (dřív jen ukázka); datum lhůty bez času.
- **Oznámení ze serveru**: nepřečtená oznámení (úkol přidělený druhým,
  import financí…) jsou ve zvonku na počítači a na Domů v telefonu;
  „Přečteno", „Odložit na týden" a „Vše přečteno" jdou na server.
- **Travel inbox**: přidání z počítače i telefonu; Zařadit do cesty,
  Vytvořit místo, Archivovat a Smazat volají `/api/v1/calendar/inbox` (dřív
  jen obrazovka — po obnovení se položka vrátila; bez plánované cesty
  spadla stránka). Zařazené a archivované se počítají správně v Akčním
  inboxu, odznacích i zvonku (zvonek je navíc ukazoval podruhé jednotlivě).
- **Jízdenky**: „Přidat jízdenku" má dialog s názvem a trasou a jízdenka
  jde smazat (telefon dvojím klepnutím); „Do cesty" jen v ukázce. Druhá
  záložka travel inboxu se jmenovala „Zařazené", ale ukazovala jízdenky.
- **Místa**: „Byli jsme" jen přes `/api/mista/stav` — počítač k tomu psal
  místní kopii, která pak přebíjela databázi, chybu spolkl a „Zpět" vrátilo
  jen kopii; telefon zapisoval jen do sebe. Na telefonu jde přidat cíl.

**Chyby:**
- Načtení stránky bralo `/api/data` z třicetivteřinové paměti prohlížeče:
  co se přidalo těsně před obnovením stránky, po obnovení „zmizelo";
  načtení po uložení v administraci dostalo stav před uložením.
- Převodník cestovní schránky psal stav „filed", který zbytek aplikace
  (`inbox`/`assigned`/`archived`) nezná.
- Stará místní kopie seznamu jízdenek by smazanou jízdenku založila znovu.
- Prázdné stavy slibovaly přepis hlasovek, nahrávání PDF jízdenek, archiv
  jízdenek a položky „z e-mailu" — nic z toho aplikace nedělá.

Ověřeno v prohlížeči na obou rozvrženích (testovací řádky uklizeny):
travel inbox — přidání dialogem i z telefonu (s kontrolou https), Vytvořit
místo (místo v databázi, položka `assigned`), Archivovat, Smazat
s potvrzením, zvonek 5 → 4; jízdenky — přidání s trasou, smazání
(počítač s potvrzením, telefon dvojím klepnutím), po smazání se nevrátila;
místa — Byli jsme a zpět z počítače i telefonu bez místní kopie, nový cíl
z telefonu; oznámení přečtená na serveru.

| Commit | Obsah |
|---|---|
| `7e265fdf` | Stav: vynulovaná místní kopie seznamu na serveru nic nesmaže |
| `e594b0d5` | Domácnost: lhůty, kontakty z rodiny a věci v bytě jdou přidat |
| `f7a30874` | Oznámení ze serveru v galerii: zvonek na počítači, Domů na telefonu |
| `66e6d7be` | Načtení stránky bere obsah ze serveru, ne z třicetivteřinové paměti |
| `63f40679` | Travel inbox, jízdenky a „byli jsme" jdou doopravdy na server |

Testy: **1469 PHP testů**, všechny prošly. Nic se nemigruje.

## 2q. Sedmnácté kolo — tlačítka, která jen přepnula obrazovku (22. 9.)

Průchod počítače (s pomocí auditu všech tlačítek, jejichž hláška tvrdí
hotovou věc) a telefonu (hlášky nad klíči stavu, které se neukládají).

- **Vzpomínky se u dvojice neukazovaly vůbec**: generátor zapisuje druhy
  `anniversary`/`album`/`event`, obrazovka filtrovala podle `den`/`milnik`…;
  výchozí „aspoň 10 fotek" schovalo zbytek a „na dnes" bralo i včerejší.
  `gallery:memories` navíc nebyl v plánovači — teď denně 8:30 pražského času.
  Odložení na zítra/týden se vrátí samo; dlaždice jsou fotky vzpomínky.
- **Týdenní přehled**: „Na příští týden" posune úkol po termínu na pondělí
  (`POST /api/ukoly/{uuid}/pristi-tyden`); minulý týden se otevře na fotce.
- **Prohlížeč fotky**: čas pořízení byl napevno „06:42", „nahráno" ukazovalo
  den pořízení a fotka bez alba měla v poli Album cizí album.
- **Úkoly na telefonu** se odškrtávají na serveru (`PATCH /api/v1/todos`).
- **Milníky**: „Přidat milník" zakládalo nápad na dárek; nový
  `POST /api/milniky` (počítač i telefon).
- **Finance**: pravidla zařazování jsou tatáž, podle kterých zařazuje import
  výpisu (dřív text, že import galerie nemá); hledání v transakcích po slovech.
- **Audit tlačítek**: nedělní agenda, zavřít rozhodnutím, rozvaha, pravidlo
  z mentální zátěže, kolečko → kalendář, rozpočet příští cesty, fronta hostů
  (schválení/odmítnutí na serveru), kapitola ze vzpomínky, fotokniha v Tisku,
  album z návrhu na Dnes (dřív prázdné) a z hotového výběru.

**Co zůstává (2q):**
- Zakázky Tisku (`pJobs`) žijí ve sdíleném stavu dvojice, ne v tabulce
  `photo_books` ze starého rozhraní. Nic se neztrácí (stav se ukládá a vidí
  ho oba), ale telefon a staré rozhraní vidí jen `photo_books`. Sjednotit
  chce migraci (stav, počet kusů, nastavení editoru u `photo_books`).
- Nastavení připomínek vzpomínek (čas, komu, tichý režim) je předvolba
  zařízení, kterou server neukládá; oznámení chodí v 8:30 všem členům.
- ~~Komentáře k fotce, archiv alba, balení na cestu~~ — hotovo (2r). Chytrá alba:
  ukládají se ve sdíleném stavu, ne v tabulkách, které k nim server má.

| Commit | Obsah |
|---|---|
| `8a8ba423` | Týdenní přehled a prohlížeč fotky: posun úkolu a skutečné údaje o fotce |
| `9a5e1a6e` | Finance: pravidla zařazování podle plateb, hledání po slovech |
| `28f9032e` | Telefon: odškrtnutý úkol se uzavře na serveru |
| `b59a2e86` | Milníky, odložené vzpomínky a album z návrhu doopravdy |
| `3b1dc8ed` | Tlačítka, která hlásila hotovo a jen přepnula obrazovku (audit) |
| `320056cc` | Vzpomínky dvojice se konečně ukážou a generují se každé ráno |

Testy: **1480 PHP testů**, všechny prošly. Nic se nemigruje.

## 2r. Osmnácté kolo — sdílený stav do tabulek, audit telefonu (22. 9.)

Věci, které žily jen ve sdíleném stavu dvojice (nebo jen v telefonu), přitom
server k nim má tabulku — a audit telefonu stejnou metodou jako minule počítač.

- **Komentáře u fotek**: z `lbCom` ve stavu do `media_comments`
  (`/api/v1/media/{uuid}/comments`); smazat jde jen vlastní, čas v pásmu
  dvojice. Co už napsané bylo, převede migrace.
- **Archiv alb**: `albums.archived_at`, `POST /api/alba/{uuid}/archivovat`;
  archivované album zmizí z Alb na obou zařízeních, dole v Albech (a v
  telefonu v „Archiv a záchrana") jde vrátit. Dřívější `albArchived` migrace
  archivuje doopravdy.
- **Balení na cestu**: zaškrtnutí jde do `trip_packing_items` (dřív `pack`
  podle pořadí, který přebil i to, co odškrtl druhý).
- **Spíž**: množství, nové i odebrané položky do `house_pantry_items`
  (`POST /api/domacnost/spiz`) na počítači i telefonu.
- **Fronta zápisů** slučuje `xRows` po seznamech — dva rychlé zápisy různých
  seznamů přepsaly ten první.
- **Telefon**: rychlý zápis (nákup, nápad, odkaz), akce do kalendáře k dni
  (`POST /api/kalendar/udalost`), zápis k dni s datem dne, nástěnka úkolů
  (Hotovo na server), kolečko (menu, kalendář, kývnutí pod jménem), uložená
  hledání a hranice rozvahy se ukládají; ukázkové akce v prázdném kalendáři
  a „Plitvice" na záložním Domů jsou pryč.

Ověřeno v prohlížeči (testovací řádky uklizeny): komentář přidat/smazat,
archivace alba a návrat, akce do kalendáře k 25. 9. (18.30 → 18:30), zápis
k 25. 8., rychlý zápis nákupu i nápadu v databázi, přesun úkolu do Hotovo
(`completed`).

| Commit | Obsah |
|---|---|
| `d293b06b` | Komentáře u fotek v tabulce, ne ve sdíleném stavu |
| `4f5c48fa` | Archiv alb doopravdy: na serveru, na obou zařízeních, s návratem |
| `15f889e4` | Balení na cestu se odškrtává na serveru |
| `c9aaa336` | Telefon: co hlásilo uloženo, se doopravdy uloží (audit) |
| `380cc22d` | Test pravidel: rychlý zápis posílá nápady jedním zápisem se seznamy |

Testy: **1488 PHP testů**, všechny prošly. **Dvě migrace** (viz níže).

## 2an. Čtyřicáté první kolo — mazání po společném schválení, stav, obsah obrazovek (25. 9.)

Rozhodnutí dvojice ke `can_delete` z minulého kola: **„Mazat fotky mohou
jen po společném schválení pokud si po vzájemném schválení nenastaví
jinak."** K tomu audit poskytovatelů obsahu (`app/Services/Obsah`),
převodníků stavu (`app/Services/Provoz`), řadičů galerie a zbylých míst
s „dneškem" v UTC — čtyři `task-plan` naráz, opravy v patnácti dávkách
na oddělených souborech, každý nález nejdřív potvrdil test, který bez
opravy spadl.

### Mazání fotek po společném schválení

* **Výchozí pro všechny galerie, i stávající:** „Do koše" od jednoho
  z dvojice fotku jen **navrhne** — zůstane v knihovně s přesýpacími
  hodinami. Do koše (30 dní na vrácení, pak noční úklid jako dřív) ji
  přesune až **souhlas druhého**: „Smazat" v prohlížeči fotky nebo
  v koši (oddíl „Čeká na schválení"), případně jeho vlastní „Do koše" na
  tutéž fotku. „Ponechat" návrh odmítne, navrhující ho může stáhnout.
  Vlastní návrh schválit nejde. Druhý dostane jedno oznámení na dávku
  (bez jmen souborů, nic za trezor).
* **„Každý maže sám"** jen po vzájemném schválení: jeden navrhne
  (Nastavení → zámek → Mazání fotek), druhý potvrdí **kódem zámku, nebo
  heslem do galerie**, nebo návrh odmítne. Návrat ke společnému
  schvalování smí kdokoli sám — hned.
* Kdo je v galerii zatím sám (partner nepřijal pozvánku), maže rovnou.
  Partner s odebraným přístupem se **počítá** — odebráním přístupu nejde
  dohodu obejít. Host nesmí navrhovat, schvalovat ani mazat nic.
* Týká se všech cest do koše: knihovna, prohlížeč fotky, hromadný výběr,
  porovnání, série, duplicity, karanténa úklidu (i přes zápis stavu),
  staré webové rozhraní a API v1. Trvale smazat z koše smí dál jen
  vlastník nebo správce (`MazaniFotek::smiTrvaleMazat`, jedno pravidlo
  pro koš galerie, staré rozhraní i panel rizik).
* `can_delete` v členství už oprávnění není; `MediaPolicy::delete/restore`
  pustí jen dvojici prostoru.

### Zabezpečení

* **Úlohy celého serveru přes stav:** kterýkoli člen dvojice kterékoli
  galerie pozastavil nebo spustil zálohy, úklid koše i synchronizaci
  banky všem (`admJobPause`, `admJobs`). Teď jen provozovatel.
* **Trvalé smazání mimo koš:** `DELETE /media/{uuid}/purge` smazal
  nadobro i fotku z knihovny; `trash/{uuid}/purge` a `trash/empty`
  hlídaly `users.role` (owner má každý účet). „Vysypat koš" z panelu rizik
  spustil i běžný člen a smazal i trezor při zamčeném trezoru.
* **„Necháváme obě"** u duplicit kopie ve skutečnosti vyhodilo.
* Host cizí galerie vyhazoval její fotky přes hromadnou akci starého API;
  fotka z trezoru šla do koše se zamčeným trezorem.
* **Tajné dárky** zapsané z prototypu viděl ten, pro koho byly
  (`visibility` zůstalo „shared") — migrace je dodatečně skryje.
* **Soukromé věci partnera:** rozpočet (fondy, odhady, vyrovnání, cíl na
  Dnes), akce kalendáře v přehledu událostí, zápisy deníku v rekonstrukci
  dne a příběhu, zprávy z konverzací, kde člověk není, fotky vzpomínek
  přesunuté do trezoru. Platbu šlo přiřadit k cizí soukromé cestě.
* **Host jako partner** na desítce obrazovek (obálky, Klid, Mluvení,
  rozhodování, dárky, e-mail hosta v LOCKMAIL, seznam s přístupem
  k trezoru, účastník akcí „spolu" a jejich připomínky).
* Trezor v počtech (osoby, zdraví dat, duplicity jen z trezoru); video
  a archivy odcházely `Cache-Control: public`, originál z trezoru bez
  `no-store`; data obrazovek s trezorem se držela 30 s v mezipaměti
  prohlížeče i po zamčení. Protokol správy ukazoval záznamy z jiných galerií.

### Co padalo nebo počítalo špatně

* **Na MySQL celá skupina `system` prázdná:** dotaz na `trips.deleted_at`,
  sloupec, který tabulka nemá (SQLite to spolkne) — koš, trezor, zámek,
  oznámení i schránka. Na Dnes šifrovaný text zpráv („eyJpdiI6…").
* **Datum pořízení před rokem 1970** (sken z roku 1965) shodilo na MySQL
  celý zápis stavu — `taken_at` byl `timestamp`.
* **Stará kopie stavu** mazala a vracela: chybějící den jídelníčku smazal
  večeři (`ckMenu: {}` celý týden), smazané kapitoly, milníky, dárky,
  pravidla a úkoly se vracely, přepínače a fondy přepsaly novější změny
  druhého, dluh zápisů přehrával nejstarší popisky a srdíčka jednoho
  dával druhému. Připomínka partnerovi tvořila řádky bez stropu.
* Délky a rozsahy, které MySQL odmítne (poznámky, částky, minuty, id delší
  než 64 znaků — druhý zápis pak 500 dokola, 2026-13-45).
* **Posun termínu cesty** nechal dny cesty na starých datech („Teď",
  deník, rezervace, jídla); souběžné vklady do fondu se ztrácely, dvojí
  „Opakovat" založilo dva předpisy; `FinanceService::balances` počítal
  i rozepsané záznamy.
* **Pražský den** na dalších ~30 místech: poznámka dne, týdenní přehled
  v pondělí ráno, volné soboty (v neděli nabízely včerejšek), hotové úkoly
  týdne, @dnes, „letos" na Nový rok, den cyklu, otevření sdílené
  vzpomínky a připomínky o hodinu až dvě pozdě, časy ve správě.

### Po nasazení

* **Tři migrace:** `2026_09_27_100000_spolecne_mazani_fotek` (sloupce
  režimu a návrhů), `2026_09_27_110000_skryt_chystane_darky` (jen úprava
  dat, bez návratu), `2026_09_27_120000_taken_at_datetime` — na MySQL
  `ALTER TABLE media_items` kopíruje celou tabulku a po dobu běhu blokuje
  zápisy: **pustit v klidné chvíli**. Hodnoty zůstanou (pásmo sezení se
  nemění), návrat odmítne, když by data zničil.
* Ve dvojici „Do koše" od nasazení jen navrhuje — **oba o tom mají
  vědět**. Co už v koši leží, dožije se svého termínu jako dřív.
* Staré webové rozhraní (zmrazené) čekající návrh neukazuje: po smazání
  přejde na časovou osu, hromadná lišta napíše „Hotovo: N" i za návrhy.
* Termín cesty mění už jen hlavní akce cesty v kalendáři (posun rezervace
  vlaku cestu neposune). Účet jen pro čtení přes stav nezapíše tabulky.
* Protokol správy u účtů ve více galeriích nezobrazí záznamy bez prostoru
  starší než 24. 9.

### Zbývá (vědomě neřešené)

* Vlastník může partnera přeřadit na hosta a pak mazat sám — zásah je
  v protokolu, ale dohodu obchází.
* Součty přes účty v různých měnách (`FinanceRozbory::zustatek`,
  průměrná denní útrata) — potřebuje rozhodnutí o hlavní měně.
* `LIBSTATS` prochází celou knihovnu; opakovaný zápis po ztracené
  odpovědi může zdvojit nové přání nebo úkol Klidu (bez klientského id).
* Další `timestamp` sloupce s daty od lidí (`happened_at`, `recorded_at`,
  plány `started_at/ends_at`); připomínky v `PlanovaniVeStavu` a výdaj
  z deníku cesty (`TripPlanController::addJournalEntry`) ještě v UTC.
* `Vary` přepisuje middleware Inertie; `TrashController` bere první
  prostor bez řazení.
* Z předchozích kol: čekající karetní platby Revolutu, `r3` panelu rizik,
  souběh zamčení trezoru, `SpaceContext` ve statické proměnné.

| Commit | Co |
|---|---|
| `6a372c63` | Platba jen k cestě, kterou smí upravit; zůstatek jen ze zapsaných |
| `1d5ef726` | Mazání po společném schválení — jádro (`MazaniFotek`, migrace) |
| `bb2d5982` | Staré rozhraní maže jen přes dohodu, trvale jen z koše |
| `3472747d` | Úklid přes stav maže jen po dohodě, „necháváme obě" |
| `29e88cf5` | API galerie a data obrazovek pro schvalování |
| `378d2c44` | Úlohy serveru jen provozovatel, vysypat koš jen správce |
| `7c3375af` | Datum pořízení před 1970, dluh zápisů, fondy, jen pro čtení |
| `0c344798`, `1ef94637` | Telefon — schvalování, sloučení, přepínače |
| `f9b85477`, `ec302142` | Převodníky stavu — MySQL, hosté, dárky, jídelníček, stará kopie |
| `176fb9df` | Počítač — schvalování, jídelníček, přepínače, fondy |
| `2a36b48f` | Pražský den ve starém API a službách |
| `8b2d0263` | Obsah obrazovek — soukromé rozpočty, deník, chat, hosté |
| `35805294` | Skupina `system` na MySQL, šifrovaný chat, trezor v počtech |
| `43690604` | Posun cesty posune i dny, souběh vkladů a pravidelných plateb |
| `db08d0e8` | Testy — pražský den i v noci |

Testy: **2435 PHP testů** (280 nových), všechny prošly — i v noci na
Silvestra a v letní noci přes `TESTY_CAS`. **Tři migrace.**

## 2am. Čtyřicáté kolo — staré API: nahrávání, cesty, peníze, úložiště (25. 9.)

Audit souborů, kterých se od 13. 9. nedotkla žádná oprava (319 souborů,
hlavně starší API `v1`, které na produkci pořád běží): čtyři `task-plan`
naráz — nahrávání a média, cesty a plánování, finance a banka, integrace
a úložiště — 56 nálezů. Opravy v osmi dávkách na oddělených souborech
plus jedna na zbytky, které dávky nahlásily; každý nález nejdřív potvrdil
test, který bez opravy spadl.

Společný kořen většiny nálezů: řadiče braly „kterékoli členství" nebo
„každého člena prostoru" — a tím i hosta. Nový
`PristupDoGalerie::prostoryDvojice()` / `idProstoruDvojice()` vrací jen
prostory, které účet vlastní nebo v nich má roli dvojice; „partner" je
vždy `dvojice($prostor)`.

### Zabezpečení

* **Hlasovka jako webová stránka.** Typ souboru se ukládal podle
  prohlížeče a přehrávání ho vracelo zpět: partner mohl podstrčit HTML se
  skriptem, který se druhému spustil na doméně galerie (CSP dovoluje
  `unsafe-inline`). Teď typ podle obsahu z povolených zvukových, přehrání
  s `sandbox`.
* **Server jako průzkumník vnitřní sítě.** Vlastní úložiště WebDAV bralo
  jakoukoli adresu `https://` a šlo za přesměrováním — kdokoli
  zaregistrovaný tak přes server zkoušel `127.0.0.1`, metadata cloudu
  `169.254.169.254` i vnitřní síť (hláška řekla, co odpovědělo) a zálohy
  fotek pak mířily tam. `App\Support\VerejnaAdresa` (sdílená s importem
  receptů), bez přesměrování, s časovým limitem a pevnou IP.
* **Kurzy měn přepsal kdokoli.** Tabulka je jedna pro celou instalaci
  a zápis hlídal `isAdmin()` (`users.role`) — kurz 0,0001 by „vyrovnal"
  rozpočty všech cest. Teď jen provozovatel.
* **Záznamy cizí galerie:** PATCH deníku cesty a itineráře vrátil celý
  řádek (název, příběh, GPS) podle id; úlohy složek na Disku poslaly
  Googlu token Dropboxu; pozvánka do galerie (`can:admin` =
  `users.role`) dovolila účtu bez galerie zakládat účty i při zavřené
  registraci.
* **Nahrávání po částech bez mezí** — kvóta jen podle ohlášené velikosti,
  počet ani velikost částí neomezené: disk šel zaplnit. Dvojí „dokončit"
  založilo dvě média. Kontrola duplicity prozradila název a uuid fotky
  z trezoru.
* **Trezor unikal** v lidech a štítcích (počty, detail osoby, obálka),
  stackách, médiích/návrzích/obálce cesty, deníku a itineráři, sdílených
  chvílích, milnících, večeru vzpomínek (fotka přesunutá do trezoru po
  naplánování skončila ve sdíleném albu), režimu akce alba, nástěnce
  výběru, receptech a recenzích míst.
* **Host jako partner:** třetina společných výdajů a výdajů cesty
  (300 = 3 × 100, návrh vyrovnání chtěl peníze po hostovi), editor
  automatických alb, účast a připomínky akcí (ICS, vaření, rezervace,
  randíčka, výročí), úkol přidělený hostovi, partner v cyklu.
* Synchronizace banky vracela id žádosti a souhlasu GoCardless a text
  chyby poskytovatele; nepotvrzené připojení se jí aktivovalo. Soukromou
  cestu v Rozpočtu četl i druhý z dvojice. Klíč k databázi filmů mohl
  skončit v protokolu.

### Co padalo nebo počítalo špatně

* **Peníze:** pravidelná platba se po posunutí dne založila znovu (nájem
  dvakrát), souběžné přehledy ji zdvojily; výdaj ze společného účtu
  dělal dluh mezi partnery; zahrnutý poplatek se v galerii odečetl
  podruhé, poplatek v cizí měně šel z účtu v jiné měně; převod 100 → 1000
  vyráběl peníze z ničeho. **Import Revolutu** zdvojil řádky překrývajícího
  se výpisu (otisk řádku bral čas z hodin serveru) a zamítnuté platby
  počítal do útrat i do cest.
* **Na MySQL (SQLite délku ani rozsah nehlídá):** přípona souboru
  nad 20 znaků, částky a vypočtený kurz bez horní meze, rozpočet cesty,
  nadpis akce s předponou („Vaření · …"), hodnoty ze zprávy pomocníka,
  datum úpravy společného výdaje, dlouhé jméno souboru z Disku (zablokovalo
  všechny další změny připojení).
* **Čas:** připomínky rezervací o 1–2 h později (UTC), pohled „Teď"
  cesty, „tento den", deník z 0:30 odmítnutý, check-in do včerejška,
  Rozpočet 1. den měsíce v noci na minulém měsíci, prošlé doklady; čas
  změny souboru se ukládal v UTC místo pražských hodin; `date_to` bez
  času uřízl poslední den.
* Uložení cesty se stejnými daty přesunulo všechny navázané akce na první
  den. Konec cesty před začátkem, cesta na tisíc let (řádek na každý den),
  období Rozpočtu `od=abc` (500) nebo 0001–9999 (miliony řádků).
* Dvojí klik: večer vzpomínek, převod akce na cestu, plán z přání
  a ankety, schválení nahrávky hosta — vše dvakrát.

### Po nasazení

* **Bez migrace.**
* Nahrávání, které klient po výpadku dokončí znovu, dostane totéž médium
  (dřív 404); relace, která se právě skládá nebo selhala, vrací 409.
* Řádky Revolutu naimportované dřív s datem bez času mají v otisku čas
  z hodin serveru — překrývající se výpis je zdvojí ještě jednou.
* Oprávnění a účasti, které dostali hosté dřív (editor alb, účastník
  akcí), zůstávají v databázi — nejde je odlišit od záměrně udělených.
  Výročí je odebere při dalším uložení, příprava cesty smaže čekající
  automatické připomínky hostů.
* Staré výdaje rozdělené i na hosta se v bilanci přepočtou na rovný díl
  dvojice; sloupec `split` zůstává, jak byl. Výdaj, který **zaplatil**
  někdo mimo dvojici (host, účet s odebraným přístupem), vyrovnání mezi
  partnery vynechá celý — tak to bylo i dřív, jen „dvojice" je teď užší.
* `can_delete` u členství (`MediaPolicy`) se dál nevynucuje — výchozí
  hodnota je 0 a žádná obrazovka ji nenastavuje; oprava by vzala mazání
  každému pozvanému partnerovi. Rozhodnutí o výchozí hodnotě.

### Zbývá (vědomě neřešené)

* Rozpracované načtení čekajících karetních plateb (`pending`) Revolutu
  se do útrat počítá dál (blokace zůstatek opravdu snižuje).
* `walletBalances` filtruje podle stavu platby, `balances` ne — u konceptů
  se mohou lišit. Přiřazení cesty u platby bere kteroukoli cestu prostoru.
* Relace nahrávání, kterou uprostřed skládání zabije pád procesu (paměť,
  časový limit), zůstane ve stavu `assembling` — klient dostane výzvu
  nahrát znovu (nová relace), části uklidí `gallery:clean-temp`. Tak to
  bylo i dřív.
* Posun cesty neposouvá řádky `trip_days`; poskytovatelé obsahu galerie
  (`app/Services/Obsah/*`) mohou u nadcházejících plateb ještě brát „dnes"
  v UTC.
* Z předchozího kola: `r3` panelu rizik, souběh zamčení trezoru,
  `SpaceContext` ve statické proměnné, staré rozhraní podle `users.role`.

| Commit | Co |
|---|---|
| `e6b08a8c` | Prostory, kde účet patří do dvojice |
| `e4826504` | Společné výdaje jen mezi dvojicí, Revolut, bankovní připojení |
| `b3716fe7` | Nahrávání po částech, hlasovky, duplicity a trezor |
| `02260c90` | WebDAV do vnitřní sítě, disková úloha s cizím tokenem, pozvánky |
| `94376c5b` | Připomínky hostům, trezor v nástěnkách a receptech, pomocník |
| `1d11ce8a` | Vzpomínky bez trezoru, host není partner, pražský den, dvojí klik |
| `d926ed40` | Staré API — lidé a štítky bez trezoru, stacky, meze |
| `771b7029` | Cesty — kurzy měn, cizí záznamy, trezor, hosté, termíny |
| `e93ab4ce`, `8758397d` | Rozpočet — pravidelné platby, společný účet, poplatky |
| `82c550c4` | Dodělky — prostory dvojice, trezor při čtení, výdaje cest |
| `be05ed81` | Po revizi: zrušení nahrávání uprostřed skládání, alias `spravce` |
| `d91a09b2` | Testy Rozpočtu podle pražského dne i na přelomu roku |

Testy: **2155 PHP testů** (184 nových), všechny prošly — i v noci na Silvestra
a v letní noci přes `TESTY_CAS`. **Bez migrace.**

## 2al. Třicáté deváté kolo — fakturace, provozovatel, trezor, média (25. 9.)

Audit souborů, kterých se dosud nedotkla žádná oprava (podle `git log`):
16 řadičů API galerie, webové řadiče a middleware, OAuth/webhooky/
fakturace/úlohy a zpracování médií (čtyři `task-plan` naráz). Opravy
v šesti dávkách na oddělených souborech (`task-deep`, `task-build`);
každý nález nejdřív potvrdil test, který bez opravy spadl.

### Zabezpečení

* **Placený tarif zdarma.** `PUT /api/v1/billing/plan` a `/modules/{code}`
  pouštěly každého s `users.role = owner` — a tu dostane každý, kdo se
  zaregistruje; přes `gallery_space_id` i v cizím prostoru. Teď role
  v prostoru, placené bez platby jen provozovatel; totéž zkušební
  období a zahájení nákupu.
* **Zaplacené období nekončilo.** O platnosti rozhoduje `ends_at`, platba
  psala jen `current_period_ends_at` (a ten kvůli `$fillable` ani
  neukládala): jedna měsíční platba = navždy, upomínky mlčely, zápočet
  při přechodu na dražší tarif nikdy nenastal. Souběžné dvojí potvrzení
  platby prošlo dvakrát.
* **Převzetí instalace přes adresu provozovatele.** Provozovatel se pozná
  jen podle e-mailu; změna adresy v profilu, registrace i pozvánky
  (odkaz dostane ten, kdo zve) vzaly jakoukoli adresu. Když adresu
  provozovatele nedržel žádný účet, vzal si ji kdokoli — i s `/admin`.
  Teď `Provozovatel::pravidlo()` na všech čtyřech místech.
* **Trezor patřil prohlížeči, ne člověku.** Kdo se přihlásil po partnerovi
  (i z jiné dvojice) v témže prohlížeči, měl trezor otevřený až 15 minut.
  `App\Support\Trezor` si pamatuje, kdo odemkl; přihlášení ho zapomene.
* **Disk hosta dostával originály dvojice** (výběr připojení bral každého
  člena, i hosta) a „Odpojit Google Disk" odpojil Dropbox — a u Googlu
  neodvolal nic (posílal celý JSON místo tokenu).
* **Trezor unikal:** počet a obálka alba, odznak koše, vzkazy hostů,
  „Vysypat koš" z panelu rizik (mazal i trezor), vzpomínky (výročí cesty,
  místa), nástěnka výběru alba, fotokniha (ZIP s `001_pas.jpg`), a ve
  starém rozhraní archiv, obálky alb (celý řádek i s GPS), duplicity,
  statistiky. Soubory z trezoru `no-store`; `response()->file()` dělalo
  ze `private` `public` (originály, videa, nahrávky).
* Oprávnění k albu ve starém rozhraní bralo `users.role` — host cizí
  galerie tam alba přejmenoval i přesouval; oblíbené počítaly hosty do
  dvojice; webhook Disku bez tokenu prošel; `?error=` z Googlu se ukázal
  doslova; chybové hlášky nesly text výjimky.

### Co padalo nebo počítalo špatně

* **Na MySQL (SQLite délku ani rozsah nehlídá):** sledovací číslo tisku,
  město nového cíle, nadpis deníku z pravidla (a „Spustit teď" vrátil
  celé SQLSTATE), EXIF s ISO 102400 nebo hodnocením −1 — to shodilo celý
  zápis metadat fotky.
* **Čas videí v UTC** (video z 0:30 leželo na předchozím dni) a náhledová
  úloha přepisovala datum z EXIF; rozměry fotek na výšku neotočené.
* Obrázek 30000×30000 zabil pracovníka fronty při každém pokusu; otočení
  RAW padalo na 500; webová kopie videa často nevznikla; `--force` náhledů
  smazal, co neměl z čeho obnovit; automatické série viděly jen nejstarších
  2000 fotek; `reconcile-dates --limit` chodil dokola.
* Pravidlo po zrušeném účtu autora padalo; ruční spuštění nechávalo
  `{title}`; ruční úloha přeskočená kvůli překryvu hlásila HOTOVO;
  počítadlo špatných hesel nebylo atomické; kvóta Disku se řadila při
  každém otevření galerie; první stažení z banky, které selhalo, hlásilo
  nepovedené připojení.
* Faktura 1. 1. v 0:30 měla loňské číslo; přehled a schránka starého
  rozhraní počítaly den v UTC.

### Po nasazení

* **Migrace `invoices_payment_id_unique`** — unikátní index; při existujících
  duplicitách se přeskočí (zapíše varování), faktury nemaže.
* **Ověřit:** `SELECT email FROM users WHERE LOWER(email) IN (…adresy
  provozovatele…)`. Pokud adresu provozovatele nedrží žádný účet, díra
  byla otevřená — prohlédnout protokol `profile.email_changed`
  a `auth.registered` a účet provozovatele založit.
* Rozběhnutá odemčení trezoru po nasazení platit přestanou (chybí jim, kdo
  odemkl) — odemknout znovu.
* Předplatná zaplacená před nasazením mají `ends_at = null` a platí dál
  navždy; konec období se nikde neuložil, takže ho doplnit jde jen ručně.
  Měsíční tržba u ročních zákazníků (matice provozovatele) teď klesne na
  dvanáctinu — dřív se počítali jako měsíční.
* Dřívější „odpojení" Google Disku přístup u Googlu neodvolalo; staré
  souhlasy jde zrušit jen v účtu Google.
* Videa s časem v UTC z doby před opravou se sama neopraví.

### Zbývá (vědomě neřešené)

* Panel rizik: „Ověřit obnovu" (`r3`) hlásí „Obnova ověřena — zkušební
  stažení proběhlo" a nedělá nic (oblast záloh).
* Náhledový požadavek, který přečetl sezení před zamčením trezoru, ho po
  zamčení může zapsat zpět (souběh; řešení: stav trezoru v cache).
* `SpaceContext` drží seznam prostorů ve statické proměnné bez obnovy
  — u dlouho běžících procesů může zastarat.
* Staré rozhraní ukazuje tlačítka nákupu podle `users.role`; hláška
  `warning` se v něm nezobrazuje. Live Photo se nepárují.
  `GenerateImageVariantsJob` bere rozměry neotočené; čas z prohlížeče
  (`lastModified`) se ukládá jako okamžik v UTC.
* Výběr Disku nevylučuje neaktivní účty dvojice (rozhodnutí o zálohách).

| Commit | Co |
|---|---|
| `cfe8de97`, `dd31c15d` | Počet a obálka alba, odznak koše bez trezoru |
| `5163d719`, `e644816a` | Disk hosta, poskytovatel, kvóta, webhook, odvolání u Googlu |
| `69216a0d` | Placený tarif si zákazník nepřidělí, období končí, jedna faktura |
| `774fdb5d` | Adresa provozovatele, trezor patří tomu, kdo odemkl |
| `5a03f266` | Vzkazy hostů, vysypání koše, pády na MySQL, pravidla, úlohy |
| `6dabb2e8`, `97dd18b2` | Staré rozhraní: archiv, obálky, duplicity, alba hostů, statistiky |
| `bd06ae80` | Zpracování médií — vzpomínky, čas videí, EXIF, náhledy |
| `11b0262f` | Fotokniha bez trezoru a koše |
| `c44e4f38` | Testy: příprava dat podle pražského dne |

Testy: **1971 PHP testů**, všechny prošly — i v noci na Silvestra
a v letní noci přes `TESTY_CAS`. **Jedna migrace.**

## 2ak. Třicáté osmé kolo — zámek, noční úlohy, peníze, alba (25. 9.)

Audit oblastí, které dosud neprošly: plánované příkazy, řadiče galerie pro
alba/sdílení/koš a pro peníze/plánování (tři `task-plan` naráz), opravy po
dávkách (`task-deep`, `task-build`). Každý nález nejdřív potvrdil test, který
bez opravy spadl.

### Zabezpečení

* **Otisk odemkl zámek bez kódu.** Bez klíče v zařízení ťuknutí na otisk
  klíč rovnou zaregistrovalo a aplikaci odemklo — stačilo ověření samotného
  zařízení (Windows Hello na společném počítači, PIN telefonu, který zná
  partner), i když server pokusy o kód blokoval. Teď se otisk zapíná
  s příštím správným kódem, volby registrace chtějí kód (kdo ho nemá, heslo)
  se společným počítadlem pokusů (`PotvrzeniZamkem`) a odemyká jen token od
  serveru. Tím je vyřízená i položka „registrace otisku chce jen přihlášení".
* **Hledání duplicit míchalo dvojice.** Týdenní běh dal tutéž fotku dvou
  dvojic do jednoho nálezu; první viděla cizí název a místo a „Sloučit"
  poslalo cizí fotku do koše (za 30 dní smazaná). Teď po prostorech a bez
  trezoru; migrace opraví staré nálezy (viz „Po nasazení").
* Vzpomínky braly fotky z trezoru a koše; výzva „Zároveň", vzpomínky
  a výročí chodily i hostům; „druhý z dvojice" (připomínka, domácí práce,
  úkol, vyrovnání, účastníci akce) mohl být host.
* Vzkaz hosta šel přivázat k jakékoli fotce prostoru (i z trezoru) a přepsat
  jí popisek. Sdílený odkaz na smazané album dál vydával fotky.
* Fotka z trezoru v koši se ukazovala se zamčeným trezorem a „Vyprázdnit
  koš" ji smazal nadobro, i když na obrazovce nebyla.

### Co padalo nebo počítalo špatně

* **`gallery:memories` padal na MySQL každé ráno** (`strftime` jen ze
  SQLite) — vzpomínky nedostal nikdo. Statický test hlídá `strftime` mimo
  větev podle ovladače.
* **„Opakovat" u starší platby dopsal zmeškané měsíce** (nájem z června
  v září = tři výdaje navíc). **Výdaj za dárek padal na MySQL na 500** —
  klíč o 40 znacích do `char(36)`; totéž hlídá i starý zápis platby.
* Rozdělení platby ztrácelo haléř (10,03 = 5,01 + 5,01); řádky výpisu, které
  modul banky už znal, do knihy nedošly; vyrovnání sama se sebou prošlo;
  posun bodu programu cesty přelil konec přes půlnoc.
* Okno výzvy „Zároveň" v UTC (v létě do 23 h); večerní souhrn vynechal
  první dvě hodiny pražského dne; pevný LIMIT nechával štítky, výročí a dárky
  za hranicí navždy; úklid koše přeskakoval každou druhou dávku.
* Přesun staršího alba pod novější rozbil strom (chyběl předek, pak smyčka
  a 500). ZIP alba vynechal fotky zařazené jen přes `primary_album_id`,
  stejná jména se přepsala, zůstával prázdný dočasný soubor. Hlasovky hostů
  z prohlížeče se odmítaly (finfo hlásí `video/webm`).

### Po nasazení

* **Migrace `oddelit_duplicity_dvojic`** — jen data: u nálezů s cizí fotkou
  vrátí z koše fotky vyhozené tím sloučením (±5 minut od `resolved_at`),
  při cizím vítězi i vlastní kopie a nález otevře; cizí položky odpojí.
  Fotky nemaže. Ověřená jen na SQLite.
* Otisk na zařízení, kde ještě není, se zapne až s kódem zámku — kdo kód
  nemá, otisk nezapne (hláška to řekne).

### Zbývá (vědomě neřešené)

* `MediaPurger` volí spojení s Diskem bez ohledu na vlastníka fotky — při
  dvou připojených Discích může trvalé smazání mířit na špatný účet
  (oblast záloh).
* Fotky z trezoru ve starých nálezech duplicit migrace neodpojí (seznam
  i sloučení je už přeskakují); počet fotek alba dál zahrnuje trezor.
* Znovunahraný totožný výpis, jehož řádky založil dřívější import, vidí
  jen řádky, které sám založil.

| Commit | Co |
|---|---|
| `7743efdf` | Otisk neodemkne zámek bez kódu |
| `4cf2805f` | Platby a úkoly dvojice — zpětné měsíce, haléře, hosté |
| `c91c5f63` | Starý zápis platby odmítne klíč, který se nevejde do sloupce |
| `f6a89135` | Noční úlohy — vzpomínky na MySQL, trezor, hosté, pražský čas |
| `f7cb4a15` | Hledání duplicit nemíchá fotky dvou dvojic |
| `d6571bd1` | Strom alb po přesunu, odkaz na smazané album, archiv, vzkazy hostů |
| `b1d3a2f8` | „Vyprázdnit koš" se zamčeným trezorem nesmaže trezor |

Testy: **1813 PHP testů**, všechny prošly (nové i v noci a na Silvestra
přes `TESTY_CAS`). **Jedna migrace** (jen data).

## 2aj. Třicáté sedmé kolo — účty, nahrávky, cloud a přepnutí účtu (25. 9.)

Sloučené obě větve samostatného úkolu (klient, který čeká JSON, dostane
z webové cesty 422 místo přesměrování; staré rozhraní ukáže chybu místo
falešného úspěchu)
a přestavěný `public/build`. Pak audit oblastí, které dosud neprošly
(`task-plan`), a opravy po třech dávkách (`task-deep`, `task-build`); každý
nález nejdřív potvrdil test, který bez opravy spadl.

### Zabezpečení

* **Pozvánka převzala cizí účet.** Vlastník jiné galerie zadal e-mail
  účtu, který „pozvánku nepřijal" — tak vypadají účty ze seedu i čekající
  pozvaní jiných galerií —, dostal odkaz s novým tokenem a nastavil si
  heslo. Teď 422; znovu pozvat jde jen účet, který tentýž vlastník sám
  založil a nikdo ho nepřevzal. Pozvánka platí týden.
* **Otisky prstu přežily obnovu hesla i „Odhlásit ostatní"** — a otisk
  vydá nový token sám. Teď se ruší; „Odhlásit ostatní" nechá jen otisk
  tohoto zařízení. Tytéž cesty zneplatní cookie „zapamatovat si mě".
* **Registrace otisku přepsala klíč jiného účtu** (`updateOrCreate` podle
  identifikátoru, který volby přihlášení vydají komukoli) — teď 422.
* **2FA:** kód z aplikace platí jednou, obnovovací kód projde i malými
  písmeny.
* **Změna e-mailu** dá vědět na starou adresu a zapíše se do protokolu.
* **Přihlášení jiným účtem na tomtéž zařízení** nechávalo v paměti, v
  localStorage i v cache workeru data toho předchozího (offline je dostal
  druhý). Teď se kopie zahodí a načte znovu.
* Worker ukládal kopii **každé** odpovědi pod `/api/` (i ZIP celého alba);
  teď jen povolený seznam.
* `DEPLOYMENT_ISPCONFIG.md` radil `storage:link`, který vydává originály
  i z trezoru bez přihlášení.

### Nahrávky a cloud

* Nahrávka z aplikace **neměla EXIF** (datum pořízení zůstalo z času změny
  souboru), otisk hlásil chybu u každé fotky a náhledy se počítaly dvakrát.
* Na Google Disk odcházely **dvě až tři kopie** každé fotky; smazání
  odstranilo jednu. Teď jedna (jedinečná úloha, rozběhnuté nahrávání se
  nezakládá znovu).
* Noční `mirror-backlog` u Disku **každou noc dokola** řadil prvních 500
  hotových a na zbytek nedošlo.
* Fotka vyhozená do koše ve frontě se do cloudu nenahraje.
* `gallery:clean-temp` hledal části nahrávek a sdílené soubory mimo disk,
  kam se zapisují — ležely napořád.

### Po nasazení

* **Migrace `zabezpeceni_uctu`** — tři nullable sloupce; čekající pozvánky
  dostanou týden ode dne nasazení.
* Otisky z doby před migrací vazbu na zařízení nemají: **první „Odhlásit
  ostatní" zruší i otisk tohoto zařízení** — přihlásit se heslem a otisk
  zapnout znovu.
* Kopie na Disku, které už vznikly dvakrát, se samy neuklidí.

### Zbývá (vědomě neřešené)

* Trezor se do cloudu kopíruje dál a přesun do trezoru kopii v cloudu
  nesmaže; trvalé smazání maže kopii jen na Google Disku, ne na Dropboxu,
  OneDrivu a WebDAV — rozhodnutí o zálohách.
* ~~Registrace otisku chce jen přihlášení, ne heslo.~~ — hotovo (2ak): kód
  zámku, kdo ho nemá, heslo.
* Pojistka fronty (`queue:work` každých 5 minut, zámek na 10 minut) může
  při dlouhých převodech videa pustit víc pracovníků naráz; úlohy se
  nezdvojí (`retry_after`), jen se zvýší zátěž.
* Přihlášení heslem na tomtéž zařízení odpojí otisk od tokenu; příští
  „Odhlásit ostatní" ho pak zruší taky (bezpečná strana).

| Commit | Co |
|---|---|
| `f4a8f39e`, `815ab40c` | Sloučení větví samostatného úkolu |
| `40218fff` | Přestavěné assety starého rozhraní |
| `68e3af11` | Návod pro ISPConfig neradí `storage:link` |
| `bdc7d819` | Pozvánka nepřevezme cizí účet, otisky a „zapamatovat" končí s odhlášením, 2FA |
| `fe6c0a03` | Nahrávka dostane EXIF, zpracuje se jednou, na Disk jedna kopie |
| `88192245` | Úklid dočasných souborů hledá tam, kam se zapisují |
| `18d0be60` | Přihlášení jiným účtem nezdědí kopii dat, worker jen povolené adresy |
| `43c63aa0` | Registrace otisku nepřepíše klíč jiného účtu |
| `1e32cefb` | Změna e-mailu dá vědět na starou adresu |

Testy: **1761 PHP testů**, všechny prošly (nové i v noci a na Silvestra
přes `TESTY_CAS`). **Jedna migrace.**

---

## 2ai. Třicáté šesté kolo — odhlášení a čeština frameworku (25. 9.)

* **„Odhlásit se" v aplikaci.** V nastavení (Zámek a přístup) na počítači
  i telefonu, jen u přihlášené dvojice. Neodeslané změny se nejdřív zkusí
  doručit; co zbude (bez signálu), se zahodí jen na výslovné „Odhlásit
  a zahodit" — s počtem („2 změny se ještě neodeslaly"). Dřív tlačítko
  chybělo a odhlášení by je zahodilo mlčky.
* **Hlášky frameworku česky.** Výchozí jazyk byl `en` a složka `lang/`
  neexistovala: přihlášení bez hesla hlásilo „The password field is
  required.", souhrn „(and 2 more errors)". Teď `lang/cs` (validace,
  přihlášení, obnova hesla, stránkování) a výchozí `cs`; testy běží česky.
  Česky začnou i datumy psané přes `translatedFormat` bez `locale('cs')`.
* Smazané vývojové klíče `mereni-23` a `mereni-telefon` z lokální databáze
  (bod 3.10).

**Po nasazení:** v `.env` na serveru má být `APP_LOCALE=cs` (nebo řádek
chybět). S `APP_LOCALE=en` zůstanou hlášky anglicky.

**Ověřeno bez přihlášení:** oba dokumenty se načtou bez chyby skriptu
a obě nové funkce jsou k dispozici; průchod dialogem s přihlášenou dvojicí
v prohlížeči neproběhl (přihlašovat se v relaci nesmím) — kontroluje ho
statický test pravidel.

~~**Nesloučeno:** větve `claude/kind-perlman-108d25` a
`claude/elated-tesla-64fdeb`~~ — sloučeno v kole 2aj, `public/build`
přestavěný.

| Commit | Co |
|---|---|
| `65064353` | Hlášky validace a přihlášení česky |
| `32bbef70` | „Odhlásit se" — a neodeslané změny se nezahodí bez zeptání |

Testy: **1716 PHP testů**, všechny prošly. Bez migrace.

---

## 2ah. Třicáté páté kolo — zápisy bez signálu, trezor, upozornění, noc (25. 9.)

Kolo podle plánu (`task-plan`) a oprav po dávkách (`task-deep`, `task-build`);
každý nález nejdřív potvrdil test, který bez opravy spadl.

### Zabezpečení

* **Zápis bez signálu odešel pod jiným účtem.** Fronta service workeru nesla
  zápisy i s hlavičkami a při „Odeslat" jim vyměnila token za právě
  přihlášený; odhlášení frontu nemazalo. Na sdíleném tabletu tak neodeslané
  změny jednoho skončily u druhého a pod jeho jménem. Teď každý zápis nese
  autora (`ucet`), worker vymění token jen u téhož účtu a server zápis
  s cizím autorem odmítne (422 `jiny_ucet`). Odhlášení napřed doručí vlastní
  zápisy, pak frontu smaže.
* **Vývoz vydal fotky z trezoru a z koše** — úloha filtrovala jen podle
  galerie, webové stažení hlídalo koš, ale ne trezor.
* **Odhlášený telefon dál dostával upozornění** — odhlášení, „odhlásit
  ostatní" ani odebraný přístup odběr (`push_subscriptions`) nerušily; text
  upozornění je vidět i na zamčené obrazovce. Hostům a účtům bez přístupu
  (`PristupDoGalerie`) se teď nic neposílá.

### Ztráta dat a špatná čísla

* **Dvě úpravy téhož klíče bez signálu: druhá se ztratila** (409 při doručení,
  worker ji potichu zahodil). Teď jedna položka fronty na adresu a autora,
  nejstarší `rev`, střety se kartě ohlásí.
* **Finance sčítaly koruny s eury** — u rozpočtu v eurech „utraceno 1 520 €"
  z 1 500 Kč a 20 €; každý řádek nesl měnu rozpočtu; převody se počítaly
  jako útrata.
* **Připomínka rozhodnutí k revizi nikdy neodešla** — četla rozhodnutí ze
  stavu, kde od převodu do `couple_decisions` nejsou.

### Noc mezi půlnocí a druhou

Osm testů padalo po pražské půlnoci. Šest z nich počítalo očekávání v UTC,
ale průchod celé sady v noci našel čtyři skutečné chyby: „Odhad vs.
skutečnost" v noci vynechal dnešní útratu (a 1. ledna zmizel celý), horizont
plateb měl první noc v měsíci o měsíc méně, jistota předpovědi taky, a starší
obrazovka cesty psala „zbývá 19 dní" vedle částky na 21 dní.

`TESTY_CAS` v `tests/TestCase.php` pustí celou sadu v libovolném okamžiku:

```bash
TESTY_CAS="2026-09-25 00:30 Europe/Prague" vendor/bin/phpunit
```

Cestou odzbrojené testy, které by padaly od prosince (napevno září) a prvního
či druhého dne v měsíci.

### Opravený závěr z kola 2ae

„Chyba 500 u validace na webových cestách" nebyla pravda — server vracel 302
a padala až hláška testu. Skutečnou chybu (axios tiše následoval přesměrování)
opravil samostatný úkol ve větvích `claude/kind-perlman-108d25`
a `claude/elated-tesla-64fdeb` — **zatím nesloučeno do `main`**.

### Zbývá

* ~~V aplikaci **není tlačítko „Odhlásit se"**~~ — hotovo (2ai).
* ~~Odhlášení bez signálu neodeslané změny zahodí bez varování~~ — hotovo
  (2ai): zeptá se s počtem.
* Po vypršení tokenu (401, ne odhlášení) zůstane fronta v IndexedDB, dokud se
  týž účet nepřihlásí nebo neodhlásí.
* Ověřit v prohlížeči s přihlášenou dvojicí: dvě úpravy bez signálu → jedna
  položka fronty; „Jiný účet" → žádný zápis s cizími daty; odhlášení →
  fronta pryč.

| Commit | Co |
|---|---|
| `b30a09a1` | Oprava závěru „chyba 500 u validace" |
| `d8b62336` | Vývoz nevydá fotky z trezoru ani z koše |
| `c14440e2` | Odhlášený telefon přestane dostávat upozornění |
| `76bd35de` | Připomínka rozhodnutí k revizi konečně odchází |
| `b8baf043` | Mezi půlnocí a druhou ráno počítají finance a testy správný den |
| `bb30dabb` | Finance nesčítají koruny s eury |
| `4b43d471` | Testy, které by padaly od prosince a prvního v měsíci |
| `0e7c1a0a` | Další dva testy nezávislé na dni spuštění |
| `ac1ad496` | Zápis bez signálu odejde jen pod účtem, který ho napsal |

Testy: **1711 PHP testů**, všechny prošly — i s `TESTY_CAS` v letní a zimní
noci, 1. října a v prosinci. Bez migrace.

---

## 2ag. Třicáté čtvrté kolo — záloha databáze, která opravdu existuje (24. 9.)

Bod 3.9 („obnova ze zálohy nebyla ověřená") se při prověrce ukázal horší:
**záloha databáze neexistovala vůbec.** `BACKUP_AND_RESTORE.md` popisoval noční
`BackupMetadataJob` a pět příkazů — žádný z nich v kódu nebyl. Originály fotek
se kopírují do cloudu (`mirror-backlog`), ale deník, finance, alba, lidé,
poznámky a zdravotní zápisy byly jen v MySQL.

**`gallery:zaloha`** — plánovač denně ve 3:30, drží posledních 14
(`GALLERY_BACKUP_KEEP`), do `storage/app/private/zalohy` (mimo web). Gzipovaný
NDJSON: hlavička s migracemi a počty, pak data. Po zápisu se soubor přečte
znovu a počty se porovnají; záloha, kterou nejde přečíst, se smaže a úloha
skončí chybou — v administraci je vidět jako „Záloha databáze".

**`gallery:obnova`** — bez `--opravdu` jen ukáže, co v záloze je. S ním
nejdřív zazálohuje současný stav a pak v jedné transakci přepíše tabulky.
Zálohu z novějšího kódu odmítne, zmizelý sloupec přeskočí a vypíše.

Záloha nese **data, ne schéma** (schéma postaví `migrate`), takže funguje stejně
na MySQL i SQLite a `ZalohaDatabazeTest` ji při každém běhu testů ověří celým
kruhem. `mysqldump` by se tu vyzkoušet nedal a `shell_exec` je na serveru
vypnutý. Vyzkoušeno i na vývojové databázi: 255 tabulek, 487 řádků, 39 kB.

**Otevřené rozhodnutí — kopie mimo server.** Záloha leží na stejném serveru.
Cloud je připojený po galeriích, záloha je za celou instalaci: nahrát ji do
cloudu jedné galerie by znamenalo dát jí data všech. Pro dnešní jednu dvojici
to nevadí, pro službu ano. Možnosti: (a) záloha panelu hostingu nebo `scp`
(dnes, ručně — popsáno v návodu), (b) šifrovaně do cloudu **provozovatele**,
(c) tlačítko „Stáhnout zálohu" v administraci jen pro provozovatele.

| Commit | Co |
|---|---|
| `f349f9c6` | Databáze se konečně zálohuje — a ze zálohy jde obnovit |
| `04dd449a` | Před migrací v `deploy.sh` se zazálohuje databáze; selhání nasazení zastaví |

Testy: **1684 PHP testů**, všechny prošly. Bez migrace; nové nastavení
`GALLERY_BACKUP_KEEP`.

---

## 2af. Třicáté třetí kolo — co je vidět zvenku a kdo je provozovatel (24. 9.)

Dokončený průchod starého API (zbylých 49 kontrolerů) a veřejné cesty bez
přihlášení. Každý nález je ověřený testem, který bez opravy spadne.

### Kritické

**Připojení Google Disku šlo podstrčit.** Přesměrování ke Googlu neneslo
`state` a zpětné volání žádný nekontrolovalo — jako jediné z OAuth v aplikaci
(Discord, Dropbox i OneDrive ho mají). Útočník si prošel souhlasem se svým
Diskem, nechal si nevyužitý `code` a podstrčil přihlášené oběti odkaz
`/oauth/google/callback?code=…` (SameSite=lax cookie při otevření odkazu
nese). Server kód vyměnil, uložil **útočníkův** Disk k účtu oběti a hned
zařadil synchronizaci všech jejích galerií. Teď náhodný stav v sezení,
jednorázový, porovnaný v konstantním čase.

**Vlastník galerie byl zároveň provozovatel instalace.** `users.role = owner`
má každý, kdo si založí galerii — a po otevření registrace
(`GALLERY_REGISTRATION_OPEN`) každý zákazník. Tahle role otvírala tržby všech
galerií, změnu tarifů a cen pro všechny, seznam všech účtů, klíče integrací
platformy a spouštění i pozastavování úloh, které běží pro všechny (to smel
i editor). Dnes spící díra — registrace je zavřená —, ale jejím otevřením by
se z každého zákazníka stal provozovatel. Nově `User::isOperator()` podle
`GALLERY_OPERATOR_EMAILS`, bez nastavení podle `GALLERY_OWNER_EMAIL`
(účet vlastníka má po migraci `sjednotit_ucty_dvojice` právě tuhle adresu,
takže se dnešní provoz nemění). Pozvánka do vlastní galerie zůstává vlastníkovi
— hlídá limit členů podle tarifu.

> **Při nasazení ověřit:** že se vlastník přihlašuje adresou z
> `GALLERY_OWNER_EMAIL`, případně nastavit `GALLERY_OPERATOR_EMAILS`. Jinak
> přijde o `/admin` a o ruční spouštění úloh.

### Vysoké

* **Odkaz „bez data a místa" vydával polohu.** Dialog to slibuje, `hide_gps`
  ale nikdo nečetl: stažení vydalo originál i s EXIF (souřadnice obvykle
  domova). Teď se stahuje zmenšenina — ta vzniká otočená a bez metadat;
  EXIF z originálu smazat nejde, je v něm i otočení snímku. Video se u takového
  odkazu nestahuje, stránka originál nenabídne ani jako náhled.
* **Přehled aktivity** (`/activity`) filtroval jen podle lidí, ne podle
  galerie — kdo je ve dvou, tomu partner viděl jména souborů z té druhé.
* **Nahrávky od hostů** (jediný zápis na disk bez přihlášení): smazaný odkaz
  vzal řádky (`cascadeOnDelete`), ne soubory — ty ležely na disku napořád.
  Odkaz měl limit jen na soubor, ne celkový; nově strop čekajících nahrávek
  `GALLERY_GUEST_UPLOAD_PENDING_MB` (2 GB). `gallery:clean-temp` dočistí
  sirotky z dřívějška.

### Ověřeno bez nálezu

Klíče dat, které server nedodává (66), jsou buď slovníky, nebo je prototyp
ukazuje jen v ukázce. Převodníky stavu mažou jen výslovně odebrané (klient
posílá `__odebrane` u všech seznamů). Bankovní OAuth je vázaný na žádost
uloženou na serveru. `laravel.log` obsahuje jen šum z testů.

**Pokrytí průchodu:** API galerie celé, starší API všech ~103 kontrolerů.

| Commit | Co |
|---|---|
| `0cdac12f` | Fotky od hostů nezůstávají na disku napořád a nezaplní ho |
| `a06fe2cc` | Připojení Google Disku nejde podstrčit cizím kódem |
| `d365acfd` | Vlastník galerie není provozovatel celé instalace |
| `6177693b` | Přehled aktivity ukazuje jen tuhle galerii |
| `5c9729e2` | Odkaz „bez data a místa" nevydá polohu ani ve staženém souboru |

Testy: **1676 PHP testů**, všechny prošly. Bez migrace; dvě nová nastavení
(`GALLERY_OPERATOR_EMAILS`, `GALLERY_GUEST_UPLOAD_PENDING_MB`).

---

## 2ae. Třicáté druhé kolo — den dvojice a cizí záznamy ve starém API (24. 9.)

Kolo bez zadaného seznamu: hledání chyb napříč kódem. Dvě třídy chyb, obě
s hlídkou, která je příště chytí dřív než člověk.

### „Dnes" bylo dnes v UTC

Server běží v UTC, dvojice v Praze. Mezi půlnocí a druhou ráno (v zimě do
jedné) je v UTC ještě včerejšek — a 36 míst bralo den, týden, měsíc či rok
právě odtud: obsah obrazovek, zápisy ze stavu, modely, automatizace, příkazy.

Nejhorší byla **nálada**. Čtení bralo okno přes `Cas::dnes()`, zápis půlnoc
v UTC. Dnešní hodnota se po půlnoci uložila ke včerejšku a přes
`updateOrCreate` **přepsala jeho skutečnou náladu**; protože prototyp posílá
celou řadu čtrnácti dnů, posunula se o den zpátky celá. Test to ukázal přesně:
z {24.: 3, 25.: 5} se stalo {23.: 3, 24.: 5}.

Dál: kuchařka a domácí týden v pondělí v noci ukazovaly minulý týden, první
noc v měsíci počítal rozpočet útratu minulého měsíce, 1. ledna patřil
„letošek" loňsku, a termín slibu, automatický zápis do deníku, odpočet dárku
i výročí dostaly včerejší datum.

`CasovaPasmaPrikazuTest` dřív chytal jen `Carbon::today()` a jen v příkazech —
`CarbonImmutable::today()`, `today()` ani `now()->startOfWeek()` neviděl.
Teď zná všechny tvary a kontroluje i obsah, stav, kontrolery galerie, modely
a automatizaci, s číslem řádku.

**Zůstává:** asi 60 stejných míst ve starším API (`Api/*` mimo galerii a jeho
služby — `CycleService`, `FinanceService`, `RecurringService`, `FinanceFilter`…).
Patří zamrzlé aplikaci a hlídka je záměrně nekontroluje.

### Cizí osoby, štítky a místa ve starém API

Dva agenti prošli trasy API galerie (116, bez nálezu) a starého API; každý
nález jsem ověřil testem, který bez opravy spadne.

Starší API ověřovalo `exists:people,id`, `exists:tags,id` a `exists:places,id`
— celou tabulku, ne galerii. S číslem cizí osoby (čísla jdou po sobě) si ji
kdokoli připojil k vlastní fotce a odpověď na úpravu mu ji vrátila celou:
jméno, přezdívku, narozeniny; u místa adresu a souřadnice. Opraveno v úpravě
fotky i v hromadné akci (tam navíc ke každé fotce jen z její galerie), a stejně
u obalu alba — ten by se cizí fotkou ukázal na veřejném sdíleném odkazu, kde
globální rozsah bez přihlášení ustupuje.

Místa bez galerie (z doby před sloupcem `gallery_space_id` nebo po smazané
galerii) šla číst, přepsat i smazat odkudkoli: kontrola platila jen tehdy,
když galerie vyplněná **byla**. Seznam míst je nikomu neukazuje, takže přísná
kontrola legitimnímu uživateli nic nebere.

Ze 13 pravidel `exists:` nad tabulkami s galerií byla děravá čtyři; nahrávání,
sdílení a alba si id po validaci ověřují podle galerie sama.

**Druhý průchod** (dalších 28 kontrolerů starého API) našel stejný tvar chyby
bez pravidla `exists:` vůbec — číslo prošlo jako `nullable|integer`:

* **příběh alba — skutečný únik.** `PATCH …/story/{id}` omezil zápis na album,
  ale odpověď četla blok podle čísla odkudkoli; prázdný požadavek s cizím
  číslem vrátil text cizího příběhu. Tohle agent přehlédl, přišlo se na to
  při ověřování jeho nálezů. Fotky v bloku se teď berou jen z galerie alba;
* transakce, pravidelná platba a šablona vzaly partnera z cizí galerie;
* rozpočet vzal cizí cestu, úkol při úpravě cizí `trip_id` (založení ji
  ověřovalo, úprava ne);
* rozpočet i cesta šly předat vlastníkovi mimo galerii — a pak se k nim
  nedostal nikdo z dvojice; výchozí účet cesty a kategorie mohl být cizí.

Kromě příběhu nic z toho cizí data neukázalo (globální rozsah je přihlášenému
schová), ale záznam v naší galerii ukazoval do cizí.

**Hlídky:** `ExistsVGaleriiTest` shodí každé nové `exists:` nad tabulkou
s galerií bez omezení na galerii (ověřeno proti kódu před opravou — ukázal
přesně pět děravých pravidel). `PravidlaDokumentuPrototypuTest` nově hlídá
metody třídy `Component` definované dvakrát — druhá definice tiše přepíše
první, a tak telefon jednou vůbec neukládal stav. Dnes jsou obě třídy čisté.

**Pokrytí:** API galerie celé (116 tras), starší API v prioritních
kontrolerech (~58 ze ~103). Zbytek starého API projitý není.

**Nález zvlášť — opraveno (tady šlo původně o chybný závěr):** tvrzení, že
chyba validace na webových cestách starého rozhraní končí u JSON klienta
chybou 500, **nebylo pravdivé**. Server vracel 302 zpět; „Call to a member
function all() on array" hodil až Laravelův `TestResponseAssert` při skládání
hlášky selhaného `assertStatus` (s `session.serialization = json` jsou chyby
v sezení pole). Skutečná chyba byla jinde: axios přesměrování tiše následoval
na 200 s HTML, ohlásil úspěch a hláška o chybě se neukázala. Samostatný úkol
ji opravil ve větvích `claude/kind-perlman-108d25` (server vrací JSON klientovi
422) a `claude/elated-tesla-64fdeb` (obrazovky chybu ukážou) — **zatím
nesloučeno do `main`**.

### `read_only_mode` — ověřeno, zatím nevynuceno

Z bodu 3.5: klient odmítnutý zápis stavu dokola neopakuje (401/403 zápisy
zastaví), ale 403 bere jako **odebraný přístup a odhlásí**. Protože každý toast
je zápis stavu, účet „jen pro čtení" by vyhodilo při první hlášce. Vynutit to
chce odpověď, kterou klient pozná zvlášť — zápis zahodí a řekne proč, místo
odhlášení. Dokud žádná obrazovka režim nezapíná, zůstává, jak je.

| Commit | Co |
|---|---|
| `13ea1a68` | „Dnes" je dnes dvojice, ne dnes v UTC |
| `526a700e` | Ke svým fotkám nejde připojit osobu, štítek ani místo z cizí galerie |
| `8be7b281` | Hlídka na metody prototypu definované dvakrát |
| `dd909778` | Hlídka na `exists:` nad tabulkami galerie |
| `cce2bc98` | Příběh alba nevrací cizí blok, finance neukazují do cizí galerie |
| `7110eef2` | Rozpočet ani cestu nejde předat mimo galerii |

Testy: **1657 PHP testů**, všechny prošly. Bez migrace.

---

## 2ad. Třicáté první kolo — drobnosti z auditu: co nespouštělo nic (24. 9.)

Tři věci, které audit vedl jako drobnosti, protože nic nerozbíjely. Každá
z nich ale znamenala, že něco slíbeného nefunguje.

### Patnáct příkazů, které nespouštělo nic

Z 38 příkazů jich 14 nespouštěl ani plánovač, ani `deploy.sh`, ani
`Artisan::call`. Dvanáct z nich je opravné nářadí pro člověka a to je
v pořádku — ale dva tam nepatřily:

`gallery:billing-reminders` posílá upozornění „zkušební období končí za tři
dny", „předplatné se obnoví za týden" a „prošlo". Dvojici nepřišlo ani jedno;
o konci zkoušky se dozvěděla tím, že jí galerie spadla na základní tarif. Teď
běží denně v 9:30. Denní běh je bezpečný: příkaz si každé upozornění hlídá sám
přes protokol (`billing.reminder` v `audit_logs`, porovnané proti začátku
období), takže z denního plánu nevznikne každodenní připomínání téhož.

`gallery:upravy-ze-stavu` dohání popisky, místa, data a štítky, které zůstaly
jen ve společném stavu. Jeho vlastní docblock říká „jednou po nasazení", a tak
je teď v `deploy.sh`. Opakování nevadí — od kola 2aa se srovnává proti databázi.

Rozdíl mezi „ručně schválně" a „zapomněli jsme to zapojit" se z kódu nepozná,
takže tenhle nález nemá zůstat jednorázovou opravou. `PrikazySeSpoustiTest`
projde všechny příkazy a shodí se na každém, který nespouští nic, dokud u něj
někdo nenapíše do seznamu `RUCNE` důvod. Druhý test hlídá, aby ten seznam
nezastaral. `PopisyUlohTest` hlídá zase to, aby nová úloha nedostala
v administraci anglický název ze záchranného převodu klíče — to se už jednou
stalo třem úlohám a všimlo si toho až oko.

### Limity mezipaměti variant, které nikdo nečetl

`variant_cache_max_size_gb` (20) a `variant_cache_max_age_days` (90) byly
v konfiguraci od začátku a nečetl je nikdo. Dvacet gigabajtů byl údaj
v souboru, ne limit.

`gallery:uklid-variant` je čte a je schválně dvakrát opatrný:

* **Velikost rozhoduje, stáří jen vybírá.** Dokud se mezipaměť do limitu vejde,
  neděje se nic — ani u variant starých roky. Zmenšenina, kterou nic netlačí,
  je užitečná; mazat ji podle data by z galerie udělalo pomalou galerii bez
  důvodu. Teprve nad limitem je stáří nejlepším vodítkem, co zahodit dřív.
* **Zahodit jde jen to, co nikomu nechybí:** zmenšeniny (`small`, `medium`,
  `large`) a převod videa. Bez nich se pošle originál — pomalejší, ale správné.
  Originál je sama fotka, náhled a plakát videa jsou tvář galerie a `edited_*`
  je výsledek úpravy dvojice; těch se úklid nedotkne.
* **Bez originálu na disku se nemaže nic.** Tam je zmenšenina poslední kopie
  a smazat ji není úklid, ale ztráta.

Když ani po vyklizení všeho dovoleného limit nestačí, příkaz to řekne nahlas.
To je pro správce informace, že problém je velikost knihovny, ne mezipaměti —
dosud neexistovala vůbec. `--nasucho` vypíše, co by šlo pryč, a nesmaže nic.

Příprava k tomu odhalila vlastní chybu: `GenerateExportJob` bral `large ??
medium` a fotku bez obou **tiše přeskočil** — stažený ZIP se tvářil hotově
a pár snímků v něm prostě nebylo. Stejná díra byla v `ApplyMediaEditJob`: bez
obou se úprava neprovedla vůbec. Originál byl v obou případech celou dobu
vedle a je teď poslední v řadě. Bez téhle opravy by z výjimky udělal úklid
variant každodennost.

### Tabulka, která se jen plnila

`scheduled_task_runs` rostla od svého vzniku. Sám tep plánovače je řádek každou
minutu — přes půl milionu ročně; se zbytkem úloh zhruba 1,8 milionu. A čte se
z ní při každém otevření administrace.

`gallery:uklid-protokol` nechává posledních 90 dní (`GALLERY_TASK_LOG_DAYS`)
a k tomu **vždycky poslední běh každé úlohy**, ať je jakkoli starý.
Administrace z něj bere sloupec „naposledy"; bez té výjimky by úloha, která
běží jednou za rok, o sobě tvrdila „nikdy", a to je horší informace než žádná.

Druhá půlka je rejstřík. `ScheduledTaskRun::posledni()` počítá `MAX(id)` podle
úlohy a tabulka měla jen `(task, started_at)`, podle kterého se `MAX(id)`
spočítat nedá — databáze musela projít všechny řádky úlohy. S `(task, id)` si
vezme z každé poslední položku rejstříku a dál nečte. Zmenšit tabulku bez
rejstříku je jen polovina práce.

| Commit | Co |
|---|---|
| `5312ec37` | Upozornění na konec zkušební doby nespouštělo nic |
| `96db89b6` | Vývoz tiše vynechával fotky bez zmenšenin |
| `99d48ffe` | Limity mezipaměti variant konečně něco dělají |
| `ff038703` | Protokol běhů úloh se zkracuje a hledá se v něm přes rejstřík |

Testy: **1635 PHP testů**, všechny prošly. **Jedna migrace** (rejstřík
`(task, id)` nad `scheduled_task_runs`).

---

## 2ac. Třicáté kolo — poslední čtyři nálezy auditu (24. 9.)

Tím je audit z kola 2y vyčerpaný: kritické, vysoké i střední nálezy jsou
opravené. Ke každé opravě test, který bez ní spadne.

### Protokol, který dvojice neviděla

`AuditLog::record()` bere galerii z předmětu akce. Záznamy bez předmětu
(`app_lock.*`, `vault.*`, `auth.login*`) i ty, jejichž předmětem je `User` —
ten sloupec `gallery_space_id` nemá — se ukládaly s `null`, a panel Aktivita
čte `where('gallery_space_id', …)`. Odemykání zámku, otevření trezoru ani
přihlášení se tedy neukázalo nikdy, přestože obě obrazovky protokol slibují.

Galerie se teď odvodí od toho, kdo akci vyvolal. Bez přihlášeného člověka
zůstává `null` — u úloh z fronty se odvodit nedá a hádat se nemá.

### Sdílení, které hlásilo uloženo

Dialog `album` i `polozky` posílal a validace je přijímala, ale `update()` je
nikam nezapsal. Odkaz dál servíroval původní sadu fotek a kdo z něj chtěl
fotku odebrat, měl za to, že ji odebral.

### Dvě večeře na jeden den

Čtení bralo všechno kromě `cancelled`, zápis jen `planned` a `confirmed`.
Uvařené jídlo se na obrazovce ukázalo, ale při uložení se nenašlo: výběr jiného
receptu ten den založil druhý řádek a vyčištění dne neudělalo nic.

Filtry jsou sjednocené. Uvolnění dne ale uvařené jídlo **nemaže** — je to
záznam o tom, co dvojice jedla. Dva testy si v tomhle odporovaly a ten starší
měl pravdu; rozhodnutí je zapsané v obou.

### Střety u klíčů z tabulek

`rev_keys` zapisoval až `applyPatch()`, jenže ten dostane patch teprve potom, co
z něj každý převodník vytáhl své klíče. U všeho, co má vlastní tabulku —
papírová záloha, pravidla, rozhodnutí, domácnost, kalendář, mapa energie,
přání — revize nikdy nevznikla a `strety()` neměl co porovnat: vyhrál poslední
zápis a nikomu se nic neřeklo. Dosud to jistily jen `__zmenene` a `__odebrane`
v prohlížeči.

---

## 2ab. Dvacáté deváté kolo — co zbylo po auditu (24. 9.)

Kolo bez zadaného seznamu: pokračování v nálezech, které audit vedl jako
provozní. Ke každé opravě test, který bez ní spadne.

### Sirotci po trvalém mazání — důsledek vlastní opravy

Devět sloupců v osmi tabulkách míří na `media_items` bez cizího klíče: obálka
alba, osoby, cesty, fotoknihy a stohu, druhá půlka živé fotky, výsledek
nahrávání, doklad hosta a záznam o stažení.

Dokud koš mazal měkce, řádek zůstával a odkaz pořád na něco ukazoval. Od
chvíle, kdy se maže doopravdy (kolo 27), ukazuje do prázdna. Stojí za to si to
přiznat: **tohle nezpůsobil audit, ale naše vlastní oprava** — a ukázalo se to
teprve tím, že se na to někdo zeptal testem.

Řeší to obsluha `deleting` na modelu, protože cizí klíče s `nullOnDelete()`
těch devět sloupců nemá (`recipes.cover_media_id` je má, takže je to
přehlédnutí). Týká se jen `forceDelete()`; při měkkém smazání se odkazy nechají.

### `gallery:exif --clean-orphans`

Bral každou položku bez `drive_file_id`, jejíž originál nenašel na místním
disku, a rovnou ji odstranil natvrdo. Do té množiny ale patří i fotky zrcadlené
do Dropboxu nebo OneDrivu (ty `drive_file_id` nemají z principu) a položky,
které se právě nahrávají. Bez zkoušky nanečisto, bez potvrzení a bez zápisu do
protokolu. Příkaz nikdo nespouští — to je jediný důvod, proč se to nestalo.

Výchozí chování je teď výpis, maže se až s `--opravdu`. Při psaní testu vyšlo
najevo, že `--clean-orphans` navíc pokračoval čtením EXIF z celé knihovny a že
jediná fotka v Dropboxu ten průchod shodí výjimkou o nenastaveném disku.

### Dny cesty

`sort_order` se počítal jako `$datum->diffInDays($start)`. Carbon 3 vrací
rozdíl **se znaménkem** a v tomhle pořadí je to `start − datum`, tedy záporné
číslo pro každý den po tom prvním. Sloupec je `unsignedSmallInteger`: na MySQL
spadne celá transakce a bod programu nejde přidat nikam než na první den.

### Plánovač

Patnáct úloh mělo `withoutOverlapping()` bez uvedené platnosti — výchozí zámek
drží **1440 minut**. `releaseOnTerminationSignals` nepokryje SIGKILL ani výpadek
proudu, takže po jednom takovém konci minutová úloha den neběží.

A `runInBackground()` hlásí skutečný konec událostí
`ScheduledBackgroundTaskFinished`, na kterou nikdo neposlouchal: `gallery:doctor`
a vyprazdňování fronty se zapisovaly vždy jako úspěšné. Doktor mohl vracet
FAILURE donekonečna a administrace o tom mlčela — přitom je to jediné místo,
kde by se dvojice dozvěděla, že něco spadlo.

---

## 2aa. Dvacáté sedmé kolo — šířky sloupců, soukromí, koš (24. 9.)

Body 6–10 z pořadí v auditu 2y. Ke každému test, který bez opravy spadne.

### Co projde přes stav, se vejde do sloupce

Devatenáct sloupců mělo `Vejde` širší než sloupec, nebo `Vejde` nemělo vůbec.
Nejostřejší byl `couple_story_chapters.year` — volné pole pro rok s devíti
znaky, do kterého šel syrový text z prohlížeče. Na MySQL (`strict`) by první
takový zápis shodil celý `PATCH /api/state`, a protože `galerie-api.js` bere
500 jako výpadek sítě, patch by se týden vracel do fronty bez jediné hlášky:
aplikace by přestala ukládat cokoli a nikde by to nevypadalo jako porucha.

Šířku `client_id` (64 na devíti místech) drží `Vejde::KLIENT`.

Hlídá to `SirkySloupcuTest`: pošle přes stav dlouhé texty a projde **každý**
znakový sloupec v databázi proti šířce z migrací. Šířky se nečtou ze schématu
testovací databáze — SQLite je do `CREATE TABLE` vůbec nezapíše, takže by sken
neměl co kontrolovat; vlastní test proto hlídá i to, že sken není prázdný.

### Srdíčka a mapa energie

`favs` bylo v databázi per-uživatele, ale ukládalo se do **společného** stavu —
a klient sdílenou mapu čte přednostně před serverovým příznakem. Druhý viděl
cizích čtyřicet srdíček jako svá. Klíč je teď v `CoupleState::NEUKLADAT`:
přijme se, zapíše do `user_favorites` a do společného dokumentu nejde.
`persistSkip()` v prohlížeči by ho neposlal vůbec a srdíčka by se přestala
ukládat — tím se ta cesta vylučuje.

Tím padl výpočet rozdílu proti stavu (proti prázdnému „předtím" vypadá odebrání
jako žádná změna), takže se `oblibene()` ptá databáze. Vedle toho zmizel celý
problém staré kopie. Dluh zápisu proto nese i hodnotu, ne jen jméno klíče.

Mapa energie: počítač posílá mřížku obou lidí a zapisovala se oběma, takže
kliknutí ze starší záložky vrátilo partnerovi všech 42 buněk. Teď jen svůj
řádek — táž pojistka, jakou má `FilmyVeStavu` u známek.

### Koš a nahrávání na Disk

Všechny čtyři cesty koše volaly `->delete()` na modelu se `SoftDeletes`:
soubory zmizely, řádek zůstal a noční úklid ho nevidí. `gallery:purge-trash`
měl navíc vlastní kopii mazání, která o Google Disku nevěděla a `disk`
z variant brala jako jméno filesystemu (u zrcadlení je to `dropbox`), takže
výjimka spadla do logu a mazalo se dál. Vede teď přes `MediaPurger` a uklidí
i sirotky po dřívějším měkkém mazání.

`UploadDriveChunkJob::dispatch()` slibovalo `PendingDispatch` a vracelo úlohu —
`TypeError` při každém volání a nic ve frontě. Nahrávání po částech tedy
nikdy neběželo a každý z pěti pokusů nechal na Googlu osiřelou relaci.

### Indexy pro MySQL

`albums.materialized_path` a `push_subscriptions.endpoint` mají 2048 znaků =
8192 bajtů proti stropu klíče 3072. Cesta stromem se indexuje po 191 znacích
(hledá se podle předpony); u odběru upozornění by prefix nestačil, protože se
adresy liší až na konci — jednoznačnost drží `endpoint_hash` (SHA-256).
`DelkaIndexuTest` čte migrace, ne schéma testovací databáze.

### Zbytek auditu — body 11–12 a oddíl Vysoké

**Klíče.** `jenSpravce` pouští i roli `editor` a `klic()` hledá mezi tokeny
všech členů, takže partner mohl zrušit vlastníkův klíč a vydat si náhradní na
sebe — a v tom seznamu nejsou jen API klíče, ale i přihlašovací tokeny
zařízení. Druhá vada: náhrada se razila na toho, kdo klikl, takže obnovený
partnerův klíč najednou patřil vlastníkovi galerie.

**Zámek.** `nastav()` nemělo tři pokusy, blokaci ani zápis do protokolu, které
má `over()`. Hádalo se tudy heslo do galerie donekonečna a nezbyla po tom stopa.

**Slučování lidí.** Přecházely jen poznámky, jejichž `scope_key` cíl ještě
neměl; zbytek zmizel s kaskádou po smazání zdrojové osoby — typicky partnerova
soukromá poznámka. Dvě poznámky téhož druhu se teď spojí pod sebe; vybírat za
dvojici, která verze je ta pravá, tu nikomu nepřísluší.

**Slučování duplicit.** Prohlížeč posílal pořadí kopie v seznamu. Když mezi
vykreslením a kliknutím kterákoli kopie zmizela, pořadí se posunulo a do koše
šla jiná fotka, než která svítila. Teď identifikátor; čtení i zápis navíc
dostaly pevné druhé kritérium řazení.

**Připomínky.** `event_reminders.user_id` byl vždy `created_by`, takže když
jeden zapsal druhému zubaře, přišla připomínka jemu. Teď účastníkům z
`event_participants`.

**Nasazení.** `deploy.sh` dostalo bránu `galerie:pred-nasazenim`, `chown` na
`storage` a znovunačtení PHP-FPM. Bez `chown` PHP-FPM nezapíše šablony a
aplikace odpoví 500; při `opcache.validate_timestamps=0` se bez reloadu nový
kód vůbec neprojeví. Oba kroky se přeskočí s hláškou, když skript neběží pod
rootem.

**Časová pásma.** `Cas` se v `app/Console/Commands` nepoužíval ani jednou, takže
okna spuštění ležela o dvě hodiny jinde, než co slibuje jejich popis: cyklus
„dopoledne" v 10–12 h, večerní souhrn ve 21–23 h. Deset naplánovaných úloh
s pevnou hodinou dostalo `->timezone()`. Hlídá `CasovaPasmaPrikazuTest`.

---

## 2z. Dvacáté šesté kolo — opravy z auditu a vlastní adresy (24. 9.)

Pět nálezů z auditu 2y opraveno, ke každému test, který bez opravy spadne.
K tomu druhá věc ze zadání: každá obrazovka dostala vlastní adresu.

### Opravy z auditu

- **Pozvánka na cizí účet.** `invite` odmítal e-mail jen tehdy, když už byl
  členem téhle galerie; u účtu někoho jiného se přepsal `invitation_token`,
  `invitation_accepted_at` se vrátilo na `null` a odkaz dostal volající. Kdo
  ho otevřel, nastavil si na cizí účet heslo a přihlásil se. Podmínka je
  v `AdministraceZasahy::pozvi`, ne jen v kontroleru — zvát umí i stavová
  cesta (`AdminVeStavu:133`), která kontrolerem neprochází.
- **Vývoz fotek** filtruje podle `gallery_space_id` (ve frontě není přihlášený
  uživatel, takže `SpaceContext` ustupuje) a kontroler cizí identifikátor
  odmítne už při zadání. Výběr je ve statické `GenerateExportJob::vybraneFotky()`,
  aby šel testovat bez fronty.
- **Ztracený zápis ze stavu.** Dva `catch (\Throwable)` zahazovaly úpravu
  natrvalo: rozdíl se počítá proti stavu *před* uložením a stav se uložil tak
  jako tak. Převodníky teď selhání hlásí zpátky, `StateController` si ho uloží
  do stavu pod interní `__dluh` a při dalším požadavku ho zaplatí — hodnotu
  vezme ze stavu a předchozí předá prázdnou, takže se zapíše celá. `__dluh`
  ke klientovi nejde a od klienta se nebere.
- **`couple_truths`** doplněno do `MechanismyVeStavu::KLICE` a `truths` do
  seznamů `KLICE`/`ZMENY` v prohlížeči — bez toho se obě pojistky proti staré
  kopii chovaly, jako by je klient neposlal. K tomu **pojistka proti hromadnému
  smazání** v devíti převodnících: prázdný seznam bez `__odebrane` se nedá
  odlišit od „nic jsem neodebral", takže se v tom případě nemaže nic.
- **Fronta.** `retry_after` je 3900 s (nejdelší úloha běží 3600 s) a čte se
  z `DB_QUEUE_RETRY_AFTER`; `.env.example` dřív nastavoval `QUEUE_RETRY_AFTER`,
  což nečte nic. `queue:retry all` z plánovače pryč — vynuloval pokusy a mazal
  `failed_jobs`, takže doktor neměl co hlásit. `queue:work` dostal
  `--queue=high,default,media,drive`; bez toho bral jen `default`, zatímco
  35 míst posílá úlohy jinam.

### Vlastní adresa pro každou obrazovku

Prototyp měl všech 56 obrazovek na `/`: nedalo se nikam odkázat, nic přidat do
oblíbených, obnovení stránky vrátilo dvojici na úvod a tlačítko Zpět zavřelo
celou aplikaci. Teď `/galerie`, `/galerie/alba`, `/galerie/cyklus`…

Seznam je v `App\Support\TrasyPrototypu` a platí pro obě strany. Neznámý kousek
adresy je 404, ne tichý návrat na úvod.

Tři věci, které to málem rozbily:

1. **Relativní skripty.** Dokument si je načítá relativně (`./support.js`),
   takže na `/galerie/alba` by mířily do `/galerie/`. Řeší to `<base href="/">`,
   vkládaný hned za `<head>` — hlavička jde až před `</head>`, což je pozdě.
   Je to táž past, kvůli které má vynucené rozvržení pomlčku místo lomítka.
2. **Parametry cest.** Laravel je předává kontroleru podle pořadí, ne podle
   jména, takže `/galerie/alba` skončilo v `$rozvrzeni`. Pěkné adresy mají
   vlastní metodu `obrazovka()`.
3. **Trasa v počátečním stavu.** Autorské soubory načítá runtime až po
   zpracování dokumentu, takže obrazovka kreslená z `galerie-data.js` při
   prvním vykreslení spadla na `renderVals()` — červený pruh místo aplikace,
   zrovna u kuchařky. Trasa se proto nastavuje až v `componentDidMount`.

Telefon má vlastní navigaci (záložky a zásobník), ale `openRoute()`
i `currentRoute()` už v dokumentu byly, takže se z ní nic nekopíruje.

**Nález bez opravy:** telefonní rozvržení se ve vývojovém náhledu vykreslí
prázdné — a je to tak i bez změn tohohle kola (ověřeno odložením souborů).
Chyba `Cannot read properties of undefined (reading 'map')` je v konzoli
i na původním kódu. Ověřit na skutečném zařízení, ne v emulaci.

---

## 2y. Dvacáté páté kolo — kompletní audit (24. 9.)

Kolo bez oprav: tři průzkumy (bezpečnost a přístup, zapisovací cesty,
plánovač a schéma) plus vlastní ověření každého nálezu proti zdroji.
Opraveno bylo jediné — vlastní chyba z kola 2x (viz `139745dd`). Zbytek
je popsaný tady, aby se o pořadí rozhodovalo podle důkazů, ne podle dojmu.

### Kritické — kdokoli si může vzít cizí účet

**Pozvánka na e-mail, který už účet má.** `AdminController::invite`
(`:77-79`) odmítne e-mail jen tehdy, když už je členem **téhle** galerie.
U cizího účtu `AdministraceZasahy::pozvi` (`:147-148`) přepíše jeho
`invitation_token` a `invitation_accepted_at` vrátí na `null` — znovu
natáhne pozvánku, kterou ten člověk přijal třeba před rokem — a
`AdminController:85` pošle odkaz volajícímu. `InvitationController::accept`
(`:45-52`) na něj nastaví nové heslo, potvrdí e-mail a přihlásí.
Vlastník galerie si tak otevře cizí účet: deník se `visibility='private'`,
trezor, finance. Původní majitel se nepřihlásí. Chybí jediná podmínka:
účet existuje a pozvánku už přijal.

**Vývoz fotek nesahá na prostor.** `GenerateExportJob:39-41` vybírá
`MediaItem::where('primary_album_id', …)` a `whereIn('id', media_ids)`
bez `gallery_space_id`; `SpaceContext` ve frontě ustupuje, protože tam
není přihlášený uživatel, a `ExportController:23` ověřuje jen
`'media_ids' => 'nullable|array'`. Kdo si vyžádá vývoz s cizími
identifikátory, dostane ZIP s fotkami jiné dvojice.

### Kritické — ztráta zápisu

**Dvě `catch (\Throwable)` zahodí úpravu natrvalo.**
`MediaVeStavu:50-53` (popisek, místo, datum, štítky, lidé) a
`FinanceVeStavu:102-105` (zařazení transakce). Obojí se počítá jako rozdíl
proti `$predtim`, což je stav **před** uložením patche — a stav se uloží
i tak. Při dalším požadavku je tedy rozdíl prázdný a **zápis se už nikdy
nezopakuje**. Jedna chyba databáze = úprava je pryč, odpověď je 200
a obrazovka dál ukazuje nový popisek, který v knihovně není.

**Sloupec je užší než `Vejde`.** MySQL má `'strict' => true`, vývoj běží
na SQLite, která spolkne cokoli. Celý `PATCH /api/state` je jedna
transakce (`StateController:119`), takže jediný dlouhý řetězec shodí
uložení **všeho** — a `galerie-api.js:400-404` bere 500 jako výpadek sítě
a zkouší to znovu s odstupem, týden, bez hlášky. 16 míst má `Vejde` širší
než sloupec (mimo jiné všech devět `client_id` na 80 proti sloupci 64,
`house_chores.icon` 60/40, `calendar_events.title` 255/160) a dvanáct
posílá surový text klienta do sloupce s limitem — nejužší je
`couple_story_chapters.year` **varchar(9)** u volného pole pro rok.

**`couple_truths` má špatný klíč.** `MechanismyVeStavu::KLICE` (`:29-34`)
tabulku nezná, takže `srovnej()` hledá `'couple_truths'`, zatímco prohlížeč
posílá `truths`. Obě pojistky proti staré kopii tím padnou a mazání se
utrhne: přidání jedné „dvě pravdy" smaže všechny, které mezitím zapsal
partner. Stejný vzorec (`whereNotIn` + `whereIn`, oba vypadnou) je
v devíti dalších převodnících; tam ho drží jen to, že klient `__odebrane`
posílá — je to jeden řádek JavaScriptu od toho, aby byl živý.

**Tři klíče se neukládají nikam.** `mlLoad`, `pauseLog`, `pausePlan` jsou
v `SERVEROVE`, takže je `bezMechanismu()` ze stavu vyhodí, ale `zpracuj()`
je nezná a `couple_pause` ani `couple_mental_load` v `app/` nikdo nezapisuje.
Tlačítko „Pauza ukončena · zapsáno do historie" nezapíše nic.

### Vysoké — jeden vidí data druhého

- **Srdíčka jsou v databázi soukromá, ve stavu společná.** `favs` není
  v `persistSkip()` počítače ani v `CoupleState`, takže jde do sdíleného
  stavu — a klient ho čte **přednostně** před serverovým příznakem
  uživatele. Partner vidí cizí oblíbené jako své.
- **Mapa energie se přepisuje celá.** Počítač posílá řádky obou a
  `KlidVeStavu:185-216` je oběma zapíše. Klik na stará data vrátí partnerovi
  všechny buňky. Jinde v kódu je přesně tahle pojistka napsaná
  (`FilmyVeStavu:319-327`).
- **Správce (nejen vlastník) umí zrušit klíč vlastníka** a vydat si nový
  (`AdminController:184-211`) — a v seznamu klíčů jsou i přihlašovací
  tokeny zařízení, ne jen API klíče.
- **`ZamekController::nastav()` je druhé dveře k PINu** bez tří pokusů,
  bez blokace a bez zápisu do protokolu, které má `over()`.
- **Sloučení duplicit vybírá fotku do koše podle pořadí v poli**, bez
  druhého kritéria řazení; když se mezi vykreslením a klikem cokoli změní,
  do koše jde jiná fotka.
- **„Trvale odstraněno" je `->delete()` na modelu se `SoftDeletes`** —
  soubory zmizí, řádek zůstane, noční úklid ho nevidí. Mazání z Disku
  je uvnitř `catch`, takže při výpadku Disku originál v cloudu zůstane
  a zpráva stejně řekne „trvale".
- **Sloučení lidí smaže partnerovu soukromou poznámku** (`LideController:112-121`).

### Plánovač a fronta

- `queue:retry all` každých 10 minut resetuje pokusy a maže `failed_jobs`:
  trvale padající úloha se točí donekonečna a `gallery:doctor` se o ní
  nikdy nedozví. Systém tím nemá kam hlásit selhání.
- `retry_after` čte `DB_QUEUE_RETRY_AFTER` (výchozí **90 s**), zatímco
  `.env.example` nastavuje `QUEUE_RETRY_AFTER`, což nečte nic. Skoro každá
  úloha má delší `$timeout`, takže se **spouští podruhé, než doběhne první**
  — u vývozu (3600 s) si dvě kopie přepisují tentýž ZIP.
- `UploadDriveChunkJob::dispatch()` slibuje `PendingDispatch` a vrací
  `new static(...)` → `TypeError` při každém volání; po částech se na Disk
  nenahrálo nikdy a každý pokus nechá na Googlu osiřelou relaci.
- `queue:work` v plánovači nemá `--queue=`, takže bere jen `default` —
  fronty `media`, `drive` a `high` (35 míst) zůstávají ležet.
- **15 příkazů nespouští nic**, mimo jiné `galerie:pred-nasazenim`
  (brána před nasazením, má vlastní test), `gallery:upravy-ze-stavu`
  (dohnání starších úprav) a `gallery:billing-reminders`.
- `deploy.sh` vynechává `chown` na `storage`, znovunačtení PHP-FPM
  (s `validate_timestamps=0` se nový kód neprojeví) a tu bránu.
- Všechny příkazy počítají čas v UTC: `gallery:daily-moment` míří na
  9–21 h, ve skutečnosti běží 11–23 h pražského času; denní souhrn chodí
  ve 21–23 h; připomínky cyklu v 10–12 h.

### Schéma pro MySQL

`migrate` na čisté MySQL spadne na čtvrté migraci:
`albums.materialized_path` je `string(2048)` a indexovaný = 8192 bajtů
proti limitu 3072. Totéž `push_subscriptions.endpoint` (`unique`).
Devět sloupců `*_cover_media_id` nemá cizí klíč, takže po trvalém smazání
fotky ukazují na nic.

---

## 2x. Dvacáté čtvrté kolo — pozvánka místo kódu, alba podle id (24. 9.)

- **Druhý z dvojice se přidá pozvánkou.** Krok prvního spuštění „Jsme dva"
  se ptal na kód z druhého telefonu a jediná přijímaná hodnota byla
  `OBCODE = 'K7M2QF'` — konstanta v `galerie-data.js`, tedy v souboru, který
  server podá komukoli; nápověda ji rovnou vypisovala a vedle stálo tlačítko
  „Vyplnit kód z prototypu". Spárování bylo dekorace: na server nešlo nic.
  Nově se volá `POST /api/admin/users`, tedy totéž, co tlačítko
  v administraci. Krok **neblokuje** — kdo zakládá galerii sám, projde dál
  a pozve partnera kdykoli z Administrace.
- **Alba na telefonu podle identifikátoru, ne podle názvu.** Obsah alba se
  skládal porovnáním popisku dlaždice (`$f['album'] === $a['name']`), takže
  dvě alba se stejným názvem dostala obě fotky obou, fotka bez alba se
  chytla na album jménem „Bez alba" a snímek zařazený jen přes spojovací
  tabulku v albu chyběl. Bez dotazu navíc (`Knihovna` zůstává na 51).

**Triáž zbylých klíčů — plán z 2w se ukázal menší, než čekal.** Z 55 klíčů,
které server nedodává, je **54 slovník aplikace** (názvy měsíců, spouštěče
pravidel, fáze cyklu, formáty tisku, kroky průvodce) a zůstávají. `SRCHMSG`
i `TICHO_TOPICS` už jsou za `ukazka()`, `RITUALS` je katalog s uloženým
stavem dvojice vedle. Jediný skutečný nález byl `OBCODE` — a nebyl to
chybějící poskytovatel, ale předstíraná funkce. **Dvanáct nových
poskytovatelů tedy psát netřeba.**

**Změřeno, neměněno podruhé:** dokument má 2 499 KB, z toho 389 KB (15,6 %)
odsazení, 160 KB (6,4 %) komentáře a 529 KB (21,2 %) inline styly
v 7 953 výskytech. Odstranit odsazení by přenos skoro nezlevnilo (gzip
opakované mezery stlačí) a sbírání bílého místa mezi řádkovými prvky mění
vykreslení — riziko je větší než zisk na době parsování.

**Nález bez opravy:** `System` vyskočil ze 113 na 145 dotazů. Je to cena
za kontroly z kola 2v (měkké mazání v `zivotSekci`, viditelnost rozpočtu):
každý `Tabulky::sloupec()` je dotaz do schématu. Na SQLite levné, na MySQL
ne — stojí za revizi v příštím kole.

| Commit | Obsah |
|---|---|
| `3d1bf60c` | Druhého z dvojice přidá pozvánka, ne kód z veřejného souboru |
| `532f6ac5` | Dvě alba se stejným názvem si na telefonu prohazovala fotky |

Testy: **1540 PHP testů**, všechny prošly. Nic se nemigruje.

## 2w. Dvacáté třetí kolo — prototyp jako produkt (24. 9.)

Rozhodnutí z tohohle kola: **prototyp na `/` je produkt**. React/Inertia
aplikace (90 stránek, zamrzlá na `1a6a93a3` ze 14. 9.) zůstává, jak je —
a tím se ruší i nález „rozbitá navigace": React odkazuje „Domů" na `/`,
což je od přestěhování rozcestníku na `/prehled` správně.

- **Cizí život zmizel ze všech obrazovek přihlášené dvojice.** Dokument čte
  `galerie-data.js` na ~80 místech tvarem `this.state.X || UKAZKA` a dalších
  55 metod `*Vals()` bez jakékoli pojistky. Opraveno **jedním mechanismem**:
  `navlec()` mění pole na místě, takže stačí kolekce vyprázdnit hned při
  načtení — konstanty rozebrané v dokumentu zůstanou platné a serverová data
  do nich natečou. Týž vzor jako `MECH_PRAZDNE` od kola 2t.
- **Tvary posílá server** z `prazdne()` poskytovatelů, ne ručně psaný seznam
  na klientu. Nový `Poskytovatele` drží seznam jednou pro kontroler
  i pro prototyp; `AppServiceProvider` se tím zkrátil o 22 řádků.
- **Pojistka by u skutečné dvojice nikdy nespustila** — prototyp se
  přihlašuje klíčem přes `/sanctum/token` a `TokenController` sezení
  nezakládá, takže `$request->user()` je pro něj `null`. Rozhoduje proto
  i uložený klíč.
- **Čtyři kolekce neuměly být prázdné** (`AFORMS`, `POSTEPS`, `LOCKWHO`,
  `LOCKMAIL`) — právě tam by ukázka zůstala. Nový test to hlídá pro všechny
  poskytovatele naráz.
- **Pokročilé filtry začaly filtrovat.** Zásuvka s pěti skupinami
  a devatenácti volbami zapisovala `s.filters`, kreslila odznak i chipy —
  a mřížka ukázala totéž co předtím. Stav se četl na deseti místech, při
  výběru fotek ani na jednom. Filtruje se teď ve `visible()` nad tím, co
  dlaždice nesou; volby v jedné skupině jako „nebo", skupiny mezi sebou jako
  „a zároveň". Ověřeno: „Videa" + „Fotky" = vše, „Videa" + „Oblíbené" = nic.
- **Dvě políčka „Od" a „Do"** měla natvrdo červenec 2024, žádnou obsluhu
  a žádný stavový klíč. **Čtyři zaškrtávátka** v panelu časové osy neměla
  obsluhu vůbec a dvě byla natvrdo zaškrtnutá.
- **Demo hesla z veřejného souboru.** `VAULT_PWD`, `LOCKPIN`, `LOCKREC` —
  ověřuje je server už od dřívějších kol a nikdo je nečte, ale ležely dál
  v souboru, který server podá komukoli. Teď jsou prázdné. (Konkrétní
  hodnoty se nepíšou ani sem — viz kolo 2y, kde se ukázalo, že komentář
  s heslem ve veřejném souboru je totéž zveřejnění jako konstanta.)

**Změřeno, neměněno:** indexy jsou na všech horkých tabulkách v pořádku
(`media_items(gallery_space_id, taken_at)`, `transactions(…, occurred_at)`,
`calendar_events(…, starts_at)` a dál). Načtení vychází na 2,0 s do
DOMContentLoaded a dominuje mu **2,5 MB dokumentu**, ne skripty —
`galerie-data.js` je z toho ~9 %. Dokument je gzipovaný a s ETagem, takže
opakovaná návštěva dostane 304.

**Nález bez opravy:** `gallery:billing-reminders` není v `routes/console.php`
ani v `deploy.sh` — ten příkaz nikdy neběží. Zapnout ho je rozhodnutí
o fakturaci, ne oprava.

| Commit | Obsah |
|---|---|
| `560f8ea2` | Cizí život zmizel ze všech obrazovek přihlášené dvojice |
| `559892f3` | Pokročilé filtry začaly filtrovat |

Testy: **1538 PHP testů**, všechny prošly. Nic se nemigruje.

### Zbývá z tohohle plánu (kolo 24)

Dvanáct klíčů jsou data dvojice, která **žádný poskytovatel nedodává** —
`CYC_TODAY`, `CYC_SHARE`, `AMISS`, `RITUALS`, `ADMIN`, `MSGREPLIES`,
`KAP_TRIG`, `GV_VOICE_POOL` a další. Rozhodnutí padlo dodělat k nim
poskytovatele (ne jen vyprázdnit). Zámek je z nich nejdál: `LOCKWHO`
a `LOCKMAIL` server dodává, zbytek ne.

## 2v. Dvacáté druhé kolo — audit poskytovatelů obsahu, čas dvojice (23. 9.)

Hloubková kontrola všech 31 souborů v `app/Services/Obsah/` (17 264 řádků):
odkud se berou čísla, která galerie píše na obrazovku.

- **Čísla, která nikdo nenaměřil.** „Pozdní večery" počítaly podíl z **fotek**
  (`n` = snímků, `reg` = dnů, kdy se fotilo), takže dvojici, která fotí ráno
  jednu fotku denně, vyšlo „100 % věcí odtud skončilo špatně". Přestěhováno
  do `Mechanismy::pozdniVecery()` a počítá se z věcí, které opravdu mají
  pozdější osud: útraty s vratkou a rozhodnutí označená za změněná.
  Popisky pásem navíc neseděly s tím, co obrazovka hledá jako „pozdní",
  takže nadpis hlásil natrvalo nula procent.
- **Přesuny počítané jako útrata** na dvanácti místech (`type != 'income'`):
  směna 80 000 Kč na eura i každý výběr z bankomatu nafoukly rozpočet
  i „obvyklou útratu" — a tytéž peníze se počítaly podruhé, až se utratily.
  Pravidlo je teď jednou: `Transaction::scopeUtraty()` a `scopeZapsane()`
  (koncepty a smazané zápisy měnily zůstatek i předpověď).
- **Čas dvojice vs. čas serveru.** Kontrola běžela po pražské půlnoci a
  **jedenáct testů padalo i bez jediné změny**: mezi 00:00 a 02:00 má
  aplikace dvě různá „dnes". Účet splatný dnes hlásil „za 1 den", týdenní
  přehled v pondělí v 01:30 začínal **o týden zpět**, odpověď na otázku pro
  dva zmizela a nešla zapsat znovu (jedinečný klíč). Opraveno v dvanácti
  poskytovatelích; testy mají `TestCase::dnes()` a `ted()`, aby počítaly
  stejnými hodinami jako aplikace.
- **Soukromí**: řádek „Délka cyklu" obcházel celé nastavení sdílení cyklu;
  nesdílený osobní rozpočet druhého se kreslil i s limity; soukromý zápis
  v deníku cesty viděl i ten druhý; deník akcí nenesl galerii, takže kdo je
  ve dvou, viděl v jedné jména souborů z druhé (migrace).
- **Dva pády**: `json_decode(...) ?: []` propustí skalár — uložené `"vegan"`
  shodilo celou skupinu `kucharka` (500). A `Tyden::svet()` si říkal
  o sloupec, který na `media_items` není: SQLite to spolkne, MySQL na
  produkci vrátí `Unknown column`, skupina spadne a prototyp dokreslí
  ukázkové Chorvatsko.
- **Prázdné stavy**: `Tyden` a `Dnes` neuměly říct, jak vypadají prázdné,
  takže kdykoli se skupina nespočítala, zůstal na obrazovce cizí týden.
  Doplněno i `CYC_NASTAVENI` (ukázkové sdílení cyklu), `ABARS.cap`, `MENA`
  a `BUD.year.worst/best`.
- **Výkon**: dotazy uvnitř cyklů (obálky alb, videa, duplicity, zprávy,
  rozvahy, dárky, odkazy). Měřeno na vývojové databázi v transakci, která
  se vrátila zpět: padesát alb s obálkami a pětadvacet videí stojí **dva
  dotazy navíc** místo zhruba sto sedmdesáti pěti. `Uklid` navíc načítal
  celou knihovnu a procházel ji pro každý ze čtyřiceti snímků.
- **Drobnosti, které mění rozhodnutí**: propadlý pas neměl žádný štítek
  (příznak `'expiring'` se nikde nezapisuje), odložení „na dnes večer" bylo
  prošlé hned, pruh čerpání přetékal při 130 %, místa „raději ne" stála
  mezi „kam chceme", cena za porci se dělila porcemi receptu místo porcemi
  toho vaření, srdíčko partnera se ukazovalo jako moje, odznak „Je ve
  sdílení" četl stav zálohy.
- **Čeština**: druhý pád jmen dělal „od Tomáša", „od Ondřeja", „od Míšy",
  „od Soňy" — nově Tomáše, Ondřeje, Míši, Soni (21 jmen hlídá test).
  A tvary podle čísla: „1 minut", „1 hodin", „1 dílů", „1 jednou".

| Commit | Obsah |
|---|---|
| `c18803cd` | Obrazovky přestaly tvrdit čísla, která nikdo nenaměřil |
| `9ba4bd81` | Po půlnoci aplikace počítala dny o jeden vedle |
| `c84fd72b` | Věty, které nedávaly smysl, a součty přes dvě měny |
| `061ed3a5` | Dotazy, kterých přibývalo s každým albem a každým videem |
| `95a1d6fe` | Propadlý pas bez varování a další drobnosti, které mění rozhodnutí |

Testy: **1535 PHP testů**, všechny prošly. **Jedna migrace** (viz níže).

### Po nasazení (2v)

- Migrace přidá `audit_logs.gallery_space_id`. Starým záznamům se prostor
  nedopočítá (u smazaného předmětu to nejde), takže **aktivita na úvodní
  obrazovce bude první dny prázdná** a naplní se z nových akcí.
- Scénáře v šedesátidenní předpovědi: „Zrušit dvě předplatná" se teď počítá
  ze dvou nejmenších pravidelných plateb a jmenuje je. Když dvojice nemá
  aspoň dvě, scénář se neposílá.
- „Zůstatek na účtech" počítá jen účty v nejčastější měně a řekne, kolik
  jich zůstalo stranou. Dřív sčítal koruny s eury.
- Kdo zapisoval cyklus a vidí ho druhý: přehled teď ukazuje jen vlastní
  záznamy, takže se čísla můžou proti dřívějšku lišit.

## 2u. Dvacáté první kolo — bezpečnost přístupu, druhý audit telefonu (22. 9.)

Audit oprávnění celého galerijního API, druhý průchod telefonem a kontrola
rozhraní (přetečení, jména pro čtečku, klávesnice).

- **Bezpečnost — dvě odpovědi na otázku „ve které galerii jsem"**: kontrola
  role se ptala neseřazeným `gallerySpaces()->first()`, požadavek pak běžel
  v prostoru z `UrcujePar::parId()`. Kdo byl v jedné galerii host a ve své
  vlastní vlastník, prošel kontrolou podle té svojí a sáhl si na cizí fotky,
  stav i administraci. Relace má teď pevné pořadí — a s ní i devadesát
  dalších míst, která se ptají týmž `first()`.
- **Trvalé mazání koše** se řídilo sloupcem `users.role` (rolí účtu, ne rolí
  v galerii); **`GET /api/admin`** jako jediná metoda administrace neměla
  kontrolu; **smazání stavu** nechávalo šifrovanou část a revize klíčů
  (`private` ani `rev_keys` nejsou v `$fillable`) a po „smazání" se nedalo
  psát; **`PATCH /api/v1/profil`** ověřuje heslo a neměl strop pokusů.
- **Telefon — ztráta dat**: odložené a přijaté návrhy ze schránky po obnovení
  mizely (server je posílá zvlášť), kolečko a kategorie plateb se neukládaly.
- **Telefon — hluché ovládání**: balíčky „do telefonu" nic nestahovaly,
  přijetí žádosti nedošlo k druhému, „Řazení" psalo do klíče počítače, tiché
  hodiny u vzpomínek nic nenastavily (teď nastaví skutečné 22:00–8:00),
  export sliboval e-mail, rituály „připraví výstup", volby času a příjemce
  připomínek nikam nešly. Otevření dne z kalendáře u prázdné knihovny padalo.
- **Poctivé prázdné stavy** pro dvacet záložek, které se dvojici nenaplní
  (Zároveň, Přepisy, TV režim, Tierlist, Automatizace, Cesta právě nyní…) —
  místo obecného „až se záložka začne plnit" rovnou řeknou, kde ta věc je.
- **Prostor s jedním členem** (druhý ještě nepřijal pozvánku) dostával jména
  z ukázky; teď je to „vaše jméno" a „Druhý z vás".
- **Rozhraní**: dlaždice fotek a políčka kalendáře mají jméno pro čtečku,
  Escape zavírá všechny dialogy (kromě záchranných kódů), telefon ani počítač
  nemají přetečení (kontrolováno i na 360 px).

| Commit | Obsah |
|---|---|
| `e7cd8a96` | Dlaždice fotek a políčka kalendáře mají jméno pro čtečku |
| `f1c79cf9` | Escape zavírá všechny dialogy, ne jen půlku z nich |
| `1184d1d9` | Bezpečnost: jedna odpověď na otázku, ve které galerii jsem |
| `ae8714e0` | Telefon: co jste rozhodli, zůstane — a přepínače dělají, co slibují |
| `1b50c8d2` | Prostor s jedním členem není ukázka; prázdné záložky říkají pravdu |

Testy: **1508 PHP testů**, všechny prošly. Nic se nemigruje.

### Po nasazení (2u)

- Kdo je v jedné galerii host a jinde vlastník, se do té cizí přestane
  dostávat. Pokud takový účet existuje a **má** tam mít přístup, je potřeba
  mu v té galerii změnit roli na `editor` (Administrace → účty).
- Trvale vysypat koš smí nově jen vlastník a správce prostoru.

## 2t. Dvacáté kolo — mechanismy pro dva: konec prázdných obrazovek (22. 9.)

Audit souboru s mechanismy (`public/galerie-mechanismy-logika.js`) a kontrola
očima **úplně prázdné dvojice** (zkušební prostor bez jediného řádku).

- **Ukázka místo dat při výpadku**: `/api/mechanisms` posílá sbírky prázdné,
  jenže při 503 (chybí `mechanismy.json` v nasazení) nebo výpadku sítě si
  klient nechával data ze souboru — tedy postoj ukázkové dvojice k dětem,
  zdraví jejích rodičů a jmenovitě lidi, co se o ně bojí. Hlavička teď sbírky
  vyprázdní sama, jakmile ví, že jde o přihlášenou dvojici.
- **Zakládání tam, kde nebylo co číst**: Arbitr (rozpory + výběr mechanismu +
  rozhodnutí do Paměti rozhodnutí), Začátek hádky (spouštěč a protilék),
  Rozhodnutí, která tíží, Matice nezávislosti, Síť důvěry, Kdo je dnes na tom
  hůř (zápis za sebe, jen na dnešek) a Každý sám (svoje příjmy a dluhy,
  společné náklady — místo napevno vepsaných 3 400 Kč splátka ze zápisu).
  Z protiléku jde
  udělat vypršovací domluvu — ta teď existuje doopravdy (`expExtra`)
  a vyhodnocuje ji i noční `galerie:expire`. Rozepsané formuláře zůstávají
  v zařízení (persistSkip), do sdíleného stavu jde až hotový řádek.
- **Hluchá tlačítka**: „na nedělní desetiminutovku" zapíše téma na agendu,
  „Co z rozhovoru vyšlo" zapíše větu do „Až budeme mít čas", „přesunuto do
  vyhrazených částek" už netvrdí přesun, který se nekonal, „na měsíc přebírá"
  neslibuje měsíc, který nikdo nepočítá, a „Vyrovnat podíly" se ukazuje jen
  tam, kde je co vyrovnávat. Blízkost po zápisu ukáže tento týden místo
  nehybné nuly.
- **Prázdné seznamy**: Záznam verzí bez rozhodnutí neukazuje formulář, který
  by zapsal verzi „k ničemu"; Děti snesou jednoho člena prostoru; Arbitr,
  spouštěče a Blízkost mají poctivé nadpisy pro nulu.
- **Server**: `galerie:expire` četl pravidla ukázkové dvojice z `mechanismy.json`
  a zapisoval `expDead` s cizími id do stavu **každé** dvojice (a hlásil
  „Vypršelo N domluv", které neexistují). Teď vyhodnocuje jen domluvy té které
  dvojice.

Ověřeno v prohlížeči na prázdné dvojici: 55 tras počítače a 149 záložek
telefonu bez chyby, bez „undefined" a bez jediného ukázkového jména; celý
průchod Arbitrem (zapsat rozpor → vybrat mechanismus → rozhodnout → zápis
v Paměti rozhodnutí) i Začátkem hádky (spouštěč → protilék → vypršovací
domluva s odpočtem 364 dní). Zkušební dvojice je z databáze smazaná.

| Commit | Obsah |
|---|---|
| `42a1ffcb` | Formát: dva soubory podle pintu |
| `ec3f6234` | Mechanismy pro dva: konec prázdných obrazovek a hluchých tlačítek |

Testy: **1503 PHP testů**, všechny prošly. Nic se nemigruje.

## 2s. Devatenácté kolo — dvoufázové přihlášení, tiché hodiny, klíče stavu (22. 9.)

Druhý audit počítače a audit klíčů, které si telefon a počítač posílají.

- **Pojistka — dialog účtu ve sdíleném stavu**: rozepsaný dialog „Změnit
  heslo" / „Jméno a e-mail" (`acDlg`: současné i nové heslo, nově i tajný
  klíč 2FA) do stavu nepatří. Počítač ho vyřazuje už příponou `Dlg`
  (`persistKey`) a telefon ho neukládá, takže k úniku nedocházelo — server
  ho teď ale zahazuje i sám (`CoupleState::NEUKLADAT`) a migrace by smazala,
  kdyby ho poslal jiný klient. (Popis commitu `a5d93e2c` tvrdí, že ho
  počítač posílal; to se nepotvrdilo — viz oprava v dalším commitu.)
- **Dvoufázové přihlášení z nastavení** (počítač i telefon): heslo → tajný
  klíč do ověřovací aplikace → kód → záchranné kódy (jednou, zavírá se jen
  „Mám je uložené"). Vypnutí chce heslo v těle `DELETE`, ne v adrese.
  Ověřování hesla v API účtu (2FA i změna hesla) má strop 10 pokusů/min.
- **Tiché hodiny doopravdy**: u dvojice jdou do nastavení upozornění
  přihlášeného (každého zvlášť). Server je počítal v UTC — teď v místním
  čase — a web push je vůbec nečetl: teď v tichých hodinách neodejde nic
  kromě připomínky k akci, kterou si člověk nastavil sám; připomínka
  partnerovi řekne „má tiché hodiny". „Strop N denně" nahradil skutečný
  večerní souhrn (`digest`).
- **Alba**: „Duplikovat strukturu" založí kopii i se stromem podalb na
  serveru; podalba se u alb dvojice nevymýšlejí a berou se ze všech alb
  (seznam alb místo 40 posledních nese 200).
- **Poctivé obrazovky**: hledání nabízí místa a štítky dvojice; náhled
  chráněného odkazu nechce ukázkové heslo; doklad u platby otevře fotku;
  hlasovka nehlásí „odeslána" před nahráním; dárky ukazují nejbližší
  příležitost; lhůty neslibují periodu; fotky cesty podle jejích dnů;
  „Navrhnout termín" otevře novou akci; rozvaha, nápady, pravidlo po 22:00,
  režim „je mimo" a přepínače „Co pomohlo" neslibují automatiku, která
  není; úložiště bez odpovědi neukazuje ukázkových 114,5 GB.
- **Klíče sdíleného stavu**: `klQuiet` (telefon objekt, počítač 24 hodin —
  Klid na počítači padal), `arbDone` („Rozhodl los · undefined"), `nedDone`
  (odškrtávalo jinou položku), `rtOn` (vypínalo výchozí rituály), seriály
  v `rowDone` (pořadí vs. id titulu) a `curVotes` (hlasuje přihlášený).

Známé, ale neškodné: kapsle otevřená v telefonu (`kapOpened`) zůstane na
počítači zapečetěná — nic se nerozbije, jen se to nesejde.

Ověřeno v prohlížeči: dialog 2FA na obou zařízeních (chybné heslo →
„Zadané heslo nesouhlasí.", kroky klíč a záchranné kódy, bez „Zrušit"
u kódů), řádek nastavení → dialog, tiché hodiny proti serveru (22–24 h,
ztišené peníze, souhrn; vráceno zpět), duplikace alba (kopie uklizena),
návrh termínu, rituály z telefonu; průchod 55 tras počítače a 149 záložek
telefonu bez chyb a bez „undefined".

| Commit | Obsah |
|---|---|
| `745ba9fb` | Tisk: fotoknihy ze serveru zůstávají vidět vedle návrhů |
| `36a76dec` | Nastavení: dvoufázové přihlášení se zapíná a vypíná přímo v galerii |
| `a5d93e2c` | Stav: dialog účtu (hesla, klíč 2FA) se neukládá do sdíleného stavu (pojistka, viz výš) |
| `235e535b` | Druhý audit počítače: tiché hodiny na serveru, duplikace alb, poctivé texty |
| `4b872670` | Klid: telefon a počítač si nepřepisují klíč klQuiet |
| `4547be5a` | Telefon: bez odpovědi úložiště žádných ukázkových 114,5 GB |
| `b6d9b294` | Sdílený stav: telefon a počítač čtou stejné klíče stejně |
| `1f42c987` | Oprava popisu a5d93e2c: dialog účtu do stavu neunikal |

### Po nasazení (2s)

- Migrace `2026_09_22_120000_smazat_dialog_uctu_ze_stavu` spustí
  `deploy.sh`; maže ze stavu dvojice jen klíče `acDlg` a `klSrv` (nejspíš
  tam nejsou — je to pojistka).
- Tiché hodiny začnou platit pro web push hned po nasazení — kdo je má
  v nastavení zapnuté ze starého rozhraní, tomu v nich přestanou chodit
  upozornění do telefonu (kromě připomínek k akcím).

### Po nasazení (2r)

- Migrace `2026_09_22_100000_komentare_ze_stavu_do_tabulky` a
  `2026_09_22_110000_add_archived_at_to_albums` spustí `deploy.sh`
  (`artisan migrate --force`). Převedou komentáře a „archivovaná" alba ze
  stavu dvojice; nic se nemaže kromě těch dvou klíčů ve stavu.

### Po nasazení (2q)

- Plánovač má novou úlohu `memories` (`gallery:memories`, 8:30 Europe/Prague).
  Na serveru běží `schedule:run` z cronu, takže stačí nasadit; první
  vzpomínky lze vytvořit hned: `php artisan gallery:memories --no-notify`.

### Po nasazení (2p)

- Nic se nemigruje. Položky cestovní schránky, které dřívější verze
  označila stavem „filed", se dál ukazují jako zařazené.

### Po nasazení (2n)

- Nic se nemigruje. Import výpisu používá tabulky bankovního modulu
  (`bank_imports`, `bank_transactions`), které už na serveru jsou.
- Velké výpisy: aplikace přijme do 20 MB; pokud nginx/PHP odmítne dřív
  (`client_max_body_size`, `upload_max_filesize`), klient řekne „výpis je na
  server příliš velký".

---

## 3. Známé nedostatky — bezpečnost

Seřazeno podle rizika. Nic z toho není aktivně zneužitelné bez jiné chyby,
ale každá položka zmenšuje, co by jedna chyba napáchala.

1. **Token je v `localStorage`.** Jakýkoli XSS znamená převzetí účtu
   (proto byla díra v mapě tak vážná). Dlouhodobě: přihlášení cookie
   s `HttpOnly` (Sanctum SPA režim) místo tokenu ve skriptu.
2. **CSP povoluje `'unsafe-inline'` a `'unsafe-eval'`.** Vyžaduje to běh
   prototypu (inline skripty, Babel v prohlížeči). Cesta ven: předkompilovat
   JSX při sestavení a přejít na `nonce`.
3. ~~**Ikony Phosphor se načítají z unpkg bez SRI**~~ — SRI doplněné (2d).
   Písma ikon jdou dál z unpkg; úplné řešení je stáhnout do `public/vendor`.
4. **Zámek aplikace (PIN) je jen v rozhraní.** Token funguje i v zamčené
   aplikaci. Chrání před někým u odemčeného telefonu, ne před útokem na API.
   (Počítadlo pokusů je od 2d u účtu, ne v sezení.)
5. **Dva modely rolí.** `users.role` (owner/partner/viewer) používá staré
   rozhraní, `gallery_space_user.role` aplikace dvojice. ~~Ve starším API `v1`
   kontroluje oprávnění jen 16 z 82 kontrolerů — s hostem to vadí~~ — hotovo
   (2h): host do `v1` ani do starého rozhraní nesmí (pozná se podle členství
   v prostoru). Zbývá `read_only_mode`: API galerie ho nekontroluje, ale
   žádná obrazovka ho nezapíná (jen databáze). Před vynucením ověřit, že
   klient odmítnutý zápis stavu neopakuje dokola (`PATCH /api/state`, WAF).
6. ~~**Prostor se určuje jako „první" bez řazení**~~ — v API galerie hotovo (2d):
   výchozí, jinak nejstarší. Staré rozhraní (`gallerySpaces()->first()` na ~90
   místech) řazení nemá; pro dvojici s jedním prostorem to nevadí.
7. **Staré rozhraní `/prehled` (Inertia) pořád běží** — druhá plocha, kterou
   je potřeba udržovat a hlídat. Buď ho vypnout, nebo sjednotit oprávnění.
8. **`npm audit`**: postcss a nanoid (jen nástroje sestavení, ne běh aplikace).
   `npm audit fix` + `npm run build` a zkontrolovat `public/build`.
9. ~~**Obnova ze zálohy nebyla ověřená**~~ — záloha databáze neexistovala
   vůbec; od kola 2ag `gallery:zaloha` / `gallery:obnova`, ověřené testem celým
   kruhem. Zbývá kopie mimo server (viz 2ag).
10. ~~Vývojový přístupový klíč „mereni" v **lokální** databázi~~ — smazané
    (2ai: `mereni-23`, `mereni-telefon`).
11. ~~**Pokusy o heslo k trezoru se počítají v sezení**~~ — hotovo (2d): cache
    podle účtu s prodlužujícím se uzavřením. Na produkci musí `CACHE_STORE`
    být sdílený (databáze/redis), ne `array`.
12. ~~**Podepsané náhledy platí do konce zítřka**~~ — hotovo (2e): náhled
    fotky v koši i obrázek ze smazané zprávy dřív vydaná adresa nevydá.
13. ~~**Staré místní řádky v telefonu**~~ — hotovo (2e): migrace
    `smazat_hesla_ze_stavu_dvojice` je ze stavu smaže.

---

## 4. Funkce, které aplikace poctivě hlásí jako „zatím neumíme"

Tlačítka neříkají, že se něco stalo, ale přiznají to. Po třetím kole (2c)
zůstává u dvojice jen to, co potřebuje napojení na cizí službu nebo vlastní
návrh — většina hlášek „bez připojeného serveru" platí jen pro ukázku.

### Potřebuje cizí službu
- Automatické stahování transakcí a napojení banky (potřebuje poskytovatele
  a klíče). ~~Import výpisu do knihy galerie nemá~~ — hotovo (2n): výpis
  CSV/XLS/XLSX se nahraje u účtu a zapíše do Transakcí
- Investice: nákup a rebalance (nová pozice u dvojice založí spořicí účet)
- Dvoufázové přihlášení se zapíná ve starém rozhraní — v galerii je jen jeho
  stav (zapnutí potřebuje QR kód ověřovací aplikace)

### Potřebuje návrh
- Hromadné vrácení celé historie změn (jednotlivé kroky z okna vrátit jde)
- Kompletní archiv originálů jedním souborem (dnes: originály na Google Disku,
  vybrané fotky jako ZIP z výběru; celé GB přes prohlížeč nejdou)
- Přepis hlasovek na text (aplikace ho nedělá a netvrdí to)
- ~~Přidání osoby na fotku ručně~~ — hotovo (2o): „+ Osoba" v detailu
  fotky. Rozpoznávání tváří aplikace dál nemá a netvrdí to.

Úplný seznam: `grep -o "zatimNeumime('[^']*'" resources/galerie/*.html`.

---

## 5. Kvalita a provoz

1. **Prohlížečové testy do CI.** Detektory překryvů, přetékání, průchod
   všech stránek a od 2e i kontrola šablony proti hodnotám (chybějící pole,
   obsluha kliknutí bez funkce, po otevření každého dialogu) teď žijí jen
   v `localStorage` vývojového prohlížeče. Přepsat do Playwright: průchod
   161 tras a 105 vnořených záložek počítače a obrazovek telefonu, s plnými
   a prázdnými daty (`prazdne()` poskytovatelů) i prázdnou knihovnou,
   na 360/768/1440 px.
2. **Hlídat „tiché lži".** Vzorec, který se opakoval: tlačítko ohlásí úspěch
   (`toast`) a nic neuloží, nebo obrazovka sáhne po ukázce (`|| SAMPLE`).
   Test, který projde všechny `toast(` bez zápisu (stav, API), by je chytal
   dřív než člověk.
3. ~~**Dvoufázové ověření v aplikaci** se ptá dialogem prohlížeče~~ — hotovo
   (2e): políčko „Kód z ověřovací aplikace" na přihlašovací obrazovce.
4. **Fronta běží z plánovače** (`queue:work --stop-when-empty` každou minutu).
   Při dlouhém přenosu na Disk je lepší stálý worker (supervisor/systemd).
5. **Nasazení bez SSH** — povolit klíč pro nasazení (nebo webhook z GitHubu),
   aby šlo nasadit bez ručního kroku na serveru.
6. **WAF** už jednou zablokoval adresu dvojice kvůli smyčce zápisů. Hlídat
   počet `PATCH /api/state` za minutu v logu po každé větší změně klienta.

---

## 6. Doporučené pořadí

1. **Nasadit a projít kontrolní seznam z bodu 1** (hodina práce, odblokuje vše).
2. Bezpečnost 3 (ikony do `public/vendor`) a 8 (npm audit) — malé, rychlé.
3. Projít na produkci s oběma účty deník, nastavení účtu a trezor na telefonu
   (bod 2c) — mění se tím, co druhý vidí.
4. Prohlížečové testy do CI (bod 5.1) — bez nich se každá další úprava
   prototypu ověřuje ručně.
5. Bezpečnost 1–2 (cookie místo tokenu, CSP s nonce) — větší zásah do
   běhu prototypu, udělat až s testy z kroku 4.
6. Sjednotit role (bezpečnost 5–7) a rozhodnout o starém rozhraní `/prehled`.
