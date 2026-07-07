# Roadmapa rozvoje MAKI Gallery — 10 fází

## Cíl produktu

Propojit galerii, místa, itineráře, cesty a vzpomínky do jednoho životního archivu. Uživatel má být schopný cestu naplánovat, během ní pohodlně sbírat fotografie a místa a po návratu automaticky získat hotový příběh, mapu a vzpomínku ke sdílení.

Každá fáze končí nasaditelným přírůstkem. Pořadí zohledňuje datové závislosti; fáze 1–3 tvoří základ, na kterém mohou další části vznikat bezpečně.

## 1. Stabilizace základu a jednotný doménový model

**Výsledek:** spolehlivý základ bez duplicitních významů mezi cestou, itinerářem, místem a událostí.

- Popsat životní cyklus `Návrh → Naplánováno → Probíhá → Dokončeno → Vzpomínka`.
- Sjednotit entity Trip, Itinerary, Place, Journey Event, Album a Media Item pomocí jasných vazeb.
- Zavést API kontrakty, transakce, verzování změn a auditní události.
- Doplnit automatické testy ukládání, řazení, oprávnění a obnovy dat.
- Přidat měření chyb, výkonu a front úloh.

**Hotovo, když:** klíčové zápisy jsou atomické, API má integrační testy a žádná obrazovka nepočítá stejná data odlišným způsobem.

## 2. Nové responzivní informační uspořádání

**Výsledek:** konzistentní desktopové i mobilní ovládání celé aplikace.

- Sjednotit hlavičky, panely, dialogy, formuláře, prázdné stavy a zpětnou vazbu ukládání.
- Zavést design tokeny, přístupné kontrasty, dotykové cíle alespoň 44 px a stav focus.
- Na mobilu použít model seznam → detail, spodní navigaci a vysouvací filtry.
- Přidat skeletony, optimistické změny a srozumitelné řešení chyb/offline stavu.
- Ověřit klíčové obrazovky na šířkách 360, 768, 1024 a 1440 px.

**Hotovo, když:** všechny hlavní úlohy lze dokončit jednou rukou na telefonu bez horizontálního posouvání.

## 3. Galerie 2.0 a jednotná časová osa

**Výsledek:** rychlá galerie, která automaticky organizuje obsah podle času, místa a událostí.

- Virtuální načítání velkých knihoven, chytré seskupování a plynulé filtry.
- Kombinované filtrování podle data, osob, míst, tagů, typu média, cesty a hodnocení.
- Uložené pohledy a chytrá alba s živým náhledem výsledků.
- Hromadné úpravy, skládání podobných snímků a lepší práce s RAW/video soubory.
- Jednotná časová osa propojující média, cesty a významné události.

**Hotovo, když:** uživatel najde libovolnou skupinu fotografií pomocí kombinace filtrů a může ji uložit jako živou kolekci.

## 4. Plánovač cest a multimodální doprava

**Výsledek:** plnohodnotné sestavení trasy s více místy a porovnáním dopravy.

- Hromadné vložení zastávek, změna pořadí, dny cesty a časová okna.
- Filtrace auto/vlak/autobus/letadlo/pěšky/kolo/loď, kombinace režimů po úsecích.
- Reálná vzdálenost, délka, cena, přestupy a přímé odkazy na nákup.
- Varianty trasy „nejrychlejší“, „nejlevnější“, „nejméně přesunů“ a „bez auta“.
- Uložení snapshotu nabídky a upozornění na změnu ceny nebo času.

**Hotovo, když:** lze jedním průchodem naplánovat vícedenní trasu, porovnat varianty a bezpečně ji uložit.

## 5. Itinerář dne a spolupráce

**Výsledek:** cesta se mění z mapy zastávek na použitelný plán každého dne.

- Denní bloky, aktivity, rezervace, otevírací doby, rozpočet a vlastní checklisty.
- Drag-and-drop mezi dny, automatické přepočítání přesunů a varování před kolizí.
- Pozvánky členů, role, komentáře, návrhy změn a historie verzí.
- Export do kalendáře, tisknutelné PDF a sdílený odkaz bez přihlášení.
- Napojení jízdenek a rezervací na konkrétní úsek nebo aktivitu.

**Hotovo, když:** dva lidé společně připraví cestu a každý vidí aktuální plán i změny.

## 6. Režim „Na cestě“ a offline PWA

**Výsledek:** itinerář a sběr obsahu fungují spolehlivě i bez signálu.

- Offline balíček cesty: plán, mapa, adresy, rezervace a důležité kontakty.
- Rychlé přidání fotografie, poznámky, výdaje nebo navštíveného místa.
- Fronta změn s bezpečnou synchronizací a řešením konfliktů po připojení.
- Aktuální karta „Co následuje“, navigace k místu a připomenutí odjezdu.
- Úsporný režim dat a baterie.

**Hotovo, když:** uživatel projde celý den podle staženého itineráře v režimu letadlo a změny se později synchronizují bez ztráty.

## 7. Automatické zpracování dokončené cesty

**Výsledek:** po návratu vznikne uspořádané album a cestovní příběh s minimem práce.

- Automaticky spojit média podle času, GPS, zastávek a členů cesty.
- Detekovat chybějící nebo nesprávně časově posunutá média.
- Navrhnout titulní fotografii, kapitoly, mapu, statistiky a denní výběry.
- Umožnit schválit návrhy hromadně, ale vždy zachovat ruční kontrolu.
- Generovat cestovní rekapitulaci a podklady pro fotoknihu.

**Hotovo, když:** dokončená cesta nabídne během několika minut editovatelný návrh příběhu a alba.

## 8. Vzpomínky a osobní příběhy

**Výsledek:** systém smysluplně vrací starší obsah, nejen náhodné fotografie.

- Typy vzpomínek: „před rokem“, stejné místo, stejná osoba, výročí cesty a proměna v čase.
- Skóre kvality kombinující oblíbené, reakce, pestrost, ostrost a reprezentativnost.
- Editor příběhu s fotografiemi, mapou, textem, hudbou a časovou osou.
- Frekvence a citlivé filtry v nastavení; možnost skrýt osobu, období či událost.
- Soukromé sdílení vzpomínky a reakce blízkých.

**Hotovo, když:** každý návrh vzpomínky lze vysvětlit, upravit, odmítnout a ovlivnit budoucí doporučení.

## 9. Vyhledávání, mapa znalostí a doporučení

**Výsledek:** přirozené hledání napříč celým archivem a chytré návrhy dalších akcí.

- Jeden vyhledávací index pro média, osoby, místa, cesty, poznámky a rezervace.
- Kombinované dotazy typu „fotky z hor s Maki v létě 2025“.
- Prostorová mapa s clustery, navštívenými oblastmi a časovým posuvníkem.
- Návrhy: doplnit chybějící místo, sloučit duplicitu, vytvořit album nebo vzpomínku.
- U doporučení vždy zobrazit důvod a umožnit korekci.

**Hotovo, když:** výsledek hledání propojí související cestu, místa, lidi i média a reaguje v interaktivním čase.

## 10. Sdílení, automatizace a dlouhodobý provoz

**Výsledek:** hotový, bezpečný systém připravený růst bez ruční údržby.

- Sdílené kolekce s expirací, heslem, oprávněním ke stažení a auditním logem.
- Automatické workflow: import → metadata → návrh alba → vzpomínka → notifikace.
- Zálohy, obnova po havárii, kontrola integrity originálů a migrační scénáře.
- Výkonové rozpočty, dostupnost, metriky používání a pravidla mazání dat.
- Dokumentace pro provoz, podporu a další vývoj; řízené beta vydání a sběr zpětné vazby.

**Hotovo, když:** obnovu lze pravidelně otestovat, klíčové procesy jsou měřené a nové funkce lze vydávat postupně přes feature flags.

## Doporučené řízení realizace

- Každou fázi rozdělit na krátký discovery krok, technický návrh, implementaci, testování a pilotní nasazení.
- Udržovat jeden měřitelný produktový ukazatel pro každou fázi; například čas k vytvoření trasy, podíl automaticky správně přiřazených médií nebo úspěšnost offline synchronizace.
- Funkce vydávat po svislých řezech od databáze po mobilní UI, ne jako oddělené dlouhé backend/frontend projekty.
- Po fázích 3, 6 a 8 zařadit uživatelské ověření a podle výsledků upravit další priority.

## Nejbližší implementační balíček

Po dokončení aktuální opravy tras doporučuji navázat fází 1 v tomto pořadí: doménová mapa a API kontrakty, testovací matice kritických zápisů, audit událostí, sjednocení chybových odpovědí a základ provozních metrik. Tím se sníží riziko, že nové funkce z dalších fází prohloubí současné datové rozdíly.
