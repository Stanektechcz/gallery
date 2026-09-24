# Zálohování a obnova

> Dřívější verze tohoto návodu popisovala noční `BackupMetadataJob` a příkazy
> `gallery:backup-metadata`, `gallery:restore-from-backup`, `gallery:rebuild-search`,
> `gallery:deep-integrity-scan` a `gallery:export`. **Žádný z nich neexistoval**
> a databázi nezálohovalo nic. Tahle verze popisuje jen to, co opravdu je.

## Co se zálohuje

| Co                      | Jak                                                                      | Kde                                        |
| ----------------------- | ------------------------------------------------------------------------ | ------------------------------------------ |
| Databáze (všechna data) | `gallery:zaloha`, plánovač denně ve 3:30, drží posledních 14             | `storage/app/private/zalohy/` na serveru   |
| Originály fotek a videí | `gallery:mirror-backlog`, plánovač denně ve 2:40 (je-li připojený cloud) | připojený Google Disk / Dropbox / OneDrive |
| `.env` včetně `APP_KEY` | **ručně** — aplikace ho nezálohuje                                       | mimo server (správce hesel)                |

Zmenšeniny a náhledy se nezálohují: vyrobí se znovu z originálů
(`gallery:thumbnails`, `gallery:videos`).

### Co v záloze databáze není

Záměrně: přihlášená sezení, mezipaměť, fronta úloh, odkazy na obnovu hesla,
protokol běhů plánovače, rozpracovaná nahrávání a protokol stahování programu
kina. Nic z toho po obnově nechybí — lidé se jen přihlásí znovu. Seznam je
v `App\Services\Provoz\ZalohaDatabaze::VYNECHAT`.

### Formát

Záloha nese **data, ne schéma**: gzipovaný NDJSON, první řádek je hlavička (čas,
ovladač, seznam použitých migrací, počty řádků), pak řádek za řádkem
`{"t": "tabulka", "r": {…}}`. Schéma postaví `php artisan migrate` z kódu. Díky
tomu se záloha chová stejně na MySQL i SQLite a test `ZalohaDatabazeTest` ji při
každém běhu testů ověří celým kruhem: data → záloha → smazání → obnova → stejná
data.

Po zápisu se každá záloha přečte znovu a počty řádků se porovnají s hlavičkou;
nesedí-li, soubor se smaže a úloha skončí chybou (uvidíte ji v administraci
u „Záloha databáze").

## ⚠ Záloha leží na stejném serveru

Při ztrátě serveru zmizí s ním. **Stahujte ji pravidelně jinam** — zálohou
v panelu hostingu, nebo ručně:

```bash
scp server:/cesta/k/aplikaci/storage/app/private/zalohy/zaloha-*.ndjson.gz ./
```

Záloha obsahuje deník, finance i zdravotní zápisy. Kdekoli mimo server ji držte
šifrovaně. Šifrované sloupce (např. trezor) jsou v záloze tak, jak leží
v databázi — přečíst je jde jen se stejným `APP_KEY`.

## Obnova

### Ztráta databáze

1. Obnovte `.env` — hlavně `APP_KEY` a přístup k databázi.
2. `php artisan migrate --force` (postaví prázdné schéma).
3. Nahrajte zálohu do `storage/app/private/zalohy/`.
4. Podívejte se, co v ní je — **nic se nezmění**:

   ```bash
   php artisan gallery:obnova                      # výpis dostupných záloh
   php artisan gallery:obnova zalohy/zaloha-….ndjson.gz
   ```

5. Obnovte:

   ```bash
   php artisan gallery:obnova zalohy/zaloha-….ndjson.gz --opravdu
   ```

   Před obnovou se sama zazálohuje současná databáze (`zalohy/pred-obnovou-…`),
   kdyby šlo o špatný soubor. Obnova běží v jedné transakci — buď celá, nebo nic.

Zálohu z **novějšího** kódu obnova odmítne (nesla by tabulky, které tu nejsou) —
nasaďte nejdřív tu verzi. Zálohu ze **staršího** kódu obnoví; sloupec, který
mezitím ze schématu zmizel, přeskočí a vypíše.

### Ztráta přístupu ke cloudu (databáze v pořádku)

1. Metadata i místní náhledy zůstávají dostupné.
2. Znovu připojte cloud v nastavení úložiště.
3. `php artisan gallery:sync-drive` srovná stav s Diskem.
4. `php artisan gallery:mirror-backlog` dokopíruje, co v cloudu chybí.

### Kontrola stavu

`php artisan gallery:doctor` projde disk, databázi, frontu i připojený cloud.

## Varování

Google Disk může patřit školnímu účtu (Educannet). Po ukončení studia se k němu
přístup ztratí i s kopiemi originálů. Připojte druhé úložiště, nebo originály
pravidelně stahujte jinam.
