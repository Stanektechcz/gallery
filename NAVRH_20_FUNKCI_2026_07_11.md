# 20 funkcí pro cesty, akce a společné vzpomínky

Návrh je řazený podle přínosu pro český pár: méně ručního přepisování, spolehlivější příprava cesty a rychlejší návrat ke společným vzpomínkám.

## První vlna — kalendář a připomínky

1. **Události v kalendáři** — samostatné akce vedle cest: výlet, koncert, návštěva, narozeniny, rezervace nebo vlastní typ.
2. **Připomínky ve třech kanálech** — web push, PWA/mobilní upozornění a e-mail; každá připomínka má čas, časové pásmo a potvrzení doručení.
3. **Checklist před akcí** — sdílené balení, doklady, dárky, úkoly a kdo je má na starost.
4. **Rezervace a přílohy** — QR, PDF, potvrzení, kontakty a termín propadnutí u jedné události.
5. **Inteligentní odjezd** — připomínka „vyrazit za 20 minut“ podle místa, dopravy a ručně nastavené rezervy.
6. **Opakované rodinné akce** — výročí, narozeniny, pravidelné výlety a servisy s výjimkami a připomínkami.

## Druhá vlna — cesta bez zmatku

7. **Sdílený cestovní inbox** — z jedné obrazovky přijaté odkazy, screenshoty, rezervace a nápady; jedním klikem se přiřadí ke konkrétní cestě.
8. **Rozpočet plán versus skutečnost** — kategorie, společné i osobní výdaje, měny, vyrovnání mezi partnery a upozornění na limit.
9. **Kontrola kolizí** — překryvy aktivit, nereálné přesuny, zavřeno v cílový den a chybějící rezervace.
10. **Varianty trasy** — uložené scénáře nejlevněji, nejrychleji, bez auta, pohodově a deštivá varianta dne.
11. **Offline balíček cesty** — plán, QR doklady, důležité kontakty, vybrané mapové oblasti a seznam věcí dostupné bez sítě.
12. **Záchranná karta cesty** — adresa ubytování, pojištění, konzulát, nouzové kontakty, čísla rezervací a sdílitelný souhrn.

## Třetí vlna — automatické vzpomínky

13. **Automatické přiřazení médií k akci** — podle data, GPS a lidí, vždy s návrhem ke schválení.
14. **Příběh akce jedním klikem** — kapitoly dne, trasa, vybrané fotografie, poznámky a rozpočet ve sdílitelném výstupu.
15. **Časová kapsle** — naplánovaná připomínka společné vzpomínky za rok, pět let nebo k výročí.
16. **„Co jsme ještě neviděli?“** — propojí wishlist míst s volnými víkendy a rozpočtem.
17. **Dvojitá fotka v čase** — návrh zopakovat snímek ze stejného místa a zobrazení před/teď.
18. **Rychlý deník z hlasu** — hlasová poznámka se přepíše, doplní čas a místo a uloží k cestě.

## Čtvrtá vlna — důvěra a dlouhodobá efektivita

19. **Pravidla partner-sharingu** — automaticky nabídnout druhému partnerovi média z vybrané cesty, období nebo s konkrétními lidmi; vždy s náhledem pravidla.
20. **Týdenní společný přehled** — jedna soukromá karta: příští akce, nevyřízené úkoly, výročí, rozpočet a 1–2 nové vzpomínky.

## Doporučené pořadí implementace

Začít body 1–6 jako jeden celek „Kalendář a připomínky“. Datově potřebuje `events`, `event_reminders`, `event_tasks` a `event_attachments`; cestu lze navázat nepovinným `trip_id`. PWA upozornění je vhodné stavět až po jasném opt-in toku a serverovém workeru, aby se připomínky neomezily jen na otevřený prohlížeč.
