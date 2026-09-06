# Napojení prototypu Galerie na backend

Plán práce. Prototyp v ZIPu se nemění — mění se jen backend pod ním.

## Co prototyp je

| Část | Rozsah |
| --- | --- |
| Rozvržení | `Galerie.dc.html` (široké, 2 MB), `Galerie mobil aplikace.dc.html` (telefonní, 816 kB) |
| Obrazovky | 44 v katalogu `APP` (`x-*`) + 10 pohledů knihovny = 45 |
| Záložky | 135 v katalogu + záložky Nastavení = 149 |
| Navigace | 56 položek v 9 skupinách (`NAV_GROUPS`) |
| Data | 190 kolekcí v `galerie-data.js` |
| Mechanismy | 24 definic (`mechanismy.json`) + sdílená logika |
| Administrace | 6 záložek: uživatelé, zdraví, úlohy, riziko úložiště, klíče, tarify |
| PWA | manifest, service worker (fronta v IndexedDB + Background Sync), push, offline |
| Scaffold | 1 170 řádků PHP: stav, token, WebAuthn, média, push, mechanismy, 3 úlohy, 5 migrací |

## Dvě architektonická rozhodnutí

**1. Pár = prostor galerie.** Scaffold chce `users.couple_id`. Aplikace už identitu
páru má — `gallery_spaces`. Zavést druhou by znamenalo dvě pravdy o tomtéž. `couple_id`
se proto odvozuje z prostoru uživatele, nová sloupec nevzniká.

**2. Média jdou do existující tabulky.** Scaffold zakládá vlastní `media_items`, jenže
ta v aplikaci už je — a je bohatší (`sha256`, `size_bytes`, `taken_at`, EXIF, perceptuální
otisk). Druhá tabulka fotek by znamenala dva sklady téhož a rozejít se můžou už první den.
Prototypový `MediaController` proto zapisuje do existující.

Zbytek scaffoldu jde do aplikace tak, jak je: jeden JSON dokument na pár (`couple_states`)
s částečnými patchi a `rev`. README prototypu to zdůvodňuje a určuje i pořadí, ve kterém
se klíče později vytahují do vlastních tabulek.

## Úkoly

### 1. Základ — hotovo
- [x] `config/galerie.php`, `routes/galerie.php`, registrace bez prefixu
- [x] Migrace: `couple_states`, `webauthn_credentials`
      (`push_subscriptions` ne — tabulka v aplikaci už je, viz kapitola 6)
- [x] Model `CoupleState` (otevřený + šifrovaný sloupec, `applyPatch`, `rev`)
- [x] `StateController`: GET / PATCH / DELETE, konflikt `409` s aktuálním stavem
- [x] Testy: sloučení po klíčích, konflikt, šifrované klíče, izolace mezi páry (10)

### 2. Přihlášení — hotovo
- [x] `TokenController`: `POST /sanctum/token`, `POST /api/logout`
- [x] Jedno zařízení = jeden token (nové přihlášení ruší staré)
- [x] Odhlášení ruší token **i sezení** — routy běží ve skupině `web`
- [x] Testy: platné, neplatné, stejná hláška pro neznámý e-mail, dvě zařízení (7)

### 3. Otisk (WebAuthn) — hotovo
- [x] `web-auth/webauthn-lib` **5.3** (README chce 4.7, ta neumí `symfony/uid` v8)
- [x] 4 endpointy; challenge registrace v cache pod účtem, challenge přihlášení
      v sezení (před přihlášením není podle čeho jiného ceremonii poznat)
- [x] Kontrolu `sign_count` dělá knihovna (`ThrowExceptionIfInvalid`) — vlastní
      kontrola navíc by byla mrtvý kód
- [x] `GALERIE_RP_ORIGINS` — bez seznamu originů knihovna trvá na HTTPS a otisk
      by se na vývojovém serveru nedal ani vyzkoušet
- [x] `/login/options` neprozradí existenci e-mailu
- [x] Testy (16) proti **skutečně podepsaným** odpovědím — `FalesnyAutentikator`
      skládá CBOR a podepisuje ES256, takže projde i podvržený origin, klesající
      počítadlo, přehraná challenge a změněný podpis

### 4. Média — hotovo
- [x] `MediaController` nad existující `media_items` + variantou `original`
      (fotka z prototypu se tím objeví i v časové ose a v koši)
- [x] Deduplikace podle `sha256`, části po 8 MB skládané **proudem**, koš 30 dní
- [x] Limit tarifu platí i tady — jinak by se přes prototyp dal obejít
- [x] Náhledy a metadata do fronty (`media`), aby nahrávání skončilo hned
- [x] `GET /api/media/{id}/raw` přes aplikaci, ne z `public`
- [x] `POST /share-target` — v aplikaci **už je** (`routes/web.php`), manifest
      aplikace ho má metodou POST; přepnout zbývá jen manifest prototypu (kap. 8)
- [x] Testy (13): duplicita, soubor z koše jde znovu, části v pořadí, podvržený
      identifikátor i cesta ve jménu, cizí pár nedostane cizí soubor, tarif

### 5. Mechanismy — hotovo
- [x] `MechanismController` + `resources/galerie/mechanismy.json` (24 klíčů)
- [x] 503 při chybějícím souboru — prázdná odpověď by klientovi smazala všechny
      mechanismy najednou
- [x] Cache podle času změny souboru + `ETag` → podruhé 304, `private` cache
- [x] Seeder zakládá **prázdný** stav pro každý prostor. 180 kolekcí
      z `galerie-data.json` se do stavu **nelije** — jsou to výchozí hodnoty
      klienta a jejich zamrznutí do stavu by znamenalo, že pozdější změna
      výchozích čísel dvojici navždy mine. Soubor navíc obsahuje heslo a PINy
      v čitelné podobě; účty zakládá `GallerySpaceSeeder` s náhodným heslem.
- [x] Testy (8): 24 klíčů, 503, poškozený soubor, 304, `private`, seeder dvakrát

### 6. Upozornění a úlohy — hotovo
- [x] `POST` / `DELETE /api/push/subscribe` míří na **existující** kontroler
      odběrů. Druhá implementace téhož by byla druhé místo, kde se dá zapomenout
      smazat odběr odhlášeného zařízení; tělo požadavku je shodné.
- [x] Klíče VAPID z `config/push.php` — druhá dvojice by znamenala, že prohlížeč
      odběr registrovaný na jeden klíč u druhého odmítne
- [x] `galerie:expire` (03:10, **tichá**) — scaffold tu snižoval čítač `expDays`,
      jenže takový klíč prototyp nemá. Skutečné klíče jsou `expRen`, `expEt`
      a `expDead` a zbývající dny se počítají z data vzniku; příkaz počítá stejně
      jako `expVals()` v `galerie-mechanismy-logika.js`. Běh, který nic nezmění,
      nezvedne `rev` — jinak by otevřená aplikace dostala po půlnoci 409.
- [x] `galerie:notify` (18:00) — jediné upozornění, jen `decs` se stavem
      „k revizi"; ukázková data klienta se neposílají
- [x] `gallery:purge-trash` (04:20) — aplikace `purge_after` dosud jen zapisovala
      a nikdo podle něj neuklízel: smazaná fotka zůstávala na disku i v součtu
      úložiště navždy
- [x] Testy (17): odběr se neduplikuje, vypršení po lhůtě i obnovené a trvalé
      domluvy, druhý běh nezvedne `rev`, koš maže až po lhůtě a nasucho nemaže

### 7. Administrace — hotovo
Prototyp měl administraci celou na klientovi: účty, úlohy, klíče i tarify byly
ve `GalerieData.ADMIN` vymyšlené a tlačítka měnila jen stav v prohlížeči.
`GET /api/admin` teď vrací tytéž klíče ze skutečných dat a dvanáct dalších cest
provádí skutečné akce. Každá odpověď vrací **celý přehled znovu**, takže se
obrazovka překresluje z databáze, ne z toho, co si klient myslí.

- [x] Účty ze členů prostoru; vlastník právě jeden a jeho role se necykluje —
      mění se jen předáním, po kterém je z předchozího správce
- [x] Odebrání přístupu ruší i tokeny: bez toho by telefon s uloženým přihlášením
      chodil dál, a to je přesně ta věc, kvůli které se tlačítko mačká
- [x] Pozvánka zakládá **i členství** — dosud vznikl účet, který se po přijetí
      přihlásil do aplikace bez jediné galerie
- [x] Klíče k API = tokeny Sanctum. Celý klíč jednou, pak čtyři znaky (nový
      sloupec `suffix`); zrušený klíč **zůstává v seznamu** s prošlou platností,
      jinak by po kliknutí zmizel řádek a nikdo by nezjistil, který to byl
- [x] Úlohy se čtou z `routes/console.php`, ne z druhé tabulky — přidaná úloha by
      v administraci jinak chyběla a zrušená by tam zůstala navždy. Pozastavení
      platí přes jeden `->skip()` pro celý plán.
- [x] Nová tabulka `scheduled_task_runs` + posluchač: aplikace o plánovači dosud
      věděla jedinou věc, že tepe. „Poslední běh" a „incidenty za 30 dní" by bez
      ní byla vymyšlená čísla.
- [x] Ruční spuštění jde do fronty a spouští **úlohu z plánu**, ne příkaz
      z požadavku — jinak by tlačítko bylo cestou, jak přes API spustit cokoli
- [x] Tarify: placený se **nepřiděluje, kupuje se**. Kdyby backend bral tlačítko
      doslova, dostala by dvojice úložiště, které nezaplatila.
- [x] Riziko úložiště počítané ze skutečných dat; „vysypat koš" posune lhůtu do
      minulosti a spustí úklid
- [x] Protokol se jménem přihlášeného, jen zásahy vlastního prostoru
- [x] Testy (28)

### 8. Doručení — hotovo
- [x] Prototyp je na `/`. Dosavadní rozcestník se přestěhoval na `/prehled`
      a **jméno routy `dashboard` zůstalo**, takže odkazy ve starém rozhraní drží.
- [x] Napojení (`GALERIE_API_BASE`, CSRF, klíč VAPID, registrace workera) se
      vkládá **při odeslání**, ne do souborů — další verze prototypu se dá jen
      přepsat, místo ručního slučování
- [x] `sw.js` se podává z `resources/`, ne z `public/`: statický soubor by web
      server vydal dřív, než požadavek dojde do PHP, a bez
      `Service-Worker-Allowed: /` by worker neviděl `/api/` — fronta offline
      zápisů by tiše nefungovala
- [x] Worker ze ZIPu počítá se statickým hostem a po kliknutí na upozornění
      otevíral `Galerie mobil aplikace.dc.html`. Na serveru je aplikace na `/`,
      takže by kliknutí skončilo na neexistující adrese; opravuje se to při
      odeslání, soubor zůstává nedotčený.
- [x] Administrace: server data se **přimíchávají při čtení**, ne přiřazují.
      Prostý zápis do `GalerieData.ADMIN` vydržel jen do chvíle, než runtime
      načetl `galerie-data.js` znovu — a podle načasování to jednou vyšlo
      a jindy ne.
- [x] Rychlost: dokument chodí zabalený (2 047 648 → 402 254 B, −80 %),
      `preconnect` na unpkg a fonty, `preload` na největší soubory, a routa
      běží **bez Inertia middleware** — sdílená data by se počítala pro nic
- [x] Ověřeno v prohlížeči proti běžícímu serveru: přihlášení heslem → token →
      kód → aplikace; zápis stavu tam a zpět; **zápis s vypnutým serverem
      skončil ve frontě workera (202) a doručil se, jakmile server naběhl**
- [x] Testy (16 doručení + 2 PWA přepsané)

- [x] Dotazování na změny partnera. Klient stav načte jednou při startu a pak už
      jen posílá vlastní změny; kontrakt na to má nepovinný SSE proud, jenže ten
      by znamenal držet PHP proces pro každou otevřenou kartu a klient pro něj
      nemá kód. Dotaz jednou za dvacet vteřin je levnější a stačí — neptá se,
      když je karta schovaná ani když čeká vlastní zápis.

## Kontrola shody s prototypem

Projeté proti tomu, co prototyp **skutečně volá a čte**, ne proti README:
11 adres, tvary všech odpovědí, všechna pole administrace, 24 klíčů mechanismů,
soubory skořápky service workera, klíče stavu.

Pět neshod, dvě z nich tiché:

1. **Zápis tokenem padal na CSRF (419).** Klient prototypu ani nativní klient
   z README token proti CSRF neposílají; v prohlížeči to procházelo jen za
   hlavičkou `Sec-Fetch-Site`. Nejhůř u offline fronty — service worker
   přehrával zápis s uloženým, po vypršení sezení neplatným tokenem donekonečna.
2. **Prázdný stav chodil jako `[]` místo `{}`.** `JSON.stringify` u pole ukládá
   jen číselné indexy, takže první uložení v prohlížeči zahodilo všechno, co do
   stavu mezitím přibylo — a stalo se to jen novému páru.
3. **Heslo k trezoru se ukládalo na server.** `vaultPwd` chybí v seznamu, který
   si klient nechává pro sebe. Server teď hesla a kódy zahazuje sám.
4. **Administrace se po prvním kliknutí rozešla s databází.** Prototyp ji celou
   drží v komponentě a posílá do `/api/state`; na `/api/admin` nesáhne. Záměr se
   z patche přečte a provede; do stavu se ukládá skutečnost.
5. **Čtení `/api/admin` zvedalo `rev`,** takže otevřená aplikace dostala při svém
   dalším uložení konflikt.

## Administrace: přepsaná akční vrstva

`public/galerie-admin.js` je jediný soubor prototypu, do kterého se sáhlo — se
souhlasem a jen v akční vrstvě. Tvar obrazovky ani texty se nemění; mění se, odkud
data přicházejí a co tlačítka dělají.

**Bylo:** administrace byla místní model. Tlačítka zapisovala do stavu komponenty,
čísla u rizik a zdraví byla napsaná v kódu (8,1 GB / 1,2 GB / 2,4 GB měsíčně,
„dostupnost 99,98 %, disk 57 %") a celý klíč k API se skládal na klientovi
(`'gal_' + id + suffix`), takže tlačítko „Kopírovat" podávalo řetězec, který nikde
neplatil.

**Je:** každé tlačítko volá `/api/admin/*`, server zásah provede a v odpovědi vrátí
celý přehled; ten se uloží do `GalerieData.ADMIN` a obrazovka se překreslí z něj.
Ve stavu komponenty nezůstává z administrace nic — jedna pravda, jedno místo.

| Akce | Kam jde | Ověřeno v prohlížeči |
| --- | --- | --- |
| Pozvat účet | `POST /api/admin/users` | ano — limit tarifu odmítl a hláška se ukázala |
| Změnit roli | `PATCH …/users/{id}/role` | ano — role v databázi se změnila |
| Předat vlastnictví | `POST …/users/{id}/transfer` | ano (test) |
| Odebrat/obnovit přístup | `POST …/users/{id}/access` | ano — účet zneaktivněn, tokeny zrušeny |
| Poslat pozvánku znovu | `POST …/users/{id}/resend` | ano (test) |
| Vytvořit klíč | `POST …/keys` | ano — **klíč z obrazovky otevřel API (200)** |
| Vygenerovat znovu / zrušit | `POST …/keys/{id}/regenerate`, `DELETE …/keys/{id}` | ano |
| Spustit úlohu | `POST …/jobs/{id}/run` | ano (test) |
| Pozastavit / obnovit úlohu | `POST …/jobs/{id}/pause` | ano — `schedule.paused` se změnil |
| Kontrola systému | `POST …/health/check` | ano — zařadil `gallery:doctor` |
| Vyřešit riziko | `POST …/risks/{id}/fix` | ano |
| Změnit tarif | `POST …/plan` | ano — placený založil platbu, ne předplatné |

Klíč se ukáže **jednou**: server ho drží jen jako otisk, takže podruhé ho nemá odkud
vzít. Čerstvý klíč se drží v paměti do odchodu z obrazovky a rovnou se kopíruje do
schránky; u starších řádků „Kopírovat" přizná, že celý klíč k dispozici není, místo
aby podalo neplatný.

Bez backendu (`GalerieApi.mode !== 'http'`) tlačítka řeknou, že administrace
potřebuje server. Předstírat úspěch je horší než přiznat, že server není.

Vynucené rozvržení má **vlastní cestu** (`/rozvrzeni-telefon`, `/rozvrzeni-siroke`),
ne parametr v dotazu: service worker prototypu ukládá dokument pod adresu bez dotazu
a čte ji s `ignoreSearch`, takže jedno otevření `/?rozvrzeni=telefon` přepsalo
uloženou kopii `/` a prohlížeč pak i na počítači nabízel telefonní verzi.

Cesta je **jednosegmentová**, a to schválně. S `/rozvrzeni/telefon` mířily relativní
adresy skriptů (`galerie-api.js`) do neexistujícího `/rozvrzeni/`, takže telefonní
dokument se vůbec nespustil a zůstal viset na nevyplněných `{{ }}`.

## Postranní panel

`GET /api/storage` — vlastní adresa, ne součást `/api/admin`: panel vidí na každé
obrazovce každý, kdežto do administrace smí jen vlastník a správce a její přehled
je mnohem dražší na spočítání.

**Když je připojený Google Disk, počítá se podle něj.** Je to totiž jeho místo,
které dojde: tarif hlídá, co si dvojice smí uložit, ale zaplnit se může dřív účet
na Disku, a to by z čísel podle tarifu nikdo nepoznal. Bez Disku se počítá podle
tarifu; účet bez limitu (firemní Workspace) ukáže jen objem, protože pruh by u něj
byl vždycky na nule.

Kvótu z Disku dosud **nikdo neobnovoval** — zapsala se při připojení účtu a od té
chvíle ukazovala stav toho dne. Starší než půl hodiny se teď zařadí k obnovení do
fronty, aby se na Google nečekalo při vykreslení stránky.

„Druhá kopie" říká, kolik originálů ještě není v cloudu. Bez připojeného Disku se
kopírovat nemá kam, takže se to řekne rovnou — mlčet by znamenalo tvrdit, že je
všechno zálohované.

Kapacita se počítá v **desítkových** gigabajtech, stejně jako v záložce Tarify a na
Google Disku. `EntitlementService` násobí megabajty 1024×1024, takže tarif „25 GB"
by v panelu vyšel na 24,4 GB — dvě různá čísla pro totéž místo jsou horší než jedno
nepřesné.

V designových souborech se změnily tři řádky: `storagePct`, `storageLabel` a
`syncLabel` teď berou hodnotu z `GalerieData.STORAGE`, a když odpověď nedorazí,
zůstanou původní ukázková čísla, ať panel nezůstane prázdný. Nic jiného —
ani značky, ani styly, ani texty.

## Co zůstává na prototypu

Runtime prototypu si React a Babel bere z unpkg a šablonu (2 MB) překládá až
v prohlížeči. První vykreslení je proto v řádu sekund a backend s tím nic nesvede
— zabalení dokumentu a předpřipojení jsou strop toho, co jde udělat, aniž by se
do prototypu sáhlo. Kdyby na tom mělo záležet, jediná skutečná cesta je šablonu
přeložit předem při nasazení (a tím prototyp přestat brát jako zdroj pravdy).

## Hotovo je, když

- Oba účty vidí tentýž stav.
- Offline zápis dorazí po obnovení signálu.
- Otisk projde přes serverovou challenge.
- V administraci se každý zásah objeví v protokolu.
- V `.dc.html` se nezměnil jediný řádek.
