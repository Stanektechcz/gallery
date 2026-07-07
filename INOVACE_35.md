# MAKI Gallery — 35 dalších inovací

Tento backlog rozšiřuje desetifázovou roadmapu. Inspirace vychází z principů Google Photos (automatické vzpomínky, partner sharing, výtvory), Immich (lokální vlastnictví dat, pokročilé filtry, veřejné odkazy), Notionu (pohledy nad jedněmi daty, bloky, relace) a Android Gallery (rychlost, offline práce, složky zařízení). Nejde o kopírování rozhraní; návrhy jsou přizpůsobené společnému archivu cest a vzpomínek.

## Objevování a organizace

1. **Univerzální uložené pohledy** — jeden filtr lze zobrazit jako mřížku, časovou osu, mapu, kalendář nebo tabulku.
2. **Připnuté pohledy** — nejčastější kolekce jsou dostupné přímo v navigaci.
3. **Přirozené české hledání** — dotazy jako „videa z Itálie s Maki v létě 2025“ se převedou na strukturované filtry.
4. **Fasety výsledků** — okamžité počty podle typu média, roku, fotoaparátu, osoby, místa a alba.
5. **Hledání napříč entitami** — jeden výsledek zahrne fotky, alba, cesty, místa, osoby a tagy.
6. **Vysvětlitelné výsledky** — karta ukáže, zda odpovídá názvem, místem, osobou, textem nebo metadaty.
7. **Automatické stacky** — série, podobné snímky, RAW+JPEG a Live Photo se složí do jedné položky.
8. **Nejlepší snímek stacku** — výběr podle ostrosti, rozlišení, hodnocení a oblíbenosti s ruční změnou.
9. **Chytrý úklid** — bezpečné návrhy pro screenshoty, rozmazané snímky, velká videa a duplicity.
10. **Lokální složky zařízení** — oddělené pohledy Kamera, Screenshoty, Stažené a aplikace bez narušení hlavní knihovny.

## Cesty a itinerář

11. **Životní cyklus cesty** — návrh, plánováno, probíhá, dokončeno, archivováno.
12. **Notionové bloky itineráře** — aktivita, přesun, ubytování, rezervace, text, checklist, rozpočet a příloha.
13. **Šablony cesty** — víkend, roadtrip, pracovní cesta, turistika nebo festival předvyplní strukturu.
14. **Varianty trasy** — nejrychlejší, nejlevnější, bez auta, nejméně přestupů a bezbariérová.
15. **Časová kolize** — upozornění na nereálný přesun, překryv aktivit nebo zavřené místo.
16. **Rozpočtová obálka** — plán/skutečnost podle kategorií, měn a účastníků.
17. **Rezervační trezor** — jízdenky, QR kódy, potvrzení a kontakty navázané na aktivitu.
18. **Offline balíček cesty** — itinerář, média, mapové výřezy a dokumenty dostupné bez sítě.
19. **Karta „Právě teď“** — následující aktivita, odjezd, počasí, navigace a důležitý doklad.
20. **Cestovní deník jedním klepnutím** — rychlá poznámka, hlas, fotografie, místo nebo výdaj.

## Vzpomínky a příběhy

21. **Více typů vzpomínek** — výročí, stejné místo, oblíbené znovu, lidé v čase, cesta a měsíční výběr.
22. **Proč tuto vzpomínku vidím** — srozumitelný důvod výběru a možnost algoritmus opravit.
23. **Citlivé filtry** — skrýt osobu, datum, místo nebo celé téma bez mazání fotografií.
24. **Odložit/odmítnout/uložit** — zpětná vazba ovlivní další výběry.
25. **Automatický cestovní příběh** — kapitoly po dnech, mapa, statistiky a reprezentativní fotografie.
26. **Proměna v čase** — stejné místo nebo osoba napříč roky v porovnávacím režimu.
27. **Rodinná kronika** — události, narozeniny, výročí a vztahy propojené s médii.
28. **Výtvory** — koláž, animace, krátké video, mapa pohybu a fotokniha z vybraného příběhu.

## Sdílení, soukromí a spolupráce

29. **Partnerské sdílení podle pravidel** — automaticky sdílet od data, vybrané osoby, cesty nebo alba.
30. **Příspěvkový odkaz** — hosté mohou do sdíleného alba bezpečně nahrát vlastní fotografie.
31. **Role na úrovni kolekce** — vlastník, editor, přispěvatel, komentující a divák.
32. **Soukromý trezor** — zvlášť chráněné položky skryté z hledání, vzpomínek a běžných exportů.
33. **Centrum soukromí** — kdo má přístup, aktivní odkazy, expirace a historie stažení na jednom místě.

## Provoz a důvěra

34. **Vysvětlitelný stav zálohy** — u každé položky originál, náhledy, poslední ověření a cesta obnovy.
35. **Režim digitálního dědictví** — řízený export, nouzový kontakt a ověřitelný plán předání archivu.

## Implementační seskupení

- **Balíček A — Pohledy a hledání:** 1–6, 9.
- **Balíček B — Cesta jako workspace:** 11–20.
- **Balíček C — Paměťový engine:** 21–28.
- **Balíček D — Sdílení a důvěra:** 29–35.

Každý balíček má společný datový základ a několik samostatně zapínatelných uživatelských řezů. Díky tomu lze inovace vydávat postupně bez dlouhé větve, která by byla měsíce nenasaditelná.

## Stav po prvním implementačním řezu

| Oblast | Hotová funkční část |
|---|---|
| Pohledy | Uložení filtrů, řazení a typu pohledu; připnutí na domovskou stránku; soukromé a sdílené pohledy |
| Hledání | České dotazy, fasety, hledání alb/osob/míst/tagů/cest, rychlé filtry a mřížkový/seznamový/mapový/kalendářní pohled |
| Cesty | Životní cyklus, rozpočet, automatické dny, sedm typů bloků itineráře a offline cache plánu |
| Vzpomínky | Pět typů výběrů, vysvětlení doporučení, uložení/odložení/odmítnutí a nastavení četnosti/typů |
| Soukromí | Patnáctiminutový trezor chráněný opětovným zadáním hesla; skrytí z běžných dotazů a vzpomínek |
| Úklid | Vysvětlitelné návrhy duplicit, screenshotů, velkých videí, nezařazených médií a chybějících dat |
| Mobil/PWA | Dotykové ovládání, bezpečné okraje, omezení nechtěného zoomu formulářů, reduced-motion a nové PWA zkratky |

## Stav po druhém implementačním řezu

| Oblast | Hotová funkční část |
|---|---|
| Automatické stacky | Detekce RAW+JPEG a burst sérií, skórování obálky, náhled kandidátů, transakční vytvoření a sbalení v timeline |
| Aktivní cesta | Mobilní karta „Právě teď“, aktuální/další aktivita, průběh dne, navigace a rychlý cestovní deník s polohou |
| Příspěvkové odkazy | Veřejný upload více souborů, oddělená karanténa, schvalovací fronta, přijetí/odmítnutí a veřejné stahování v rámci oprávnění odkazu |
| Centrum soukromí | Přehled aktivních a zaheslovaných odkazů, skrytých médií, čekajících uploadů a audit návštěv/stahování |
| Digitální dědictví | Bezpečně uložený vypnutý/koncept/připravený plán, ověřený kontakt a období neaktivity; bez automatického předání dat |

Další řezy se soustředí na partnerská pravidla, kolekční role, šablony a varianty cest, kontrolu časových kolizí, rezervační doklady a generátor výtvorů. Dokument zůstává živým implementačním checklistem.
