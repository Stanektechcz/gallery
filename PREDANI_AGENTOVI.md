# Předání stavu systému — MAKI-GALLERY

Aktualizováno: 5. 8. 2026 · větev `main`, commit `6e94516`

Tenhle dokument je určený agentovi nebo vývojáři, který na projektu bude pokračovat.
Obsahuje ověřený stav, konvence, pasti a otevřené úkoly. **Ostatní plánovací dokumenty
v repozitáři jsou z poloviny července a na několika místech už neodpovídají skutečnosti** —
řiď se tímto a kódem, ne jimi.

---

## 1. Co systém je

Původně privátní galerie a plánovač pro dvojici, nyní rozšířený na SaaS. Primární cílová
skupina zůstávají **dvojice**; rodiny a skupiny jsou rozšíření podle tarifu.

**Stack:** Laravel 12 · PHP 8.5 (produkce) / 8.4 (lokálně) · Inertia + React 19 + TypeScript ·
Tailwind 4 · Vite 8 · SQLite lokálně, MySQL na produkci.

**Rozsah:** 78 stránek, 43 komponent, 63 API controllerů, 70 služeb, 67 modelů,
103 migrací, 20 jobů, 18 konzolových příkazů, 588 rout, 247 testů (všechny procházejí).

---

## 2. Zásadní omezení pro další práci

- **Bez investic.** Žádné placené externí služby (řečové API, OCR API, LLM API, druhé
  úložiště). Co nejde self-hostovat nebo napsat, se nedělá.
- **Na míru.** Preferuj vlastní implementaci před závislostí na cizí službě.
- **Testy se aktuálně nepožadují** u nové funkčnosti. **Existujících 247 ale musí dál
  procházet** — jsou jediná pojistka proti regresi.
- Uživatelské rozhraní i texty jsou **česky**. Komentáře v kódu anglicky.

---

## 3. Architektura, kterou musíš znát

### 3.1 Izolace prostorů (bezpečnostně kritické)

Každý zákazník = `GallerySpace`. Sloupec `gallery_space_id` je ve schématu na **123 místech**.

Trait `App\Models\Concerns\BelongsToGallerySpace` přidává **globální scope**, který dotazy
omezí na prostory přihlášeného uživatele. Nasazený je na `MediaItem`, `Album`, `Recipe`,
`CalendarEvent`, `EntertainmentTitle`, `VoiceNote`, `Burp`.

- Scope **nedělá nic, když není nikdo přihlášený** — konzole, fronty a seedery vidí vše.
- Provozní obrazovky ho zvedají vědomě: `Model::withoutGlobalScope(SpaceContext::SCOPE)`.
- `App\Support\SpaceContext` cachuje ID prostorů na request; po změně členství volej
  `SpaceContext::forget()`.

**Když přidáváš model se sloupcem `gallery_space_id`, nasaď na něj trait.** Historicky se
scoping psal ručně a jedno opomenutí byl skutečný únik dat (`CommentController` načítal
libovolnou fotku podle UUID).

### 3.2 Entitlementy — tři vrstvy

`App\Services\Billing\EntitlementService` je jediná autorita. Nemíchej vrstvy:

| vrstva | otázka | kdo rozhoduje |
|---|---|---|
| **entitled** | smí to prostor podle tarifu nebo doplňku | provozovatel přes matici |
| **enabled** | chce to zákazník vidět | zákazník |
| **active** | průnik obojího | na tomhle aplikace gatuje |

- `isEntitled()` — má nárok
- `hasFeature()` — má nárok **a** nemá to vypnuté
- `setFeaturePreference()` — předvolba zákazníka; **odemknout nikdy nemůže** (vrací 402)

**Middleware `module:<kód>` kontroluje `isEntitled()`, ne `hasFeature()`.** Záměrně: kdyby
zákazník schoval funkci z menu a URL mu vrátila 402, vypadalo by to jako ztráta dat.

Funkce označené `is_core` nejdou zamknout ani vypnout (dnes `gallery`, `search`).

### 3.3 Katalog a matice

- `features` — katalog toho, co systém umí (20 položek v 5 kategoriích)
- `billing_plan_feature`, `billing_module_feature` — co který tarif/doplněk odemyká
- `space_features` — předvolby zákazníka

Matici edituje provozovatel na `/admin/tarify`. **Změna platí okamžitě pro všechny
zákazníky na tom tarifu, nic není zadrátované v kódu.**

Tarify: `duo` (zdarma, výchozí) · `duo_plus` 149 Kč · `rodina` 249 Kč · `skupina` 499 Kč.
Doplňky: `burps` 49 Kč. Ceny jsou v **haléřích** (minor units).

### 3.4 Platby — Comgate

`App\Services\Billing\ComgateGateway` + `CheckoutService`.

**Nárok uděluje výhradně serverová notifikace** (`POST /platby/comgate/notifikace`), a to
až po zpětném dotazu na stav transakce. Odeslaná pole ani návratová URL v prohlížeči se
neberou jako důkaz platby. Vypořádání je idempotentní, Comgate notifikace opakuje.

Notifikace je vyjmutá z CSRF v `bootstrap/app.php`.

Údaje z prostředí, výchozí je sandbox:

```
COMGATE_MERCHANT=
COMGATE_SECRET=
COMGATE_TEST=true
```

Bez nich vrací 503 se srozumitelnou hláškou.

---

## 4. Otevřené úkoly, seřazené podle naléhavosti

### 4.1 Produkce je čtyři commity pozadu

Server naposledy hlásil `977262a`. Nenasazené:

| commit | co přináší |
|---|---|
| `f6813da` | hlasovky, krkanci, entitlementová vrstva |
| `abd17f0` | **uzavření úniku dat mezi prostory**, registrace, limity |
| `a8eba96` | onboarding, oprava limitů ořezávání |
| `6e94516` | katalog funkcí, Comgate, landing page |

**Dokud se nenasadí, běží na produkci verze s únikem dat** — přihlášený uživatel může přes
UUID číst komentáře k cizí fotce.

### 4.2 Neobjasněný pád při vkládání receptu

Chat vrací HTTP 500 při vložení dlouhého receptu. **Příčinu neznám.** Vyloučeno měřením:

- parser recept zvládne (test `AssistantRealRecipeTest` s plnou verzí prochází)
- všechny hodnoty se vejdou do sloupců (163 kontrol)
- žádné volání TMDB se nespouští (0 detekovaných titulů)
- validace by vrátila 422, ne 500

Nejpravděpodobnější zbývá **stará OPcache po nedokončeném nasazení** — v `35ed3c5` se
měnil konstruktor `WorkspaceAssistantController`. Rozhodne serverový log:

```bash
tail -n 200 /www/wwwroot/gallery.stanektech.cz/storage/logs/laravel.log
```

### 4.3 Comgate není ověřený proti reálné bráně

Integrace je psaná podle znalosti API, ne proti dokumentaci ani testovacímu účtu.
Struktura je správná (form-encoded, `prepareOnly`, `refId`, notifikace vrací `code=0`),
ale **první ostrý test v sandboxu udělej dřív, než to uvidí zákazníci.** Nejpravděpodobnější
odchylka je přesná sada polí v `create`.

### 4.4 Chybí právní část

Obchodní podmínky, GDPR, zpracovatelské smlouvy, odstoupení od smlouvy. S reálnými
platbami už to není odkladatelné. Není to programátorská práce.

### 4.5 Nemáte měření spotřeby

Žádná evidence, kolik který prostor spotřebuje výpočtu nebo API volání. Než vznikne první
modul s nákladem za použití, je potřeba to postavit — jednou, pro všechny.

---

## 5. Provozní prostředí

```
Server:     vmi2254765, aaPanel
Cesta:      /www/wwwroot/gallery.stanektech.cz
PHP:        /www/server/php/85/bin/php   (8.5)
Web:        https://gallery.stanektech.cz
Uživatel:   www
Repozitář:  https://github.com/Stanektechcz/gallery
```

### 5.1 Nasazení

**Skript nesmí obsahovat `git pull`? Obsahovat MUSÍ.** Původní deploy skript ho neměl a
opakovaně se stávalo, že se jen přebuildoval starý kód.

```bash
cd /www/wwwroot/gallery.stanektech.cz && git fetch origin && git checkout -f -B main origin/main
```

Pak migrace, seeder a cache:

```bash
cd /www/wwwroot/gallery.stanektech.cz && P=/www/server/php/85/bin/php && $P artisan migrate --force && $P artisan db:seed --class=BillingCatalogSeeder --force && $P artisan optimize:clear && $P artisan config:cache && $P artisan route:cache && $P artisan view:cache && $P artisan queue:restart && chown -R www:www storage bootstrap/cache
```

### 5.2 Pasti při nasazení

- **`public/build` je verzovaný v gitu** a zároveň se na serveru přebuildovává. Vite tam
  generuje jiné hashe než lokálně, takže je strom po nasazení vždycky špinavý —
  `git checkout -f` je nutnost, ne volba.
- **`chown -R www:www storage bootstrap/cache` nevynechávej.** Artisan běží jako root;
  bez srovnání vlastnictví hrozí 500, protože PHP-FPM zapisuje do `storage/framework/views`.
- **Nové routy vyžadují `route:cache`**, jinak vracejí 404.
- **OPcache.** Pokud má `validate_timestamps=0`, nový PHP kód se neprojeví bez reloadu
  PHP-FPM. Příznak: routy jsou v `route:list`, ale přes HTTP dál 404 nebo stará logika.
- **Nevkládej dlouhé skripty do terminálu.** Jednou se to takhle rozsypalo v půlce a
  proběhla jen část. Ulož do souboru přes `nano` a spusť.

### 5.3 Provozní závislosti

- `routes/console.php` registruje **10 plánovaných úloh**. Vyžadují systémový cron volající
  `schedule:run`. **Ověř, že běží.**
- Fronta: 20 jobů. Vyžaduje běžícího queue workera.
- Registrace je **vypnutá**, dokud není `GALLERY_REGISTRATION_OPEN=true`.

---

## 6. Ověřovací postup

Před každým commitem:

```bash
npx tsc --noEmit
```

```bash
php artisan test
```

```bash
npm run build
```

Lokální PHP je na `C:\php\php.exe` (Windows), na serveru `/www/server/php/85/bin/php`.

---

## 7. Pasti v kódu, na které jsme narazili

- **Testy běží na SQLite, produkce na MySQL.** SQLite **nevynucuje délky `varchar`**.
  Hodnota delší než sloupec projde lokálně a na produkci je „Data too long" → 500.
  Při ukládání textu z uživatelského vstupu ořezávej na skutečnou šířku sloupce; vzor je
  v `WorkspaceAssistantController` jako pojmenované konstanty s uvedeným sloupcem.
- **JSON serializace čísel.** `4.00` z databáze přijde v JSON jako `4`. V testech porovnávej
  hodnotou, ne typem.
- **`Inertia::render('X')` bez souboru** `resources/js/Pages/X.tsx` vrátí HTTP 200 a
  **prázdnou stránku** — server nic neví. Hlídá to `InertiaPageComponentsExistTest`.
- **Kalendář auto-uzavírá proběhlé akce.** `completeElapsedPlans()` přepíše proběhlé na
  `completed`; dotaz na index proto skrývá **jen `cancelled`**, ne `completed`, jinak
  zmizí historie.
- **PowerShell komolí backslashe.** Na úpravy PHP namespace používej editor, ne `sed`.

---

## 8. Moduly jako příplatkové služby — co je při „bez investic" reálné

Analýza deseti návrhů po zpřísnění na vlastní implementaci bez placených služeb:

**Postavitelné vlastními silami:**

- **OCR dokladů** — `tesseract` je zdarma a self-hostovatelný, český jazykový balík
  existuje. Nejreálnější z celé desítky.
- **Přepis hlasovek** — `whisper.cpp` s malým modelem na CPU. Pomalé, ale na krátké
  vzkazy použitelné. Zvuk, joby i fronta existují.
- **Vzkazy do budoucna** — čistě v kódu. Doručení **jen e-mailem**, Web Push nemá VAPID.
- **Chytřejší asistent** — dnes je to regexový parser, žádný LLM. Zlepšovat parser jde
  bez nákladů; skutečný LLM by znamenal investici.

**Odpadá při omezení „bez investic":**

- Druhá kopie zálohy (potřebuje placené úložiště)
- AI asistent s LLM (tokeny stojí peníze)
- Živá doprava (licencovaná data, pravděpodobně nedostupná za rozumnou cenu)

**Blokované nezávisle na penězích:**

- **Bankovní vyrovnání** — provozovat službu, kde zákazníci připojují své banky, je podle
  PSD2 služba informování o účtu a vyžaduje licenci AISP nebo provoz pod licencí
  poskytovatele. **Nejtěžší regulační překážka z celé desítky.**
- **Rozpoznání obličejů** — biometrika podle čl. 9 GDPR: výslovný souhlas, posouzení vlivu,
  mazání modelů.
- **Tištěná ročenka** — tisk, doprava a reklamace jsou jiný byznys než software.

**Poznámka k úložišti:** `StorageConnection` má `owner_user_id` — originály leží na
**zákazníkově vlastním Google Drivu**, ne na vašem. Prodej „dalších GB" je proto cenová
páka, ne krytí nákladů.

---

## 9. Kde co hledat

```
app/Services/Billing/          entitlementy, Comgate, checkout
app/Models/Concerns/           trait pro izolaci prostorů
app/Support/SpaceContext.php   cache prostorů pro globální scope
app/Http/Middleware/           EnsureModuleEnabled (gating), HandleInertiaRequests (sdílené props)
routes/console.php             plánované úlohy
database/seeders/BillingCatalogSeeder.php   katalog funkcí a tarifů
resources/js/Pages/Landing/    veřejná prezentace služby
resources/js/Pages/Admin/PlanMatrix.tsx     matice tarif × funkce
```

Navigace v `AppLayout.tsx` skrývá položky podle `feature` v `NavigationItem`. Seznam
aktivních funkcí posílá `HandleInertiaRequests` jako sdílenou prop `features`;
**`null` znamená „katalog ještě není migrovaný" a neskrývá nic.**
