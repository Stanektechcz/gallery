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
  vidí jen odkazy, které dostane. Tak to administrace vždycky popisovala.
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
   rozhraní, `gallery_space_user.role` aplikace dvojice. `read_only_mode`
   API galerie nekontroluje. Ve starším API `v1` kontroluje oprávnění jen
   16 z 82 kontrolerů — pro dvojici bez hostů to nevadí, s hostem ano.
6. ~~**Prostor se určuje jako „první" bez řazení**~~ — v API galerie hotovo (2d):
   výchozí, jinak nejstarší. Staré rozhraní (`gallerySpaces()->first()` na ~90
   místech) řazení nemá; pro dvojici s jedním prostorem to nevadí.
7. **Staré rozhraní `/prehled` (Inertia) pořád běží** — druhá plocha, kterou
   je potřeba udržovat a hlídat. Buď ho vypnout, nebo sjednotit oprávnění.
8. **`npm audit`**: postcss a nanoid (jen nástroje sestavení, ne běh aplikace).
   `npm audit fix` + `npm run build` a zkontrolovat `public/build`.
9. **Obnova ze zálohy nebyla ověřená** — `BACKUP_AND_RESTORE.md` popisuje
   postup; vyzkoušet na kopii databáze a Disku.
10. Vývojový přístupový klíč „mereni" v **lokální** databázi (produkce ne) —
    smazat v tinkeru: `DB::table('personal_access_tokens')->where('name', 'mereni')->delete()`.
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
- Automatické stahování transakcí a napojení banky. **Import výpisu do knihy
  galerie nemá** (staré rozhraní má jen `POST /api/banking/imports`) —
  platby se zapisují ručně nebo z pravidelných plateb; obrazovky to od 2e
  říkají, dřív tvrdily „zařazování běží při každém importu"
- Investice: nákup a rebalance (nová pozice u dvojice založí spořicí účet)
- Dvoufázové přihlášení se zapíná ve starém rozhraní — v galerii je jen jeho
  stav (zapnutí potřebuje QR kód ověřovací aplikace)

### Potřebuje návrh
- Hromadné vrácení celé historie změn (jednotlivé kroky z okna vrátit jde)
- Kompletní archiv originálů jedním souborem (dnes: originály na Google Disku,
  vybrané fotky jako ZIP z výběru; celé GB přes prohlížeč nejdou)
- Přepis hlasovek na text (aplikace ho nedělá a netvrdí to)
- Přidání osoby na fotku ručně (rozpoznávání tváří aplikace nemá)

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
