# Předprodukční audit

Stav k 6. 9. 2026. Co je opravené, co zbývá a co se úmyslně nechalo tak.

Metoda: nálezy se **ověřovaly útokem nebo v prohlížeči**, ne čtením kódu.
U každého je napsané, jak se pozná, že platí.

## Opraveno v tomhle průchodu

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

Ověřeno proti Open-Meteo s funkčním certifikátem (lokální PHP nemá nastavené
`curl.cainfo`, takže v tomhle prostředí neprojde žádné HTTPS z PHP; ověřovalo
se s dočasně podstrčeným CA bundlem). Vrátilo pět skutečných dní.

Hned to ukázalo chybu, kterou test s podvrženými daty neodhalil: **32 °C
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

## Ověřeno jako v pořádku

| Co | Jak se to ověřilo |
| --- | --- |
| Vzkaz od hosta nejde zneužít ke skriptu | uložený `<script>` i `<img onerror>` se vykreslí jako text; `window.__XSS` zůstalo `false`, v DOM žádný `img[onerror]` |
| Platební oznámení nevěří tomu, co přišlo | `settle()` se doptá brány; pole z požadavku samy o sobě nic nemění |
| Webhook Disku ověřuje kanál | porovnává `X-Goog-Channel-Token` s uloženým |
| `PUT storage/{path}` z frameworku | vyžaduje podepsanou adresu, jinak 404/403 |
| Náhledy mřížky | podepsaná adresa na jediný soubor a den, ne token v URL |
| Bezpečnostní hlavičky | `nosniff`, `SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, HSTS přes HTTPS |
| Tlačítka bez obsluhy | z 1264 zbývají 2 (ovládání falešného přehrávače videa) |
| Testy | 1058 zelených |

## Zbývá — seřazeno podle toho, co bolí nejvíc

### 1. Tabulky, ze kterých se čte a nikdo do nich nepíše

Obrazovka z nich kreslí a naplnit je jde jedině ručně v databázi. Je to táž
třída chyby jako `guest_comments` a `print_orders`, které se opravily.

| Tabulka | Kolekce | Obrazovka |
| --- | --- | --- |
| `couple_story_milestones` | `STORYMS` | Náš příběh — osa milníků |
| `house_week`, `house_week_capacity` | `HOUSE_WEEK` | Kapacita týdne |
| `wellbeing_tasks` | `KL_TASKS` | Čeká na okno |
| `watch_titles` | `ABARS.tier` | Filmy a seriály — žebříček |
| `drive_conflicts` | `CONFLICTS` | Rozpory mezi zařízeními |

`drive_conflicts` je z nich jiný případ: rozpory nevznikají zápisem člověka,
ale synchronizací — tu je má zakládat, a nedělá to. Ostatní čtyři potřebují
formulář nebo akci na obrazovce.

### 2. Sdílení odkazem se neukládá

Vytvoření a úprava sdíleného odkazu žije **jen ve stavu prohlížeče**
(`shareRows`). Endpoint `ShareController::store` existuje, ale prototyp ho
nevolá. Po odhlášení odkaz zmizí — a přepínač komentářů, který jsem napojil
na `shared_links.allow_comments`, se tím pádem nedá z prototypu zapnout.

Je to největší zbývající díra: obrazovka slibuje odkaz, který někomu pošlete.

### 3. Host svůj vzkaz neuvidí

`publicPayload` sdíleného odkazu komentáře nevrací, takže host napíše vzkaz
a už ho nikdy neuvidí. Dvojice ho vidí, což byl záměr, ale hostovi to bez
zpětné vazby připadá, jako by se nic nestalo.

### 4. Falešný přehrávač videa

Dvě tlačítka v prohlížeči fotky (přehrát, zvuk) nemají obsluhu, protože pod
nimi není `<video>`, jen obrázek na pozadí. Doplnit obsluhu nejde bez výměny
toho bloku za skutečný přehrávač.

### 5. Bez Content-Security-Policy

Hlavičky jsou jinak v pořádku, CSP chybí. Aplikace kreslí obsah od hostů
a načítá runtime z `unpkg.com`, takže politika by měla smysl — i jako pojistka
pro případ, že by někde escapování selhalo.

### 6. Service worker může po nasazení podávat starou verzi

Při ověřování se ukázalo, že prohlížeč držel starý dokument, dokud se
registrace service workera nezrušila a nevyprázdnila cache. Strategie v `sw.js`
je přitom „nejdřív síť" — takže to nejspíš dělal starší worker, který zůstal
u řízení stránky. Před nasazením stojí za to projít, jak se nová verze dostane
ke klientům, kteří mají tu starou.

### 7. Kontrola konfigurace před nasazením

`.env.example` má správné produkční hodnoty (`APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `LOG_LEVEL=warning`);
lokální `.env` má vývojové. Před nasazením zkontrolovat, že se použil ten
první.

### 8. Pint není na celém stromu

`vendor/bin/pint --test app/ tests/` hlásí většinu starších souborů. Nové
soubory formátované jsou; sjednotit zbytek je jednorázový průchod, který se
ale prolne s každou otevřenou větví, takže patří na klidnou chvíli.

## Co se nechalo úmyslně

| Kolekce | Proč |
| --- | --- |
| `LOCKMAIL`, `LOCKPIN`, `LOCKPWD`, `LOCKREC`, `LOCKWHO`, `VAULT_PWD` | přihlašovací záslepky prototypu — **daty se stát nesmí**, jsou to hesla napsaná v souboru |
| ~53 katalogů rozhraní | názvy obrazovek, měsíce, ikony, prázdné stavy — nejsou to data dvojice |
| `AMISS`, `CYC_TODAY`, `GRAF` | dopočítává si je dokument sám |
