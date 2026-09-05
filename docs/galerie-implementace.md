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

### 8. Doručení
- [ ] Blade rozvržení, `window.GALERIE_API_BASE`, CSRF
- [ ] `sw.js` z kořene se `Service-Worker-Allowed: /`
- [ ] Statické soubory prototypu do `public/`
- [ ] Ověření: prototyp běží proti serveru bez jediné změny v `.dc.html`

## Hotovo je, když

- Oba účty vidí tentýž stav.
- Offline zápis dorazí po obnovení signálu.
- Otisk projde přes serverovou challenge.
- V administraci se každý zásah objeví v protokolu.
- V `.dc.html` se nezměnil jediný řádek.
