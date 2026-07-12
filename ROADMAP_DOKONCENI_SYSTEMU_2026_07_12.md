# Roadmap dokončení systému galerie, cest a vzpomínek

Stav: 12. 7. 2026. Tento dokument je pracovní plán pro další implementaci. Rozlišuje funkce nasazené, rozpracované a nezapočaté. „Částečně“ znamená, že existuje bezpečné datové jádro nebo prototyp, ale chybí uživatelský postup, automatizace, integrace či dostatečné testy.

## Priorita a pravidla

1. Nejdříve dokončovat celé uživatelské postupy, ne jen přidávat tabulky nebo API.
2. Cestovní data z externích zdrojů musí mít bezpečný fallback; nic nesmí rozbít plán cesty při výpadku poskytovatele.
3. Citlivé informace (poloha, doklady, zdraví, soukromé poznámky) jsou opt-in, šifrované nebo omezené na konkrétního uživatele.
4. Každý blok obsahuje migraci, API, responzivní UI, autorizaci a automatizovaný test.
5. Před nasazením vždy: `php artisan test`, `npm run build`, `php artisan migrate --force`.

## Přehled stavu

| Doména | Stav | Co existuje | Co chybí pro plnou funkčnost |
|---|---|---|---|
| Kalendář a společné plánování | Částečně | Akce, opakování s výjimkami, dostupnost, šablony, RSVP, úkoly, dárky, ankety, ICS export | ICS import, veřejné svátky ČR, drag-and-drop série, historie změn, odložení připomínek, PWA widget |
| Cesty a itinerář | Částečně | Cesty, dny, aktivity, pořadí, rozpočty, doklady, balení, auto, nouzová karta, offline JSON | OCR rezervací, QR/PDF offline balíček, reálné časy přesunů, check-in a živé dopravní změny |
| Finance | Částečně | Výdaje, limity, kurzy, ruční vyrovnání, automobilové náklady | Podíly/procenta, automatické saldo, kauce, kredity, CSV/XLSX, fond cesty, detekce duplicit |
| Doprava a mapy | Částečně | Vyhledání jízdenek s fallbackem, filtry, uložené relace, české mapy | Stabilní živé zpoždění/nástupiště, emise/pohodlí, výjezd podle dopravy, aktivní sdílení polohy |
| Galerie a výběry | Částečně | Deduplikace, série, smart alba, kurátorské výběry, fotoknihy, varování kvality tisku | Výběr nejlepší fotky s vysvětlením, doporučení alb, OCR, úprava času, kontrola zálohy média |
| Vzpomínky a příběhy | Částečně | Vzpomínky, cesta, časové kapsle, soukromé poznámky, základ příběhu | Hlasový deník, kapitoly cesty, citace, mapa/hudba v příběhu, výroční album, večer se vzpomínkami |
| Místa | Rozpracováno | Evidence míst, GPS, média, osobní preference pro déšť/cenu/délku/hodnocení | Filtry a kolekce míst, voda/toalety/otevírací doba, rezervace u místa, návrh výletu z okolí |
| Sdílení a rodina | Částečně | Odkazy, hesla, expirace, hostující upload a schvalování | Schvalování hostujících komentářů, přehled stažení, žádost o přístup, rodinné skupiny, pravidla podle místa/data |
| Mobilní režim | Částečně | PWA, Share Target, „Právě teď“, responzivní obrazovky | Offline fronta, haptika, zástupce, widget, sken dokumentu, privátní obrazovka |
| Provoz a bezpečnost | Částečně | Hlavičky, relace, audit části akcí, export s vlastnickou kontrolou | Passkeys, 2FA, obnovovací kódy, Web Push/VAPID, test obnovy zálohy, monitoring výkonu |

## Rozpracované bloky, které je nutné uzavřít

### 1. Cestovní inbox do itineráře

Datové vazby na cestu, den a aktivitu jsou rozpracované. Je nutné dokončit:

- test, že nelze přiřadit den/aktivitu z jiné cesty nebo jiného prostoru;
- zobrazení přiřazení také v detailu aktivity a denním plánu;
- možnost přiřazení z detailu cesty, ne jen z inboxu;
- migraci nasadit až po úspěšném testu na MySQL.

### 2. Preference míst

Rozpracované jsou parametry déšť, bezbariérovost, fotogeničnost, brzké otevření, cena, délka návštěvy, hodnocení a poznámka pro příště.

- přidat filtry do seznamu míst;
- přidat kolekce: kavárny, restaurace, vyhlídky, déšť, brzy ráno, fotogenická místa;
- přidat rezervaci a stav návštěvy ke konkrétnímu místu;
- otestovat migraci a API nad MySQL.

### 3. Automobilová logistika

Existuje evidence tankování, parkování, známek, mýta, kilometrů a ceny za kilometr.

- přidat datum a zónu parkování do UI;
- přidat připomínku před koncem dálniční známky;
- přidat spotřebu z rozdílu tachometru a export přehledu;
- propojit automobilové náklady s cestovním rozpočtem bez dvojího započtení.

## Low-cost plánování cest — doporučený samostatný blok

Cíl: rychle navrhnout levnou cestu bez klamavých „živých“ cen. Systém musí vždy uvést zdroj, čas ověření a možnost ruční opravy.

### MVP — implementovat jako první

1. **Rozpočet cesty a denní strop** — celkový limit, limit na den a na kategorii.
2. **Low-cost profil cesty** — úsporný / vyvážený / pohodlný; ovlivní řazení variant.
3. **Porovnání variant** — cena, čas, počet přestupů, pěší vzdálenost, komfort a emise.
4. **Hlídač drahých položek** — upozornění, když doprava, nocleh nebo jídlo překročí limit.
5. **Seznam levných aktivit** — zdarma / do stanovené ceny / vhodné při dešti / dostupné pěšky.
6. **Plán jídla** — odhad denního rozpočtu, vlastní jídlo, supermarket a restaurace.
7. **Sdílené náklady** — kdo platil, komu co náleží, automatické zaokrouhlené vyrovnání.
8. **Cestovní fond** — cíl, termín, měsíční příspěvek a zbývající částka.

### Druhá etapa low-cost

9. Cenový deník hotelů a dopravy s ručně uloženými nabídkami.
10. Srovnání ceny ubytování podle osoby/noci a dopravní dostupnosti.
11. Kalkulace auta: palivo, známka, parkování, mýto, náklady na kilometr.
12. Upozornění na duplicitu platby nebo rezervace.
13. Vratné kauce, kredity, poukazy a jejich datum platnosti.
14. Export rozpočtu do CSV; XLSX až po ověření dostupné knihovny a formátu.
15. Anonymizované srovnání plánované a skutečné ceny mezi vašimi minulými cestami.

### Závislosti low-cost plánování

- Frankfurter/ECB pro referenční kurzy; kurz se musí uložit k nákupu.
- Open-Meteo pro počasí; návrh nesmí záviset výhradně na počasí.
- OpenRouteService pro trasu, jen pokud je uložen API klíč; jinak ruční/fallback varianta.
- Ceny dopravců pouze z povolených zdrojů a s viditelným časem posledního ověření.

## Neimplementované funkce podle priorit

### P0 — nejvyšší praktický přínos

- ICS import a deduplikace kalendářů.
- Podílové výdaje a automatické vyrovnání mezi partnery.
- OCR/ruční schválení dat z PDF a e-mailových rezervací.
- Propojení inboxu s dnem a aktivitou v obou směrech.
- Offline balíček jako vytisknutelný PDF/HTML dokument s QR kódy.
- Filtry a kolekce míst; návrh výletu z wishlistu, počasí a vzdálenosti.
- Kontrola minimálního času přestupu a realistických přesunů.
- Připomínky konce dokladů, check-inu a dálniční známky.

### P1 — výrazné rozšíření zážitku

- Hlasový deník s lokálním záznamem, ručním přepisem a GPS.
- Kapitoly příběhu podle dnů, citace z deníku, mapa a hudební odkaz.
- Automatické výroční album a večer se vzpomínkami.
- Výběr nejlepší fotografie ze série s vysvětlením.
- Doporučení alb a titulní fotografie vždy s ručním schválením.
- Úprava chybného času fotoaparátu hromadně.
- Sdílená rodinná skupina, žádost o přístup a přehled stažení.
- Mobilní kamera do akce a sken dokumentu.

### P2 — provoz, výkon a důvěra

- VAPID klíče, skutečné Web Push a historie doručení.
- Passkeys/WebAuthn, druhý faktor citlivých akcí a obnovovací kódy.
- Retenční politika pro koš, hostující uploady a offline data.
- Test obnovy zálohy a měsíční report kapacity.
- Rozpočet bundle v CI, metriky obrazovek a pomalé databázové dotazy.
- Anonymizovaný diagnostický report.

## Funkce závislé na externí autoritě nebo infrastruktuře

Tyto body nelze prohlásit za plně hotové bez následného nastavení a povolení:

- živá zpoždění, nástupiště a změny dopravců — vyžadují stabilní a licencovaný zdroj dat;
- serverové Push notifikace — vyžadují VAPID klíče a funkční worker/queue;
- OCR PDF/e-mailů — vyžaduje zvolený OCR engine nebo poskytovatele a souhlas uživatele;
- Passkeys — vyžadují HTTPS, WebAuthn konfiguraci, správu credentialů a obnovovací postup;
- reálné ověření obnovy záloh — vyžaduje samostatné cílové úložiště a provozní politiku;
- živé ceny a dostupnost u dopravců/ubytování — vyžadují legální zdroj a limity API.

## Doporučené pořadí následujících implementačních vln

1. Dokončit inbox → den → aktivita, preference/filtry míst a low-cost finance.
2. Přidat OCR se schválením, offline PDF balíček, QR a cestovní kontrolní seznamy.
3. Dokončit inteligentní galerii a příběhy.
4. Dokončit rodinné sdílení a mobilní offline workflow.
5. Nasadit bezpečnostní a provozní funkce po konfiguraci infrastruktury.
