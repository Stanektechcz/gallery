# Roadmap dokončení systému galerie, cest a vzpomínek

Stav aktualizován: 14. 7. 2026. Tento dokument je pracovní plán pro další implementaci. Rozlišuje funkce nasazené, rozpracované a nezapočaté. „Částečně“ znamená, že existuje bezpečné datové jádro nebo prototyp, ale chybí uživatelský postup, automatizace, integrace či dostatečné testy.

## Priorita a pravidla

1. Nejdříve dokončovat celé uživatelské postupy, ne jen přidávat tabulky nebo API.
2. Cestovní data z externích zdrojů musí mít bezpečný fallback; nic nesmí rozbít plán cesty při výpadku poskytovatele.
3. Citlivé informace (poloha, doklady, zdraví, soukromé poznámky) jsou opt-in, šifrované nebo omezené na konkrétního uživatele.
4. Každý blok obsahuje migraci, API, responzivní UI, autorizaci a automatizovaný test.
5. Před nasazením vždy: `php artisan test`, `npm run build`, `php artisan migrate --force`.

## Přehled stavu

| Doména | Stav | Co existuje | Co chybí pro plnou funkčnost |
|---|---|---|---|
| Kalendář a společné plánování | Částečně | Akce, opakování s výjimkami, dostupnost, šablony, RSVP, úkoly, dárky, ankety, ICS export i partnerský import, české svátky a automatické návrhy prodlouženého volna propojené s cestou | Drag-and-drop série, historie změn, odložení připomínek, PWA widget |
| Cesty a itinerář | Částečně | Cesty, dny, aktivity, pořadí, rozpočty, doklady, balení, auto, nouzová karta, offline JSON | OCR rezervací, QR/PDF offline balíček, reálné časy přesunů, check-in a živé dopravní změny |
| Finance | Částečně | Výdaje, limity, kurzy, ruční vyrovnání, automobilové náklady | Podíly/procenta, automatické saldo, kauce, kredity, CSV/XLSX, fond cesty, detekce duplicit |
| Doprava a mapy | Částečně | Vyhledání jízdenek s fallbackem, filtry, uložené relace, české mapy | Stabilní živé zpoždění/nástupiště, emise/pohodlí, výjezd podle dopravy, aktivní sdílení polohy |
| Galerie a výběry | Částečně | Deduplikace, série, smart alba, vysvětlený výběr nejlepšího záběru, ručně potvrzená titulní fotografie, partnerský shortlist s hlasováním, kontrola cloudové kopie a oprava náhledů, fotoknihy, varování kvality tisku | Doporučení nových alb napříč nezařazenými médii, OCR, hromadná úprava času a provozní test obnovy zálohy |
| Vzpomínky a příběhy | Pokročilé | Vzpomínky, časové kapsle, soukromé poznámky, skutečný hlasový deník s GPS a ručním přepisem, partnerská viditelnost, kapitoly cesty podle dnů, citace, mapa, výroční album a večer se vzpomínkami | Hudební odkazy v příběhu a další kurátorské šablony |
| Místa | Částečně | Evidence míst a podniků, český našeptávač, GPS, média, preference, rezervace, výlet z výběru míst a partnerské hodnocení návštěv včetně obsluhy, jídel, pití, nabídky, cen a fotografií | Otevírací doba, voda/toalety, pokročilé tematické kolekce a návrh výletu z širšího okolí |
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

- Podílové výdaje a automatické vyrovnání mezi partnery.
- OCR/ruční schválení dat z PDF a e-mailových rezervací.
- Propojení inboxu s dnem a aktivitou v obou směrech.
- Offline balíček jako vytisknutelný PDF/HTML dokument s QR kódy.
- Filtry a kolekce míst; návrh výletu z wishlistu, počasí a vzdálenosti.
- Kontrola minimálního času přestupu a realistických přesunů.
- Připomínky konce dokladů, check-inu a dálniční známky.

### P1 — výrazné rozšíření zážitku

- **Dokončeno 15. 7. 2026:** hlasový deník se skutečným lokálním záznamem, ručním přepisem, GPS, soukromým i partnerským režimem a napojením na příběh.
- Kapitoly příběhu podle dnů, citace z deníku, mapa a hudební odkaz.
- Automatické výroční album a večer se vzpomínkami.
- Doporučení nových alb napříč nezařazenými médii; titulní fotografie i výběr ze série už mají vysvětlení a ruční schválení přímo v albu.
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

## Dokončená sjednocovací vlna 14. 7. 2026 — od hodnocení k dalšímu zážitku

- Hodnocení podniků, uložená místa, společná přání, preference ceny a historie návštěv nyní vytvářejí jeden vysvětlitelný žebříček návrhů.
- Návrh rozlišuje návrat na oblíbené místo a první návštěvu, zohledňuje shodu partnerů, přání vrátit se, dobu od poslední návštěvy, low-cost profil, déšť, fotogeničnost a rozpracované plány.
- Doporučení nikdy znovu nenabízí podnik, který už má aktivní společný plán.
- U doporučení je vidět důvod, průměrné hodnocení, poznámka pro příště a konkrétní jídlo nebo pití, které si chcete dát znovu.
- Stejný návrh je bez nové záložky dostupný na dashboardu, v existujícím kalendáři a přímo u hodnocení podniku.
- Jedno potvrzení vytvoří idempotentně propojenou událost, plán návštěvy, oba účastníky, připomínky, místo, délku návštěvy, kontext doporučení a případnou rezervaci.
- Kalendář už při použití nápadu nevytváří osiřelou událost bez vazby na místo a plán návštěvy.
- Pokrytí: integrační testy pořadí doporučení, low-cost filtru, shody partnerů, položky „dát si znovu“, dashboardu, účastníků, připomínek a ochrany proti duplicitnímu naplánování.

## Dokončená sjednocovací vlna 15. 7. 2026 — životní cyklus společného zážitku

- Detail existující kalendářní akce ukazuje jeden postup: uskutečnění, média, společná vzpomínka, ohlédnutí a případné hodnocení podniku.
- Uložení vzpomínky automaticky uzavře kalendářní akci i plán návštěvy a zachová skutečné datum návštěvy.
- Vybrané fotografie a videa se současně propojí s akcí, albem zážitku a navštíveným podnikem; není nutné je přidávat na více místech.
- Album zážitku získá výchozí podnik a vazbu do mapy míst.
- Společná vzpomínka nese vazbu na kalendářní akci i konkrétní plán návštěvy.
- Poznámka „příště“ ze společného ohlédnutí se bezpečně přenese k podniku, pokud tam ještě není přesnější poznámka.
- Dashboard zobrazuje nejnovější nedokončený zážitek, procento dokončení a další konkrétní krok pro právě přihlášeného partnera.
- Hodnocení podniku označí uskutečněnou návštěvu i související minulou událost jako dokončenou.
- Uložení vzpomínky z detailu podniku používá stejný kalendářní proces; původní izolovaná cesta zůstává pouze jako kompatibilní fallback pro starší záznamy bez události.
- Pokrytí: integrační test sleduje jeden zážitek od plánu přes ohlédnutí, média, album a vzpomínku až po rozdílný stav hodnocení každého partnera.

## Dokončená produktová vlna 15. 7. 2026 — společná kuchařka a deník vaření

- Vlastní sdílené recepty obsahují kategorie, kuchyni, obtížnost, popis, dietní štítky, vybavení, zdroj, cenu, výživové hodnoty, uchovávání, ohřev a praktické tipy.
- Suroviny jsou rozdělené do sekcí a podporují přesné desetinné množství, jednotku, přípravu, náhrady, volitelnost, položku ze spíže a vypnutí škálování.
- Změna počtu porcí okamžitě přepočítá suroviny, celkovou cenu i nákupní seznam; textové hodnoty jako „dle chuti“ zůstávají beze změny.
- Postup má samostatné kroky s teplotou, vybavením, tipem, časovačem, zvukovým a vibračním upozorněním.
- Kuchařský režim nabízí velké responzivní rozhraní, celou obrazovku a Wake Lock pro nevypínání displeje s bezpečným obnovením po návratu do aplikace.
- Vaření lze naplánovat přímo z receptu; systém vytvoří propojenou událost, oba účastníky a databázovou připomínku.
- Každé skutečné vaření má vlastní neměnnou historii: datum, autor, porce, délku, cenu, čtyři hodnocení, úspěchy, chyby, změny, zlepšení, partnerův pohled, zbytky a rozhodnutí, zda recept zopakovat.
- Při zahájení vaření se uloží snapshot přepočítaného receptu, takže historie zůstane srozumitelná i po pozdější úpravě surovin.
- Recept automaticky získá společné album. Titulní fotografie, průběh i výsledek vaření zůstávají propojené s původní galerií a konkrétním záznamem vaření.
- Kuchařka je dostupná v hlavní navigaci a příkazové paletě; nejbližší nebo doporučené vaření se propisuje na dashboard a událost v kalendáři odkazuje zpět na recept.
- Pokrytí: integrační test ověřuje vytvoření receptu, škálování porcí, nákupní seznam, album, titulní fotografii, kalendář, oba účastníky, připomínky, kuchařský režim backendu, deník, hodnocení a přístup partnera.

## Dokončená sjednocovací vlna 15. 7. 2026 — jídlo napříč kalendářem a cestou

- Recept už není izolovaná funkce: lze jej přidat přímo do existující kalendářní akce nebo konkrétního dne cesty bez další hlavní záložky.
- Jedno naplánování vytvoří propojený záznam jídla, vaření, kalendářní kontext a u cesty také blok itineráře, oba účastníky a připomínky.
- Společný nákup slučuje shodné suroviny z více receptů, respektuje počet porcí, jednotku, textové množství, volitelnost a stav domácí zásoby.
- Zaškrtnutí a odpovědnost se neukládají do paralelního seznamu: u akce vznikne existující společný úkol, u cesty položka existujícího balicího seznamu v kategorii jídlo.
- Odhad ceny receptu se přepočítá podle porcí a kurzu a vstupuje do kategorie Jídlo ve stejném low-cost rozpočtu, denním limitu a varování cesty.
- Panel ukazuje vyčerpání rozpočtu na jídlo, průběh nákupu, rozdělení mezi partnery a umí zkopírovat pouze zbývající položky.
- Cestovní obrazovka „Právě teď“ zobrazí recept daného dne a otevře jeho mobilní kuchařský postup.
- Dokončení nebo zrušení vaření aktualizuje jídelní plán. Běžná rodičovská akce se kvůli uvaření automaticky neuzavře; uzavírají se pouze události vytvořené samotným plánem vaření.
- Odebrání jídla uklidí generovaný blok, událost i již nepotřebné nákupní úkoly/balicí položky, aniž smaže původní akci partnerů.
- Pokrytí: dva integrační scénáře ověřují škálování, nákup, přiřazení, itinerář, kalendář, cestovní režim, balení, rozpočet, dokončení vaření i bezpečný úklid vazeb.

## Dokončená sjednocovací vlna 15. 7. 2026 — partnerský puls a sdílená zodpovědnost

- Dashboard a týdenní přehled používají jednu koordinační vrstvu nad původními úkoly kalendáře, balením, cestovním inboxem, doklady a dárky; nevzniká další samostatná agenda ani kopie úkolů.
- Dokončení nebo přiřazení v pulsu mění přímo zdrojovou položku a zachovává odkaz do jejího plného kontextu.
- Stejná zodpovědnost je viditelná a upravitelná také přímo v existujícím panelu dárků a v připravenosti konkrétní cesty; všechny obrazovky pracují s totožným stavem.
- Otevřené kroky lze přiřadit pouze aktivním členům stejného společného prostoru. Přehled ukazuje moje, nepřiřazené, zpožděné a týdenní kroky i rozložení zátěže mezi partnery.
- Osobní odložení skryje položku jen přihlášenému uživateli, nemění skutečný stav úkolu a nezkresluje partnerovu pracovní zátěž.
- Denní partnerský check-in obsahuje náladu, energii, kapacitu a dnešní zaměření. Může být sdílený nebo soukromý a ovlivňuje doporučení pro rozdělení úkolů.
- Doporučení upozorní nejprve na nepřiřazené kroky a nízkou kapacitu partnera, následně na nevyvážené rozdělení práce.
- Soukromé kalendářní akce a jejich úkoly zůstávají skryté partnerům bez oprávnění; režim pouze pro čtení blokuje všechny změny.
- Týdenní přehled už nenačítá všechny zdroje metodou „všechno nebo nic“ — dostupné části zůstanou funkční i při dočasném výpadku jedné služby.
- Rozhraní je responzivní: rychlý puls je vložený na dashboardu, plná správa zodpovědnosti je součástí existujícího týdenního plánování.
- Pokrytí: integrační testy ověřují všech pět zdrojů, ochranu soukromé akce, přiřazení, dokončení, osobní odložení, sdílený i soukromý check-in, cizího uživatele a režim pouze pro čtení.

## Dokončená sjednocovací vlna 15. 7. 2026 — partnerský večer se vzpomínkami

- Návrhy „Tento den“, výročí cest, oblíbené momenty, místa a měsíční výběry lze naplánovat přímo ve stávající stránce Vzpomínky; nevznikla další hlavní záložka.
- Systém najde nejbližší společný večer bez kolize, vytvoří jednu kalendářní akci, oba účastníky, připomínky a volitelné každoroční opakování.
- Fotografie a videa se nekopírují. Jediný sdílený kurátorský výběr zachovává původní galerii a zaznamená hlas každého partnera u konkrétního momentu.
- Každý partner doplní vlastní náladu a pohled. Rozhraní funguje jako mobilní společný rituál se spuštěním, průběžným výběrem a jednoznačným dokončením.
- Dokončení atomicky vytvoří sdílené album, titulní fotografii, společnou vzpomínku, oba partnerské pohledy, přílohy kalendářní akce a frontu synchronizace alba na Drive.
- Stejný stav je vidět na dashboardu, v detailu kalendářní akce a ve Vzpomínkách. Původní API pro plánování již uložených vzpomínek zůstává kompatibilní a při dostupných médiích používá nový životní cyklus.
- Oprávnění jsou omezena na členy společného prostoru; režim pouze pro čtení blokuje hlasování, spuštění, hodnocení i dokončení.
- Pokrytí: integrační test sleduje celý tok galerie → kalendář → oba partneři → výběr → ohlédnutí → album → Drive → společná vzpomínka a ověřuje idempotenci i izolaci cizího prostoru.

## Dokončená sjednocovací vlna 15. 7. 2026 — cestovní podklady přímo v itineráři

- Cestovní inbox už není samostatnou hlavní záložkou. Zachycené odkazy, rezervace a poznámky se spravují přímo v konkrétní cestě a dni.
- V plánu dne lze nový podklad rovnou vytvořit, ponechat jej jako referenci nebo jej jedním krokem převést na upravitelnou aktivitu itineráře.
- Rezervace se automaticky stane typem „Rezervace“, poznámka typem „Poznámka“ a ostatní podklady běžnou aktivitou; následně lze doplnit čas, místo a cenu.
- Zdrojový odkaz a původní poznámka zůstávají viditelné přímo u aktivity. Nedochází ke kopírování ani ztrátě kontextu.
- Převod je idempotentní a opakované volání nevytvoří další blok ani nepřepíše ruční úpravy programu.
- Podklad přiřazený jiné cestě nelze přesunout skrytým API voláním; den, aktivita, cesta i společný prostor se ověřují společně.
- Podklady vytvořené přímo u cesty dostávají stav „přiřazeno“, zatímco obecné nápady zůstávají v inboxu do jejich použití.
- Plán cesty nově funguje správně i v případě, že je uživatel členem více partnerských prostorů.
- Původní stránka cestovního inboxu zůstává jako kompatibilní cesta pro starší odkazy, ale není samostatnou položkou hlavní navigace.
- Pokrytí: integrační test ověřuje zachycení rezervace, převod, zdroj u aktivity, idempotenci, zachování ruční úpravy a izolaci jiné cesty.

## Dokončená sjednocovací vlna 15. 7. 2026 — výroční rekapitulace vztahu

- Datum začátku vztahu nyní určuje přesné období jednotlivých roků vztahu; výroční výběr se neřídí nepřesným kalendářním rokem.
- Aplikace navrhne oblíbené a hodnocené fotografie a videa a současně zachová zastoupení různých měsíců. Výběr, pořadí i titulní médium vždy potvrzuje uživatel.
- Jedno potvrzení vytvoří nebo synchronizuje právě jedno společné výroční album, oprávnění obou partnerů, příběh členěný podle měsíců a oblíbenou společnou vzpomínku.
- Album je propojené s opakovanou výroční událostí v kalendáři, dostupné ze vzpomínek a navržené na dashboardu pouze do chvíle potvrzení.
- Opakované uložení nevytváří duplicity. Aktualizuje média, titulní fotografii a vzpomínku, ale nepřepisuje ručně upravené bloky příběhu.
- Výběr nepřijme skrytá, smazaná ani časově nesouvisející média a režim pouze pro čtení blokuje zápis.
- Pokrytí: integrační test období druhého roku vztahu, návrhu médií, alba, příběhu, vzpomínky, kalendáře, partnerských oprávnění, dashboardu a idempotentní synchronizace.

## Dokončená sjednocovací vlna 15. 7. 2026 — chytré zařazení společných zážitků

- Nezařazené fotografie a videa se automaticky seskupují do souvislých zážitků podle času a výrazné změny polohy; skrytá, smazaná a již zařazená média do návrhů nevstupují.
- Každý návrh je vysvětlitelný: systém ukáže vazbu na sdílenou událost, cestu nebo uložené místo, případně jasně uvede, že rozhodl společný časový úsek.
- Návrhy jsou přímo na stránce stávajících alb a jako nejbližší akce na dashboardu. Nevznikla nová hlavní záložka ani paralelní galerie.
- Před potvrzením lze změnit název a popis, vyřadit jednotlivé snímky či videa a zvolit titulní médium. Automatika nikdy nepřesune obsah bez souhlasu uživatele.
- Potvrzení doplní existující album události nebo cesty, pokud už existuje; jinak vytvoří jedno sdílené album s oprávněním obou partnerů.
- Ve stejné transakci se média propojí s událostí, cestou a místem, vytvoří se příběhové bloky a volitelná společná vzpomínka. Nové album se následně zařadí do synchronizace na Drive.
- Odmítnuté a přijaté návrhy se uchovávají pomocí stabilního otisku a znovu se nenabízejí. Opakované potvrzení je idempotentní a nezdvojí album ani média.
- MySQL indexy používají explicitní krátké názvy, aby migrace nepřekročila limit délky identifikátorů.
- Dashboard používá odlehčený výpočet bez načítání stovek náhledů; plná média se načtou až na stránce alb.
- Pokrytí: integrační testy ověřují rozpoznání události, vyloučení skrytých a osamocených médií, dashboard, album, příběh, vzpomínku, partnerská oprávnění, přílohy kalendáře, idempotenci, odmítnutí i ochranu proti vložení cizího média.

## Dokončená sjednocovací vlna 15. 7. 2026 — společný Revolut účet a skutečné finance cest

- Revolut je připojen přes read-only PSD2 GoCardless Bank Account Data; alternativou a historickou zálohou zůstává plnohodnotný import CSV/XLSX.
- Účty, zůstatkové snapshoty, transakce, importy, kategorie a vazby na cesty mají stabilní databázovou historii. API synchronizace ani překrývající se výpisy nevytvářejí duplicity.
- Citlivé externí identifikátory, majitelé účtu, protistrany, obchodníci, popisy a původní payloady jsou šifrované; IBAN je uložen pouze maskovaně.
- Platby se párují podle období, druhu, itineráře, rezervace a uloženého místa. Potvrzená platba vytváří skutečný výdaj ve stávajícím rozpočtu, kalendářní připravenosti a low-cost limitech.
- Vlastní převody nevytvářejí výdaj, vratky mají vlastní souhrn, platby před/po cestě se rozlišují a nejisté návrhy vyžadují potvrzení.
- V plánu cesty je zůstatek před/po výletu, denní graf, kategorie, obchodníci, vratky, poplatky a transakce k rozhodnutí. Dashboard používá stejný stav a není vytvořena další hlavní záložka.
- Administrace obsahuje klíče, test poskytovatele, bankovní souhlas, ruční synchronizaci, importy a pravidla „navrhnout / zahrnout / vyloučit“.
- Ruční rozhodnutí se nesmí automaticky přepsat. Bankovní výdaj nelze smazat mimo bankovní panel; vyřazení odstraní rozpočtovou vazbu, ale uchová původní historii.
- Synchronizace běží každých šest hodin ve frontě, používá zámky proti souběhu, bezpečně obnovuje token a při odpojení odvolává poskytovatelský souhlas, pokud je dostupný.
- Pokrytí: OAuth stav a scope, šifrování, společný účet, API idempotence, výpisová deduplikace, zůstatky před/po cestě, pravidla, vratka, vlastní převod, oprávnění partnerů a režim pouze pro čtení.

## Dokončená sjednocovací vlna 15. 7. 2026 — rezervace jako součást celé cesty

- Detail cesty obsahuje jeden responzivní vstup pro PDF, fotografii, e-mail i vložený text jízdenky, ubytování, pojištění nebo jiné rezervace; nevznikla další hlavní záložka.
- PDF a obrázky lze zpracovat bez placené služby lokálními nástroji `pdftotext` a Tesseract (`ces+eng`). Pokud na serveru nejsou dostupné nebo soubor text neobsahuje, zůstane vždy plně funkční ruční kontrola.
- Parser navrhuje typ, poskytovatele, kód, začátek a konec, trasu, místo, cenu a měnu. Ukazuje jistotu a výřez zdroje, ale bez výslovného potvrzení uživatele nic dalšího nevytvoří.
- Potvrzení atomicky propojí existující kontrolu dokladů, cestovní inbox, konkrétní den a blok itineráře, kalendářní rezervaci, oba partnery, privátní přílohu a databázová/push připomenutí.
- Cena rezervace se stane plánovanou cenou bloku itineráře, čímž vstupuje do stejného přehledu dne a low-cost plánování; nevzniká falešný skutečný výdaj.
- Potvrzená data jsou součástí existující offline cestovní karty a jejího tisknutelného HTML/PDF výstupu včetně časů, trasy, místa, kódu a ceny.
- Původní soubor je uložen v privátním lokálním úložišti pod náhodným názvem, rozpoznaný zdrojový text je v databázi šifrovaný a soubor se stahuje pouze přes autorizovaný endpoint člena společného prostoru.
- Shodný soubor nebo text se v rámci jedné cesty deduplikuje podle SHA-256. Opakované potvrzení aktualizuje stejné navázané záznamy a nevytváří nové aktivity, události, doklady ani remindery.
- Nepotvrzený import lze bezpečně zahodit; potvrzený nelze smazat bez kontroly navázaných dat.
- Pokrytí: integrační test ověřuje parser, ochranu proti falešné ceně z data, deduplikaci, doklad, itinerář, inbox, kalendář, oba partnery, remindery, offline kartu, idempotenci a izolaci cizího uživatele.

## Dokončená sjednocovací vlna 15. 7. 2026 — skutečné hlasové vzpomínky na cestě

- Režim „Právě teď“ už nezaměňuje textový diktát za hlasovou vzpomínku. Nabízí zvlášť skutečné nahrávání zvuku, diktování textu a uložení samotné polohy.
- Nahrávání používá vestavěný `MediaRecorder`, podporuje běžné mobilní formáty WebM/Opus, MP4/M4A, OGG, MP3 a WAV, ukazuje délku, nabízí náhled před uložením a bezpečně zastaví mikrofon po deseti minutách.
- Před uložením lze doplnit ruční přepis nebo popis, náladu a aktuální GPS polohu. Stejná volba „mezi námi / jen pro mě“ řídí deník, přístup partnera i výsledný příběh.
- Audio je uložené pod náhodným názvem v privátním úložišti. Streamování podporuje prohlížečové přehrávání a rozsahové požadavky, ale vždy znovu ověří členství v cestě a soukromé vlastnictví.
- Společná nahrávka se přehrává přímo v existujícím cestovním deníku a jako hlasový moment v příběhu existujícího rekapitulačního alba. Nevznikla další audio galerie ani hlavní záložka.
- Pokud rekapitulační album vznikne až později, úvodní generování příběhu přenese i dříve uložené hlasové momenty. Novější nahrávky se do existujícího příběhu doplňují průběžně.
- Změna přepisu, viditelnosti nebo zařazení do příběhu aktualizuje stejný generovaný blok. Soukromý záznam se z příběhu odstraní, ale zůstane vlastníkovi; opětovné sdílení jej bezpečně obnoví.
- Smazání zápisu odstraní databázovou vazbu, privátní zvukový soubor i generovaný příběhový blok. Režim pouze pro čtení a cizí prostor zápis i přehrání blokují.
- Frontend uvolňuje mikrofon, časovače a dočasné Blob URL při zastavení i opuštění stránky, takže nahrávání nezůstane skrytě aktivní.
- Pokrytí: integrační test ověřuje nahrání, metadata, privátní soubor, deník, partnerovo přehrání, příběh, ruční přepis, přepnutí na soukromý režim, opětovné sdílení, úplný úklid, odmítnutí neplatného formátu a read-only ochranu.

## Dokončená sjednocovací vlna 15. 7. 2026 — jeden partnerský rozpočet cesty

- Plán cesty má jeden responzivní pracovní prostor pro rozpočet, skutečné výdaje, low-cost limity, cestovní fond, varianty trasy, podíly partnerů a vyrovnání. Dřívější samostatné finanční panely se už v rozhraní neduplikují.
- Každá skutečná platba rozlišuje osobní platbu, hotovost, jiný zdroj a společný účet. Platby importované ze společného Revolut účtu jsou automaticky neutrální pro dluh mezi partnery, ale zůstávají v celkové útratě cesty.
- Osobní výdaj lze rozdělit rovným dílem nebo přesnými částkami. Výpočet pracuje v haléřích, zachovává celkovou částku a bezpečně dopočítá minimální počet plateb mezi více členy cesty.
- Návrh vyrovnání je idempotentní, nelze jej uložit proti aktuálnímu saldu ani zdvojit. Po potvrzení se projeví v novém saldu a zůstane v historii; dokončenou historii nelze omylem smazat.
- Neuhrazené vyrovnání se stejným zdrojem dat objeví v partnerském pulsu i mezi nejbližšími kroky dashboardu. Je pevně přiřazené plátci, oba účastníci je mohou potvrdit a odkaz vede přímo na finance dané cesty.
- Úprava automaticky spárované bankovní platby dovoluje zkontrolovat zdroj, plátce a podíly, ale chrání původní bankovní částku a historii před nechtěným přepsáním.
- Nasazovací migrace bezpečně doplní zdroj platby a metadata vyrovnání, opraví dříve spárované společné bankovní výdaje a používá krátký explicitní název indexu kompatibilní s MySQL.
- Pokrytí: integrační testy ověřují osobní i společné platby, změnu podílů, minimální saldo, idempotentní návrh, úhradu a historii, ochranu bankovního původu i dokončení vyrovnání z partnerského přehledu.

## Dokončená sjednocovací vlna 15. 7. 2026 — rozhodnout spolu bez přepínání záložek

- Dashboard a stávající stránka společného plánování mají jeden personalizovaný proud nerozhodnutých randíček, filmů a seriálů, navržených termínů sledování a běžných partnerských anket. Nevznikla další hlavní záložka ani paralelní evidence hlasů.
- Každá volba se zapisuje přímo do původní domény: reakce k randíčku, zájem ve watchlistu, dostupnost navrženého termínu nebo konkrétní možnost ankety. Původní obrazovky proto okamžitě používají stejné výsledky.
- Proud je pro každého partnera osobní: ukazuje pouze položky, u kterých ještě nehlasoval. Návrh jednoho partnera se automaticky zobrazí druhému, ale soukromý prostor ani cizí uživatel data neuvidí.
- Termíny s blízkým datem mají přednost před anketami, randíčky a obecnými tituly. Jednotlivé zdroje mají nezávislé fallbacky, takže chybějící migrace jedné funkce nevyřadí celé plánování.
- Po shodě pokračuje stejný záznam stávajícím postupem do kalendáře, připomínek, účastníků, případné cesty a následně do fotografií, hodnocení a vzpomínky. Panel vždy nabízí i odkaz do plného kontextu původní funkce.
- Režim pouze pro čtení je sjednocený také pro původní API randíček, šablon, wishlistů, anket, dostupnosti, nouzové karty a partnerských pravidel; nelze jej obejít přímým požadavkem mimo nový panel.
- Responzivní karty podporují dynamický počet možností ankety, filmové plakáty, český termín, mobilní ovládání a kompaktní dashboard bez dalšího načítání dat po otevření stránky.
- Pokrytí: integrační test sleduje čtyři zdroje rozhodnutí, personalizované pořadí, zápis do původních tabulek, shodu partnerů, tři navazující kalendářní události, účastníky, dashboard, izolaci prostoru a read-only ochranu.

## Dokončená sjednocovací vlna 15. 7. 2026 — od naplánované akce rovnou ke společnému albu

- Detail stávající kalendářní akce nově obsahuje přímé mobilní nahrávání fotografií, videí, HEIC i RAW souborů. Nevzniká samostatná obrazovka ani další záložka pro sběr médií.
- Při prvním nahrávání se bezpečně vytvoří právě jedno sdílené album zážitku. U cesty se znovu použije její společné cestovní album a stejné album se propíše ke všem navázaným událostem.
- Dokončený i duplicitní upload se automaticky připojí k události, albu a případné cestě; média se nekopírují a položka už zařazená v jiném albu může být současně viditelná i v tomto zážitku.
- Oprávnění alba dostanou všichni členové stejného partnerského prostoru jako editoři. Cizí prostor a uživatel bez práva upravovat událost nemohou album připravit ani do něj přes událost připojit média.
- Chytré návrhy už tvrdě nevyřazují fotografie bez GPS. Kombinují přesný čas, časové okolí, dostupnou vzdálenost a existující vazbu na cestu, ukazují sílu shody i srozumitelný důvod a odmítají média zjevně vzdálená od akce.
- Skrytá, smazaná, nezpracovaná a již připojená média do návrhů nevstupují. Výběr existujících souborů zůstává pod kontrolou uživatele.
- Připravení příběhu používá stejné album a stejnou synchronizační službu. Album, přílohy, cesta, příběh a následná společná vzpomínka proto nevytvářejí paralelní nebo rozporné vazby.
- Rozhraní je responzivní, náhledy používají odložené dekódování a výběr má omezenou rolovací oblast, aby detail akce zůstal plynulý i na telefonu.
- Pokrytí: integrační test ověřuje návrh HEIC fotografie bez GPS, idempotentní album cesty, oprávnění obou partnerů, upload podle UUID, přílohu události, album, cestovní galerii, primární album a výsledný stav životního cyklu zážitku.

## Dokončená sjednocovací vlna 15. 7. 2026 — responzivní galerie, stabilní import a interaktivní finance

- Aplikace používá jeden hlavní scroll kontejner založený na `100dvh`; mobilní horní lišta a spodní navigace jsou dostupné ihned a nezávisí na délce úvodní galerie.
- Mobilní menu, vyhledávání, upload, hromadné akce, detail média a informační panel respektují bezpečné okraje zařízení a navzájem se nepřekrývají.
- Timeline a alba načítají pouze malé náhledy, nikoli originály nebo HEIC zdroje. Karty používají nativní lazy loading, asynchronní dekódování, memoizaci a odložené vykreslení vzdálených skupin.
- Chybějící náhled používá opravný endpoint; detail fotografie zůstává samostatným místem pro plné rozlišení a mobilní informační panel funguje jako spodní list.
- Revolut CSV/XLS/XLSX import lze spustit výrazným tlačítkem v horní části financí. Český přiložený XLSX byl ověřen na 36 transakcích bez ztráty řádku.
- Uložení finanční historie je oddělené od volitelného párování cest. Starší cestovní schéma už nezpůsobí HTTP 500 ani nekonečný neúspěšný opakovaný import; uživatel dostane konkrétní upozornění.
- Finance obsahují aktuální zůstatek, měnové souhrny, denní i měsíční cash-flow, kategorie, obchodníky, cesty a navázané kalendářní události.
- Denní vývoj a zůstatkové grafy mají dotykový posuvník, přesnou hodnotu vybraného dne, změnu proti předchozímu bodu a jednotný formát data `dd.mm.rr`.
- Kontrolní panel umožňuje jedním klepnutím zobrazit propojené, navržené nebo nepřiřazené platby a na stejné obrazovce měnit kategorii, pravidlo párování, cílovou cestu i rozdělenou částku.
- OpenRouteService používá aktuální GeoJSON kontrakt, raw `Authorization`, správný `Accept` a české navigační instrukce; tím je odstraněna odpověď HTTP 406 způsobená JSON-only hlavičkou.
- Pokrytí: kompletní sada 197 testů a 2091 kontrol, včetně regresí ORS hlaviček, reálných XLS/XLSX variant, odolného importu, denního cash-flow, filtrů přiřazení a vazeb na kalendář.

## Dokončená sjednocovací vlna 16. 7. 2026 — jednoduchá navigace podle skutečných činností

- Mobilní `Domů` vede výhradně na partnerský dashboard, zatímco chronologická galerie zůstává samostatně pod jasnou položkou `Fotky`.
- Hlavní nabídka obsahuje jen pět základních cílů; ostatní funkce jsou uspořádané podle činnosti do uzavíratelných skupin `Společně`, `Cestování`, `Knihovna` a `Administrace`.
- `Cestování` sjednocuje itinerář světa, mapu, cesty a výlety, jízdenky a dopravu i srozumitelně přejmenovaná `Místa a podniky`.
- `Administrace` je standardně zavřená a skrývá statistiky, aktivitu, recovery centrum, soukromí, zabezpečení, úložiště, systémovou správu i integrace a API. Práva administrátora se nadále kontrolují u každé chráněné položky.
- Otevřená skupina se automaticky přizpůsobí právě zobrazené stránce a uživatelská volba se uchovává lokálně v prohlížeči.
- Každý uživatel si může vybrat až šest vlastních rychlých zkratek, měnit jejich pořadí a kdykoli je vymazat; nastavení nezatěžuje server ani společný prostor druhého partnera.
- Mobilní spodní navigace má pouze čtyři cíle a tlačítko `Více`, takže zůstává čitelná a ovladatelná jednou rukou i na úzkém telefonu.
