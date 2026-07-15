# Revolut společný účet — implementace a nasazení

## Výsledek

Bankovní data nejsou samostatný modul. Stejná historie se propisuje do plánu cesty, skutečného rozpočtu, low-cost limitů, kalendářní připravenosti a dashboardu. Aplikace pracuje výhradně v režimu pouze pro čtení a nemá žádnou cestu k vytvoření platby.

Podporované zdroje:

1. GoCardless Bank Account Data (PSD2) pro automatické načítání Revolutu.
2. Revolut CSV a XLSX výpis pro prvotní historii, zálohu nebo provoz bez API.

## Co se trvale ukládá

- bankovní připojení, stav a konec souhlasu;
- společné účty, maskovaný IBAN a aktuální/volný zůstatek;
- časové snapshoty zůstatků;
- zaúčtované i čekající transakce a importní původ;
- stabilní otisky pro deduplikaci API i překrývajících se výpisů;
- automatická a ruční kategorie;
- vazba transakce na cestu, bod itineráře, místo a skutečný cestovní výdaj;
- stav před cestou, po cestě, denní průběh, vratky, poplatky a obchodníci;
- audit připojení, synchronizace, importu, odpojení a ručních změn.

Externí ID účtu/transakce, jméno majitele, obchodník, protistrana, popis a kompletní odpověď poskytovatele jsou v databázi šifrované pomocí `APP_KEY`. IBAN se ukládá jen jako poslední čtyři znaky. Odpojení zastaví synchronizaci, pokusí se odvolat souhlas u poskytovatele a zachová již získanou historii.

## Automatické párování

- platby během cesty mají nejvyšší jistotu;
- ubytování, doprava, pojištění a aktivity lze přiřadit až 45 dní před odjezdem;
- doplatky dopravy/ubytování lze navrhnout sedm dní po návratu;
- názvy se porovnávají s itinerářem, místy a uloženými rezervacemi;
- vlastní převody nevytvářejí cestovní výdaj;
- vratky zůstávají samostatně a snižují skutečný dopad cesty;
- nejisté platby čekají na potvrzení a do rozpočtu nevstoupí;
- ruční potvrzení, vyřazení, rozdělená částka a kategorie se další synchronizací nepřepíší.

V administraci lze přidat pravidla podle obchodníka, protistrany, popisu nebo typu: pouze navrhnout, automaticky zahrnout, nebo z cest úplně vyloučit.

## Aktivace

1. Vytvořit přístup na <https://bankaccountdata.gocardless.com/> a získat `secret_id` a `secret_key`.
2. V **Administrace → Integrace** otevřít „GoCardless · Revolut PSD2“, uložit oba klíče, aktivovat a spustit test.
3. Ve stejné stránce nebo přímo v plánu cesty zvolit **Najít Revolut** a dokončit souhlas na stránce banky.
4. Pro starší historii lze kdykoliv nahrát výpis vyexportovaný z Revolutu. Opakované soubory se nezdvojí.

Produkční `APP_URL` musí být veřejná HTTPS adresa, protože z něj vzniká návratová URL `/banking/callback`. Přímé Revolut Business API se pro osobní společný účet nepoužívá.

## Provoz serveru

```bash
cd /www/wwwroot/gallery.stanektech.cz
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
php artisan gallery:sync-banking
php artisan schedule:list
```

Cron musí spouštět Laravel scheduler každou minutu. Scheduler zařazuje bankovní synchronizaci každých šest hodin; úlohy mají unikátní zámek, tři bezpečné pokusy a nepřekrývají se. Queue worker musí zůstat spuštěný přes Supervisor/systemd.

## Obnova a diagnostika

- Ruční synchronizace všech připojení: `php artisan gallery:sync-banking`.
- Jedno připojení: `php artisan gallery:sync-banking --connection=ID`.
- Po vypršení souhlasu aplikace zachová historii a nabídne nové připojení.
- Při výpadku API lze pokračovat importem výpisu; oba zdroje sdílejí stejnou historii a párování.
- Bankovní výdaj nelze omylem smazat v obecném rozpočtu. Musí se vyřadit v bankovním panelu cesty, čímž zůstane auditovatelná původní transakce.

## Ověření

Integrační testy pokrývají OAuth stav, read-only scope, šifrování citlivých hodnot, účty více majitelů, zůstatky před/po cestě, kategorizaci, vratku, vlastní převod, automatické cestovní výdaje, překrývající se výpisy, idempotentní synchronizaci, vlastní pravidla, režim pouze pro čtení a izolaci cizího prostoru.
