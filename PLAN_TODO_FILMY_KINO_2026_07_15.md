# Společné úkoly a filmový plán

## Výsledek

Obě funkce jsou součástí existujícího **Společného plánování** na `/planning`; nevznikla další hlavní záložka. Úkoly, filmové večery a kino používají stejný společný prostor, uživatele, kalendář, připomínky, vyhledávání a partnerský přehled jako zbytek aplikace.

## Společné úkoly

- tematické seznamy (domov, nákup, cesta, rande, administrativa a vlastní seznamy),
- rychlé zadání, popis, priorita, odpovědná osoba, termín, odhad času a místo,
- samostatná připomínka nebo vytvoření společné kalendářové události,
- denní, týdenní, měsíční a roční opakování; další výskyt vznikne až po dokončení,
- podúkoly, komentáře, závislosti a označení blokovaného úkolu,
- filtrování aktivních, dokončených, vlastních a nepřiřazených úkolů,
- notifikace při přiřazení, komentáři a termínu,
- úkoly se zobrazují a dají dokončit i v Partnerském pulsu a na dashboardu,
- globální vyhledávání vede přímo do sekce úkolů,
- oddělení oprávnění společného prostoru a respektování režimu pouze pro čtení.

## Filmy a seriály

- společná fronta filmů a seriálů, ruční zadání funguje vždy,
- český globální našeptávač TMDB s plakátem, anotací, rokem, stopáží, žánry a trailerem,
- individuální zájem 1–5 a společné průměrné skóre,
- stavy navrženo, užší výběr, naplánováno, rozkoukáno, zhlédnuto, pozastaveno a opuštěno,
- návrh volných večerů podle kolizí ve společném kalendáři,
- více návrhů termínu a odpověď každého partnera „mohu / možná / nemohu“,
- potvrzený termín vytvoří společnou kalendářovou událost se dvěma připomínkami,
- záznam zhlédnutí, hodnocení a u seriálu postup po řadách a dílech,
- rezervace zůstává na oficiálním webu provozovatele a její URL se uloží k události,
- globální vyhledávání a příkazová nabídka vedou přímo do filmového plánu.

## Cinema City Velký Špalíček

Synchronizace čte veřejný JSON program, který používá oficiální stránka Cinema City. Stahuje maximálně 14 dní, ukládá pouze program, projekce, jazyk/format, dostupnost a oficiální rezervační odkaz. Nepřihlašuje se, neobchází ochrany webu a neukládá zákaznické údaje.

- ruční obnova: `php artisan gallery:sync-cinema --days=10`
- automatická obnova: denně v 06:15 přes Laravel Scheduler
- souběžná synchronizace je uzamčena a poslední úspěch/chyba jsou evidovány
- při výpadku zdroje zůstane poslední uložený program dostupný

## Konfigurace TMDB

V **Administrace → Integrace dat** je nová položka „TMDB · filmy a seriály“ s odkazem na registraci a dokumentaci. Klíč se ukládá šifrovaně a nikdy se neposílá do prohlížeče. Po vložení `api_key` integraci aktivujte a použijte test připojení.

Bez TMDB klíče zůstává plně funkční ruční seznam, hlasování, termíny, kalendář, kino i historie zhlédnutí.

## Nasazení

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
php artisan gallery:sync-cinema --days=10
```

Na serveru musí každou minutu běžet `php artisan schedule:run`; ten obsluhuje připomínky úkolů i denní program kina.

## Ověření

- nové integrační scénáře: 4 testy / 49 kontrol,
- celá backendová sada: 164 testů / 1 418 kontrol,
- produkční Vite/PWA sestavení: úspěšné.
