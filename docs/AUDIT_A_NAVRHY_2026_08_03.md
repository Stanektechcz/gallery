# Audit rozvoje, vylepšení a optimalizace systému

Datum: 3. 8. 2026  
Rozsah: Laravel + Inertia/React aplikace, PWA a moduly pro fotografie, kalendář, plánování, cesty, domácnost, finance, kuchařku, média, vztahové milníky a chatového pomocníka.

## Jak dokument číst

Návrhy jsou řazené podle oblastí a jsou samostatně realizovatelné. `P0` znamená nejvyšší uživatelskou hodnotu nebo riziko nejasného chování, `P1` významné rozšíření, `P2` následné vylepšení. Velikost: `S` do několika dnů, `M` přibližně jeden až dva týdny, `L` větší navazující celek. Neobsahuje návrhy testů; jde o produktový a implementační backlog.

## Zjištění z aktuálního systému

- Systém má mimořádně široký funkční základ: samostatné moduly pro kalendář, cesty, itineráře, cestovní inbox, finance, filmy/seriály, recepty, alba, sdílení, soukromý trezor, PWA a společné plánování.
- Největší riziko není nedostatek funkcí, ale jejich roztříštěnost: stejný záměr může vzniknout v kalendáři, cestách, inboxu, chatu nebo na dashboardu bez jednotného přehledu a návaznosti.
- Chatový panel je vhodný vstupní bod, ale musí používat stejná doménová pravidla jako formuláře. Přímé zápisy do databázových tabulek je nutné nahradit službami jednotlivých modulů.
- PWA již řeší instalaci, offline stav a frontu nahrávání, ale offline práce je zatím orientovaná hlavně na upload, nikoli na plánování a zápisy.
- Přítomnost samostatných modulů `Trips`, `Itinerary`, `Journey`, `Travel inbox` a `Calendar` potvrzuje potřebu jasně vymezit, který z nich je zdroj pravdy pro konkrétní typ dat.

## Doporučené pořadí realizace

1. P0: sjednotit zdroje pravdy, dashboard a kalendář; odstranit slepé a duplicitní zápisy.
2. P0: proměnit chat na řízený vstup do existujících doménových služeb a doplnit univerzální inbox.
3. P1: spojit cestovní, finanční, domácí a mediální tok do konkrétních každodenních scénářů.
4. P1: dokončit mobilní a offline provoz pro nejčastější akce.
5. P2: rozšířit personalizaci, přehledy a dlouhodobou archivaci.

---

## 1. Základ systému a propojení dat

1. **[P0/M] Zavést jednotný záznam „životní událost“.** Každá společná akce, cesta, recept, výdaj, dárek nebo vzpomínka dostane společné `activity_log` ID a odkazy na související entity. Detail události pak ukáže kalendář, fotky, rozpočet, komentáře i historii změn na jednom místě.
2. **[P0/M] Definovat zdroj pravdy pro každou oblast.** `CalendarEvent` bude zdroj termínu, `Trip` zdroj cesty, `TravelInboxItem` pouze pracovní podklad, `Album` zdroj výběru médií a `Journey` příběhový záznam. Ve formulářích vždy zobrazit, kam se data ukládají.
3. **[P0/M] Nahradit přímé SQL zápisy chatového pomocníka doménovými službami.** Chat má volat stejné služby jako kalendář, dárky, cesty a milníky; tím získá validace, oprávnění, notifikace, vazby a jednotné vedlejší efekty.
4. **[P0/S] Přidat do každé entity pole „vytvořeno z“.** Hodnoty jako `manual`, `assistant`, `import`, `calendar`, `mobile` a odkaz na původní záznam vysvětlí vznik dat a usnadní opravy.
5. **[P1/M] Zavést univerzální systém štítků napříč moduly.** Jediný model štítků má propojovat fotografie, události, místa, recepty, cesty, filmy a dárky; například štítek „léto 2026“ zobrazí vše relevantní.
6. **[P1/M] Přidat obousměrné odkazy mezi entitami.** U cesty zobrazit vazby na album, kalendářní události, výdaje, rezervace a itinerář; u alba naopak odkazy na cestu a milníky.
7. **[P1/S] Zobrazovat historii změn jen u společných a důležitých dat.** U rozpočtu, itineráře, dárků a kalendáře uchovat kdo a kdy změnil termín, cenu nebo stav, s možností vrátit jednu změnu.
8. **[P1/M] Přidat koncept „pracovní návrh“ pro nehotová data.** Návrhy z chatu, importu nebo rychlého zápisu nesmí vypadat jako hotové závazné položky; uživatel je může potvrdit, sloučit nebo zahodit.
9. **[P1/S] Zajistit jednotné mazání a archivaci.** Všechny moduly mají používat stejný koš, dobu obnovy a jasnou informaci o dopadu odstranění navázaných dat.
10. **[P2/M] Přidat „souvislosti“ generované pravidly.** Při otevření fotky ze dne cesty nabídnout propojení s cestou, kalendářem a albem, ale nikdy jej neprovádět bez potvrzení.

## 2. Dashboard, kalendář a Náš týden

11. **[P0/M] Udělat z dashboardu živý denní přehled.** Horní část má vždy zobrazit pouze dnešek a nejbližší relevantní termíny, otevřené závazky, nejbližší výročí a nevyřešené rozhodnutí partnerů.
12. **[P0/S] Přidat jednotný stav prázdného dashboardu.** Když nejsou aktuální data, nabídnout konkrétní rychlé akce: naplánovat večer, přidat cestu, vytvořit nákupní úkol nebo napsat do chatu.
13. **[P0/M] Rozlišit „historické“, „dnes“, „nadcházející“ a „čeká na rozhodnutí“.** Tyto čtyři skupiny musí být konzistentní na dashboardu, v kalendáři, v Náš týden i v oznámeních.
14. **[P0/S] Vytvořit přepínač pohledu kalendáře pro mobil.** Mobilní výchozí pohled má být agenda dne/týdne; měsíční mřížka až jako volitelný pohled.
15. **[P0/M] Přidat časovou osu dne.** Zobrazí přesahy, volná okna, cestovní rezervu a konflikty v jednom vertikálním pohledu.
16. **[P1/M] Přidat plánování „najít společné okno“.** Uživatel vybere délku, typ aktivity a časové omezení; systém nabídne konkrétní volné termíny podle obou kalendářů.
17. **[P1/S] Zobrazit příčinu automatického dokončení.** U starší položky má být vidět, že byla dokončena automaticky po termínu, a nabídka otevřít ji znovu.
18. **[P1/M] Přidat blok „tento týden potřebuje rozhodnutí“.** Soustředí pozvánky, dárky, nevybrané filmy, cesty bez dopravy a neodsouhlasené výdaje.
19. **[P1/M] Podporovat opakované společné rituály.** Procházka, filmový večer, společné vaření a kontrola rozpočtu potřebují šablonu s frekvencí, připomínkou a výjimkami.
20. **[P2/S] Přidat režim „klidný den“.** Ztlumí nepodstatné položky a ukáže jen závazky s pevnou hodinou, zdraví, dopravu a kritické připomínky.

## 3. Chatový panel a rychlé ovládání

21. **[P0/M] Zavést katalog příkazů s nápovědou přímo v panelu.** Po napsání `/` se zobrazí vyhledatelný seznam příkazů, stručný popis, vzor a poslední použité příkazy.
22. **[P0/M] Podporovat více akcí v jednom zadání jako návrhový balíček.** Jedna zpráva může vytvořit kalendářní událost, výdaj, aktivitu, albumový návrh a úkol; uživatel potvrdí každý řádek nebo celý balíček.
23. **[P0/S] V náhledu ukázat cílový modul každé akce.** Uživatel musí vidět „Kalendář“, „Cesty“, „Dárky“, „Kuchařka“ nebo „Filmy“, ne jen obecné „uloženo“.
24. **[P0/M] Přidat průvodce nejednoznačností.** Pokud text obsahuje dvě data, dva možné názvy nebo neznámé místo, chat položí jednu konkrétní doplňující otázku místo odhadu.
25. **[P0/M] Přidat `/přidat`, `/otevřít`, `/dnes`, `/týden`, `/úkol`, `/nákup`, `/místo`, `/fotka` a `/pomoc`.** Příkazy mají pokrýt nejčastější navigaci a zápis, se stejnou syntaxí na webu i v PWA.
26. **[P1/M] Vytvořit osobní rychlé bubliny podle používání.** Po opakovaném „přidat výdaj“, „naplánovat film“ nebo „výlet“ se bublina nabídne v horní části panelu.
27. **[P1/M] Umožnit vložení souboru do chatu.** Fotka, PDF rezervace nebo screenshot účtenky se nejprve zobrazí jako příloha návrhu; uživatel pak určí album, cestu, výdaj či událost.
28. **[P1/S] Přidat tlačítko „zopakovat poslední strukturu“.** Například nová cesta se stejnými poli jako poslední cestovní plán, ale bez kopírování starých hodnot.
29. **[P1/M] Zobrazit v chatu stav rozpracovaných návrhů.** Návrh nezmizí po zavření panelu; lze se vrátit k potvrzení, úpravě nebo zaházení.
30. **[P2/M] Zavést lokální pravidla a vlastní zkratky.** Uživatel si nastaví, že „Makinka“ je konkrétní osoba nebo že „naše kavárna“ znamená konkrétní místo, bez nutnosti AI.

## 4. Cesty, itineráře a místa

31. **[P0/M] Sloučit cestovní vstupní bod.** Stránka Cesty má po založení vždy nabídnout termín, trasu, rozpočet, účastníky, album a hlavní cíl; Itinerář zůstane detailní pracovní plochou.
32. **[P0/M] Vytvořit automatické dny cesty.** Z rozsahu dat se založí denní sekce itineráře s lokálním datem, volným místem na program, ubytování a poznámky.
33. **[P0/M] Přidat cestovní kontrolní seznam podle typu cesty.** Auto, letadlo, wellness, zahraničí nebo víkend aktivují relevantní balicí položky, doklady, rezervace a připomínky.
34. **[P0/S] Přidat stav připravenosti cesty na kartu cesty.** Jasné procento rozdělené na dopravu, ubytování, doklady, rozpočet, plán a balení místo jedné neurčité hodnoty.
35. **[P0/M] Navázat cestovní výdaje na rozpočet cesty v reálném čase.** Každý výdaj musí mít kategorii, plátce, stav plán/skutečnost a dopad na limit.
36. **[P1/M] Přidat časovou a geografickou kontrolu itineráře.** Systém označí body, které se časově překrývají nebo jsou v nerealisticky vzdálených místech.
37. **[P1/S] Uložit rezervaci jako strukturovaný objekt.** Doprava, hotel, vstupenka a restaurace mají samostatně termín, potvrzovací kód, dokument, storno podmínky a kontakt.
38. **[P1/M] Vytvořit cestovní „dnešní kartu“.** Během cesty ukáže aktuální den, navigační adresy, příští rezervaci, počasí, místní čas, zbylý rozpočet a rozbalovací seznam věcí.
39. **[P1/M] Přidat sdílené rozhodování nad variantami.** Trasy, doprava a ubytování mají srovnávací kartu s cenou, časem, výhodami, nevýhodami a hlasováním obou partnerů.
40. **[P2/M] Po návratu nabídnout uzavření cesty.** Vytvoří výběr fotek, krátké zhodnocení, finální rozpočet, oblíbená místa a návrh „kam příště“.

## 5. Fotografie, alba, vzpomínky a média

41. **[P0/M] Přidat průvodce po nahrání médií.** Po dávce fotek nabídnout rozdělení podle dne, místa, osob a události; uživatel rozhodne jedním kliknutím, zda vytvořit album nebo jen štítky.
42. **[P0/M] Zavést frontu „čeká na zařazení“.** Fotky bez alba, data, místa nebo oblíbeného označení nezůstanou skryté; dashboard nabídne malou dávku k rychlému třídění.
43. **[P0/S] U každé fotky zviditelnit kontext.** V detailu zobrazit datum, místo, album, cestu, událost, osoby a vztahový milník jako klikatelné čipy.
44. **[P1/M] Přidat návrhy duplicitních alb a událostí.** Pokud dvě alba pokrývají stejné datum, místo a fotografie, nabídnout sloučení s náhledem dopadu.
45. **[P1/M] Vytvořit pracovní režim „výběr pro sdílení“.** Uživatel vybere publikum, maximální počet, kvalitu a datum expirace; systém nabídne nejlepší reprezentativní fotky, nikoli všechno.
46. **[P1/S] Umožnit společné oblíbené napříč alby.** Kromě osobního srdce zavést „naše top fotky“ s možností přidat důvod nebo reakci.
47. **[P1/M] Přidat tematické kolekce.** Například „každý západ slunce“, „naše společné výlety“, „vaření doma“ nebo „fotky s rodinou“ vytvořené z pravidel a ručně upravitelné.
48. **[P1/M] Umožnit přímou vazbu fotky na výdaj či rezervaci.** Účtenka, vstupenka a screenshot objednávky budou mít vedle náhledu i čitelná strukturovaná data.
49. **[P2/M] Vytvořit roční rekapitulaci z vlastních dat.** Výběr vzpomínek, cest, oblíbených míst, společných aktivit a receptů s plnou možností ruční editace.
50. **[P2/S] Přidat časovou kapsli pro album.** Vybrané album nebo dopis lze naplánovat k opětovnému otevření při výročí, narozeninách nebo přesném budoucím datu.

## 6. Kuchařka, jídlo a domácí plánování

51. **[P0/M] Dokončit import receptu z textu a URL.** Vstup má rozlišit název, porce, ingredience, kroky, čas, poznámky a zdroj; nejistá data nechat zvýrazněná k potvrzení.
52. **[P0/M] Přidat plánování vaření z receptu do kalendáře.** Vytvoří vařicí sezení, čas přípravy, zvoleného kuchaře, počet porcí a volitelný nákupní seznam.
53. **[P0/M] Udělat jeden sdílený nákupní seznam.** Ingredience ze zvolených receptů se sloučí podle jednotky, přidají se ruční položky a každá položka má stav „koupit / koupeno / nahradit“.
54. **[P0/S] U receptu ukázat historii vaření.** Kolikrát byl vařen, kdy naposledy, jak chutnal oběma a co příště změnit.
55. **[P1/M] Přidat filtr podle skutečných zásob a trvanlivosti.** Základní domácí spíž umožní návrh receptů, které spotřebují dostupné suroviny.
56. **[P1/S] Přidat režim „vaříme spolu“.** Velké kroky, časovače, rozdělení úkolů a ruční odškrtávání na mobilu bez hledání v dlouhém receptu.
57. **[P1/M] Rozdělit kuchařku na uložené, vyzkoušené a oblíbené recepty.** Každý stav má odlišné informace: zájem, vlastní poznámky, hodnocení a fotky výsledku.
58. **[P1/M] Přidat plán jídel s vazbou na rozpočet.** Týdenní plán ukáže odhad ceny, počet porcí, opakování a zásoby; změna receptu automaticky přepočítá nákup.
59. **[P2/S] Přidat kuchařský archiv sezón.** V létě nabídnout lehká jídla a uchovat „naše léto 2026“ jako editovatelnou sbírku, ne jako automatický závěr.
60. **[P2/M] Zavést dárkové a společenské menu.** Událost může obsahovat menu, recepty, nákup, úkoly a rozdělení přípravy mezi oba.

## 7. Filmy, seriály a společný volný čas

61. **[P0/M] Rozdělit plně filmy a seriály v datech i rozhraní.** Každá větev má vlastní watchlist, stav, hodnocení, tier list a statistiky; společné hledání může výsledky zobrazit vedle sebe.
62. **[P0/M] Uložit hodnocení každého partnera jako samostatný hlas.** Po zhlédnutí se vyplní datum, společné nebo samostatné sledování, škála, komentář a případně oblíbený moment.
63. **[P0/S] Přidat stav „čeká na druhého“.** Jeden partner může titul navrhnout; druhý ho schválí, odmítne nebo označí „později“ bez ztráty návrhu.
64. **[P1/M] Napojit výběr titulu na volný večer v kalendáři.** Z karty filmu vytvořit večer s délkou, službou, místem a připomínkou; bez ručního opisování názvu.
65. **[P1/S] Zobrazit důvod doporučení z vlastních dat.** Například „oba jste vysoko hodnotili dva podobné filmy“, s možností tuto nápovědu skrýt.
66. **[P1/M] Přidat sledování seriálu po epizodách.** Uložit poslední epizodu, stav každého partnera, pauzu, datum návratu a vyhnout se spoilerům.
67. **[P1/S] Dovolit společný tier list s individuálním pohledem.** Výchozí pořadí může být společné, ale vždy má být dostupné osobní pořadí a důvod rozdílu.
68. **[P1/M] Přidat seznam „co sledovat na cestě“.** Výběr offline titulů, délka, platforma, kdo titul navrhl a vazba na konkrétní cestu.
69. **[P2/M] Vytvořit archiv společných večerů.** Kalendářní událost filmu se propojí s titulem, fotkou, receptem a krátkým hodnocením večera.
70. **[P2/S] Přidat tematické losování.** Filtr podle času, nálady, žánru a neviděných titulů; výsledek je pouze návrh a lze ho odmítnout bez změny hodnocení.

## 8. Finance, dárky a výročí

71. **[P0/M] Vytvořit společný finanční přehled založený na skutečných záznamech.** Rozlišit osobní, společné, cestovní a plánované výdaje; zobrazit období, kategorii, plátce a způsob vyrovnání.
72. **[P0/M] Přidat jednoduché vyrovnání mezi partnery.** Při společném výdaji zvolit podíl 50/50, vlastní poměr nebo „dar“; systém ukáže jen čistou výslednou částku k vyrovnání.
73. **[P0/S] U dárku zobrazit časovou osu.** Nápad, výběr, nákup, zabalení, předání a případná připomínka; stav nesmí být jen neurčité „idea“.
74. **[P0/M] Navázat dárek na osobu a příležitost.** Karta osoby ukáže oblíbené věci, minulá obdarování, limity a blížící se data bez odhalení soukromých překvapení druhému partnerovi.
75. **[P1/M] Přidat roční rozpočet na dárky a společné cíle.** U každé příležitosti zobrazit plán, skutečnost a zbývající rozpočet; historii nelze míchat s aktuálním rokem.
76. **[P1/S] Vytvořit centrum výročí.** Vedle data ukáže nápady, rozpočet, volné termíny, společné vzpomínky z minulých let a stav příprav.
77. **[P1/M] Podporovat připomínky v návaznosti na přípravu.** Například 30 dní vybrat nápad, 14 dní objednat, 3 dny zabalit; každý krok lze ručně vypnout.
78. **[P1/M] Přidat import a třídění účtenek.** Fotka účtenky se uloží k výdaji, rozpoznané položky uživatel opraví a výdaj se propojí s cestou či událostí.
79. **[P2/S] Přidat přehled pravidelných společných plateb.** Název, frekvence, částka, další splatnost, plátce a připomínka ke kontrole ceny.
80. **[P2/M] Vytvořit cíle úspor s konkrétním účelem.** Cesta, dárek nebo domácí projekt mají vlastní cíl, příspěvky obou a transparentní stav bez nutnosti bankovního napojení.

## 9. Lidé, místa, vztah a společné rozhodování

81. **[P0/M] Přidat jednotnou kartu člověka.** Obsahuje vztah, narozeniny, dárkové nápady, oblíbená místa, společné fotky, pozvánky a soukromé poznámky podle oprávnění.
82. **[P0/M] Vytvořit životní cyklus místa.** Místo může být nápad, plán, navštívené, oblíbené nebo „už ne“; vždy s poslední návštěvou, hodnocením, cenou a poznámkou pro příště.
83. **[P0/S] Přidat rychlé rozhodnutí nad místem.** Z návrhu restaurace nebo výletu lze jedním krokem hlasovat, naplánovat termín nebo uložit na později.
84. **[P1/M] Umožnit osobní a společné poznámky k místům.** Každý může psát vlastní poznámku, vedle ní existuje společná; aplikace je nikdy nesmí nechtěně smíchat.
85. **[P1/S] Přidat měřítko kvality návštěvy.** Po návštěvě stačí rychle vyplnit „chtěli bychom znovu“, cena, atmosféra a co příště; data se projeví v doporučeních.
86. **[P1/M] Vytvořit seznam společných přání.** Přání lze přiřadit k cestě, času, ceně, místu nebo ročnímu období a partner jej může označit jako důležité.
87. **[P1/M] Přidat „malá rozhodnutí“ na dashboard.** Jednoduché binární nebo výběrové otázky s termínem vypršení: kam v sobotu, který film, zda objednat dárek.
88. **[P1/S] Dovolit delegaci úkolu s kontextem.** Úkol má jasného vlastníka, datum, vazbu na cestu/událost a možnost odeslat druhému partnerovi pouze relevantní upozornění.
89. **[P2/M] Přidat mapu společných míst.** Filtry podle období, typu, hodnocení a cesty vytvoří osobní mapu; soukromá místa musí jít skrýt.
90. **[P2/S] Vytvořit návrh „zopakovat oblíbený den“.** Z historické události sestaví kopii plánu včetně místa, délky, receptu či filmu, ale bez kopírování minulých výdajů.

## 10. Mobilní UX, přístupnost a vizuální systém

91. **[P0/M] Stanovit jednotný mobilní vzor pro všechny podstránky.** Horní název, kontextový filtr, primární akce, obsah v jedné kolonce a spodní bezpečná zóna; kalendář a cesty mají přednost.
92. **[P0/S] Zvětšit cíle ovládání a oddělit destruktivní akce.** Ikony bez textu musí mít přístupný název, minimální dotykovou plochu a odstranění musí být vizuálně odlišeno od „zrušit“.
93. **[P0/M] Vytvořit jednotné prázdné stavy.** Každý prázdný seznam má říct proč je prázdný, co se zde objeví a nabídnout jednu relevantní akci.
94. **[P0/M] Přidat plnošířkové obsahové rozvržení.** U desktopu má hlavní pracovní plocha po sidebaru využít dostupnou šířku; úzké karty zůstanou jen u čtecích detailů.
95. **[P1/S] Udržet akční tlačítka stále dostupná na mobilu.** Formuláře kalendáře, cesty, vaření a chatu získají sticky patičku s uložením a zrušením.
96. **[P1/M] Zavést návrhový systém pro husté formuláře.** Dlouhé formuláře se rozdělí do sekcí s okamžitým souhrnem; nic se neztratí při otočení telefonu nebo návratu.
97. **[P1/S] Doplnit klávesové zkratky na desktopu.** Například `/` otevře chat, `g c` kalendář, `g t` cesty, `n` nový záznam a `Esc` zavře panel; zkratky budou zobrazitelné v nápovědě.
98. **[P1/M] Zpřístupnit všechny grafické informace textem.** Barva termínu, energie, stav balení nebo tier list musí mít popisek, ikonku a ne jen barevné rozlišení.
99. **[P2/S] Přidat volbu hustoty rozhraní.** Komfortní, standardní a kompaktní pohled pro různé velikosti displeje a preferovaný styl práce.
100. **[P2/M] Přidat osobní domovskou stránku.** Každý partner si určí pořadí widgetů a výchozí sekci, aniž by se změnil sdílený obsah.

## 11. PWA, offline provoz a výkon

101. **[P0/M] Rozšířit offline frontu na zápisy, ne jen upload.** Kalendář, úkol, reakce, nákupní položka a chatový návrh se lokálně uloží a po připojení bezpečně odešle v původním pořadí.
102. **[P0/M] Zobrazit stav synchronizace u každé rozpracované položky.** Uživatel musí vidět „uloženo v zařízení“, „čeká na synchronizaci“, „synchronizováno“ nebo „vyžaduje vyřešení“.
103. **[P0/S] Přidat konfliktový dialog pro offline úpravy.** Pokud mezitím druhý partner změnil stejný termín či itinerář, aplikace ukáže porovnání polí a možnost vybrat hodnotu.
104. **[P0/M] Upravit service worker podle strategií obsahu.** Aplikační shell cache-first, API a aktuální dashboard network-first s rozumným fallbackem, média podle velikosti a soukromí nikdy neukládat do veřejné cache.
105. **[P1/M] Zavést postupné načítání velkých mediálních seznamů.** Použít stránkování/nekonečné načítání, virtualizaci mřížek a načítání náhledů až při přiblížení k viewportu.
106. **[P1/S] Optimalizovat hlavní balíček podle skutečných vstupních bodů.** Těžší mapy, tisk, plán cesty, editor příběhu a bankovní obrazovky načítat až při otevření příslušné stránky.
107. **[P1/M] Přidat režim úspory dat.** Na mobilní síti načítat jen náhledy nižší kvality, pozastavit video autoplay a zpozdit přednačítání velkých alb.
108. **[P1/S] Zobrazit správu úložiště PWA.** Uživatel uvidí velikost cache, offline data a může bezpečně vyčistit pouze lokální kopie bez smazání serverových médií.
109. **[P2/M] Podporovat rychlé přidání z domovské obrazovky.** Zástupce aplikace pro „Nová fotka“, „Nový výdaj“, „Naplánovat“ a „Otevřít chat“ otevřou konkrétní tok.
110. **[P2/S] Přidat přechodovou obrazovku při aktualizaci PWA.** Nová verze se nabídne až po dokončení rozpracovaného uploadu či formuláře, ne náhlým přenačtením.

## 12. Soukromí, integrace, správa a dlouhodobá kvalita

111. **[P0/M] Zpřesnit oprávnění na úrovni obsahu.** Každá položka má mít jasné `společné`, `jen já`, `viditelné po pozvání` a případně omezené sdílení; toto nastavení ukazovat přímo ve formuláři.
112. **[P0/M] Zavést bezpečný export vlastních dat.** Výběr období a modulů vytvoří srozumitelný balíček médií, CSV/JSON metadat a přehled vazeb; export nesmí obsahovat soukromé položky druhého partnera bez oprávnění.
113. **[P0/S] Přidat centrum připojení.** Google Drive, kalendáře, bankovní importy a budoucí zdroje mají jednotné místo se stavem poslední synchronizace, rozsahem dat a tlačítkem odpojit.
114. **[P1/M] Zavést importní schránku pro všechny externí podklady.** E-mailové rezervace, odkazy, PDF, screenshoty a sdílený obsah nejprve přijdou do inboxu, kde se přiřadí k cestě, události či albu.
115. **[P1/M] Přidat plán archivace médií.** Rozlišit originál, náhled, cloudovou kopii a lokální kopii; u každého alba jasně ukázat stav zálohy a možné riziko.
116. **[P1/S] Umožnit nastavit pravidla notifikací podle typu, ne jen globálně.** Každý partner si zvolí kalendář, výdaje, dárky, cesty a fotky; kritické věci se definují zvlášť.
117. **[P1/M] Vytvořit provozní přehled pro správce.** Zobrazí kapacitu úložiště, čekající zpracování médií, selhané importy, nevyřízené sdílení a stav integrací bez zobrazení soukromého obsahu.
118. **[P1/M] Přidat čitelný registr automatizací.** Každé automatické dokončení, připomínka, návrh alba nebo pravidlo má mít zdroj, podmínky, poslední provedení a vypínač.
119. **[P2/M] Definovat rozhraní pro budoucí integrace přes interní události.** Modul pošle standardní událost typu `trip.created`, `recipe.cooked` nebo `gift.purchased`; další funkce se napojí bez kopírování logiky.
120. **[P2/M] Vytvořit kvartální produktový přehled.** Systém sám z vlastních anonymizovaných metrik používání ukáže nevyužité moduly, nejčastější nedokončené toky a návrhy na zjednodušení, nikoli pouhé počty kliknutí.

## Nejlepší první balíček k realizaci

1. Jednotný zdroj pravdy a společný log událostí (1–4).
2. Živý dashboard, mobilní agenda a prázdné stavy (11–15).
3. Chat jako bezpečný orchestrátor s návrhovými balíčky (21–25).
4. Cesta jako jeden propojený pracovní tok včetně dnů, rozpočtu a balení (31–35).
5. Offline zápisy a srozumitelná synchronizace (101–104).

Tento balíček zlepší každodenní používání dříve, než se budou přidávat další izolované stránky. Další návrhy jsou navržené tak, aby se na něj mohly přímo napojit.