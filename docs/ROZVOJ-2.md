# Rozvoj — druhá řada fází

Navazuje na `ROZVOJ.md`, jehož čtyři fáze jsou hotové. Pořadí není libovolné: každá fáze
odstraňuje důvod, proč nejde udělat ta další.

## Fáze 5 — úložiště, které vydrží

Bez tohohle je připojený cloud jen slib. Dropbox má autorizaci, ale token vyprší za čtyři
hodiny a nikdo ho neobnoví.

1. **Obnova tokenů** — jedna služba pro všechny cloudy, obnova před vypršením, zápis chyby
   do `storage_connections` místo tichého selhání. *(Bez toho je vše ostatní dočasné.)*
2. **Zápis souborů do Dropboxu** — adaptér vedle stávajícího Drive, přes `StorageResolver`.
3. **OneDrive OAuth** — stejný tvar jako Dropbox, Microsoft Graph.
4. **Přenos knihovny mezi úložišti** — dávkově, s možností pokračovat po přerušení.

## Fáze 6 — provoz SaaS

Co chybí k tomu, aby systém šel provozovat, ne jen používat.

1. **Fakturace** — doklad k platbě, číselná řada, PDF.
2. **Upomínky** — vypršení tarifu, neúspěšná platba, blížící se konec zkušebního období.
3. **Onboarding** — první přihlášení vede k prvnímu albu, ne k prázdné obrazovce.
4. **Stavová stránka** — fronta, úložiště, poslední chyby, veřejně.

## Fáze 7 — dokončení služeb

1. **AFFiNE** — dnes jen uloží credential; chybí klient a synchronizace.
2. **Discord** — webhooky fungují; stav uživatele vyžaduje démona (viz `DISCORD.md`).
3. **Facebook import** — potřebuje schválení oprávnění od Meta, počítat v týdnech.
4. **Passkeys** — blokované `ext-gd` lokálně; nutné ověřit na skutečném zařízení.

## Fáze 8 — údržba, kterou nikdo nezadá

1. **Křehké testy** — indexy místo vyhledávání podle jména. Dvakrát způsobily falešné
   selhání (`IntegrationSettingsTest`, `SaasModulesTest`). Projít celou sadu.
2. **`thecodingmachine/safe`** — zastaralé stuby zaplavují každý příkaz a kazí `route:list`.
   Vyžaduje `composer update`, blokované chybějícím `ext-gd`.
3. **Neověřené kusy** — `gallery:memories` proti reálným datům, nahrávání avataru na
   produkci.

## Pravidlo pro každou fázi

Nic se neoznačí za hotové bez spuštění proti běžícímu kódu. Lint a typová kontrola
dokazují, že se to přeloží — ne že to funguje.
