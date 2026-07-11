# Externí závislosti pro dokončení pokročilých funkcí

Tento dokument odděluje funkce, které lze implementovat čistě uvnitř aplikace, od funkcí vyžadujících produkční účet, licencovaný datový zdroj, zařízení nebo administrátorské oprávnění. Aplikace pro ně má datový základ a bezpečné fallbacky; bez níže uvedených vstupů však nesmí předstírat reálné doručení nebo aktuální údaje.

## Doprava, provoz a počasí

| Funkce | Co chybí pro aktivaci | Bezpečný stav aplikace |
|---|---|---|
| Živé zpoždění, změna nástupiště a výluky | Smluvní/API přístup k GTFS-RT nebo autorizovanému zdroji ČD, RegioJet, FlixBus apod. | Vyhledání a ověřené odkazy na portály dopravců. |
| Dopravní situace a „vyrazit teď“ | Poskytovatel routing/traffic API, klíč, limity a podmínky užití. | Ručně nastavená rezerva odjezdu a upozornění. |
| Počasí pro doporučení výletu | Open-Meteo je zapojen bez klíče; před komerčním nasazením je nutné ověřit podmínky konkrétního provozu a attribution. | Aplikace používá Open-Meteo pro ověřenou předpověď podle souřadnic. |
| Otevírací doby, bezbariérovost a vybavenost míst | Licencovaný POI/opening-hours zdroj a pravidla aktualizace. | Ručně uložená místa a poznámky. |

## Komunikace a identita

| Funkce | Co chybí pro aktivaci | Bezpečný stav aplikace |
|---|---|---|
| Vzdálený Web Push | VAPID veřejný/soukromý klíč, HTTPS a worker podporující šifrovaný web-push protokol. | In-app, e-mailové a lokální prohlížečové připomínky; historie doručení. |
| Přijetí rezervace z e-mailu | Vyhrazená schránka, IMAP/Gmail/Microsoft OAuth souhlas a retenční pravidla příloh. | Ruční přidání odkazu, referenčního kódu a přílohy. |
| Passkeys/WebAuthn | Registrované RP ID/doména, HTTPS, attestation politika a audit obnovy přístupu. | Heslo, pozvánky, bezpečné relace a trezor. |
| Sdílení polohy na pozadí | Nativní mobilní aplikace nebo OS oprávnění; PWA nemá spolehlivé background GPS na všech platformách. | Výslovný časově omezený souhlas a ruční záznam bodů trasy. |

## Dokumenty, média a automatizace

| Funkce | Co chybí pro aktivaci | Bezpečný stav aplikace |
|---|---|---|
| OCR PDF a vstupenek | Tesseract/Poppler na serveru nebo smluvní cloud OCR; pravidla pro citlivé doklady. | Rezervace, dokumentové checklisty a ručně zadaný text/reference. |
| Automatický výběr „nejlepší fotografie“ s vysvětlením | Model kvality/vision inference a výpočetní rozpočet. | Stacky, duplicitní skupiny a ruční výběr. |
| Předpřipravené offline mapové oblasti | Poskytovatel map s povoleným offline cache/licencí a limitem dlaždic. | Offline plán, kontakty, doklady a zvolená trasa v JSON/PWA cache. |
| AVIF na všech serverech | Libvips/Imagick s AVIF enkodérem a testovací matrix. | Existující responzivní varianty; AVIF se nezapíná bez ověřeného enkodéru. |

## Finance, zálohy a observabilita

| Funkce | Co chybí pro aktivaci | Bezpečný stav aplikace |
|---|---|---|
| Automatický kurz měn | Frankfurter/ECB je zapojen bez klíče; je třeba rozhodnout frekvenci synchronizace a případný zdroj pro měny mimo ECB pokrytí. | Aplikace čte referenční kurzy ECB přes Frankfurter a zachovává i ruční kurzovní lístek. |
| Ověření obnovy zálohy | Oddělené testovací úložiště, přístup k záloze a schválený postup obnovy DB/Drive. | Health kontroly a storage diagnostika. |
| Externí performance monitoring | Vybraný Sentry/APM/analytics účet, privacy konfigurace a souhlas s telemetrií. | Produkční build a interní health endpointy. |

## Co je potřeba dodat

1. Seznam preferovaných poskytovatelů pro dopravu, počasí, routing a kurzovní data.
2. Produkční klíče uložené pouze v serverovém `.env`, nikdy do repozitáře nebo klientského bundle.
3. Rozhodnutí, zda povolit čtení vyhrazené rezervační schránky.
4. Doménu pro WebAuthn a VAPID klíče pro push.
5. Politiku záloh a testovací prostředí, kde je bezpečné obnovu ověřit.

Po doplnění těchto vstupů lze aktivovat konkrétní adaptér, testovat jej proti sandboxu a teprve poté povolit produkční automatizaci.
