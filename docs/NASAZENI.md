# Nasazení na produkci — kontrolní seznam

Stav k 27. 9. 2026 (kola 43–47). Server: aaPanel, PHP-FPM s `open_basedir`,
PHP **≥ 8.4.1** (systémové `php` je 8.1), `shell_exec`/`exec` vypnuté,
MySQL 8. Nastavení serveru (vhost, cron, workery) je v
[`DEPLOYMENT_ISPCONFIG.md`](../DEPLOYMENT_ISPCONFIG.md); tady je postup
jednoho nasazení. V příkazech `php` = binárka PHP 8.4, např.
`/www/server/php/84/bin/php`.

## Před nasazením

- [ ] CI na `main` je zelené — **obě** úlohy (SQLite i MySQL 8).
- [ ] Na serveru: `php artisan migrate:status` — které migrace čekají.
      Od kola 41 jich přibylo devět (poslední `2026_09_29_130000`:
      `users.access_revoked_at`, `media_items.trash_approved_alone_at`);
      nejdelší jsou tři přestavby `media_items`
      (`2026_09_27_100000`, `…_120000`), u 20 000 fotek jednotky až desítky
      sekund, zápisy během nich stojí — nasazovat v klidné chvíli.
- [ ] MySQL, rychlé kontroly:
      - `SELECT COUNT(*) FROM scheduled_task_runs;` (index na ní — u milionů řádků minuty)
      - `SELECT COUNT(*) FROM media_items WHERE CAST(taken_at AS CHAR) LIKE '0000%';` → musí být 0
      - `SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL;` → neběží převod videa (drží zámek tabulky)
- [ ] `.env` — povinné (`galerie:pred-nasazenim` jinak nasazení zastaví):
      `APP_ENV=production`, `APP_DEBUG=false`, **`APP_KEY` beze změny**
      (migrace dešifrují sdílený stav), `APP_URL=https://gallery.stanektech.cz`,
      `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=database`,
      `CACHE_STORE=database`, `DB_*` (`DB_COLLATION` nenastavovat).
- [ ] `.env` — bez toho něco nefunguje:
      - `MAIL_MAILER=smtp` + `MAIL_*` — jinak nechodí obnova hesla ani pozvánky
      - `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` — jinak nechodí upozornění;
        **nepřegenerovat**, pokud už jsou (odběry by osiřely)
      - `SANCTUM_STATEFUL_DOMAINS` **nenastavovat** (výchozí bere host z `APP_URL`),
        nebo s `gallery.stanektech.cz` — vývojové `:8765` by na produkci
        rozbilo odemčení trezoru
      - `GALERIE_RP_ID`, `GALERIE_RP_ORIGINS` prázdné nebo produkční původ (otisk prstu)
      - `FFMPEG_PATH`, `FFPROBE_PATH`, `EXIFTOOL_PATH` — absolutní cesty
- [ ] `.env` — doporučené: `APP_LOCALE=cs`, `APP_TIMEZONE=Europe/Prague`,
      `LOG_LEVEL=warning`, `GALLERY_OWNER_EMAIL`, registrace zavřená,
      `DB_QUEUE_RETRY_AFTER=3900`, `VIDEO_ENCODER=auto` (bez GPU klidně `libx264`).

## Nasazení

```bash
cd <aplikace> && PHP_BIN=/www/server/php/84/bin/php ./deploy.sh
```

- Skript vypíše **předchozí verzi (pro návrat)** — zapsat si ji.
- Běží v režimu údržby: `down` → `git pull` → composer (jen při změně
  zámku) → `config:clear` → kontrola → záloha databáze → migrace →
  `optimize:clear` → `queue:restart` → reload PHP-FPM → `up`.
- Když skript PHP-FPM nenačte (vypíše to), udělat ručně **před** koncem
  údržby: `/etc/init.d/php-fpm-84 reload`.
- Selhání uprostřed nechá aplikaci v údržbě schválně a vypíše krok; opravit
  příčinu a pustit znovu. Zápisy z telefonů se během údržby neztrácejí.

## Po nasazení (jednou, ručně)

1. `php artisan gallery:doctor` — fronta, plánovač, `proc_open`, `pcntl`
   v CLI, ffmpeg/ffprobe/exiftool, `/sw.js` a `/offline.html`, mazání
   v cloudu, **trezor v cloudu** (dokud se neuklidí bod 3, hlásí chybu).
2. Výpis cronu na konci `deploy.sh`: absolutní PHP 8.4 a **uživatel webu**
   (ne root — jinak `storage/logs` patří rootovi a web padá na 500).
3. Trezor pryč z cloudu (rozhodnutí 27. 9.):
   `php artisan gallery:trezor-z-cloudu` (jen spočítá) → pak
   `php artisan gallery:trezor-z-cloudu --provest`. Maže jen kopie fotek,
   jejichž originál na serveru je ověřený; neověřené vypíše a nechá.
4. Hledání: `php artisan gallery:rebuild-search` — složí index hledání
   všem fotkám (místo, alba, měsíc, rok, bez diakritiky).
5. Videa: `php artisan gallery:videa-bez-polohy`, pak s `--provest`;
   `php artisan gallery:videos --compat` pro videa bez kopie k přehrávání.
6. `php artisan gallery:cloud-mazani` — stav mazání kopií (s `--znovu`
   vrátí selhaná do fronty).
7. Webový server: hlavička CSP pro `mapa.html` a `offline.html`
   (ukázka pro Apache i nginx v `DEPLOYMENT_ISPCONFIG.md`), ověřit
   `curl -I https://gallery.stanektech.cz/mapa.html`. Na nginx musí
   `location ~ ^/(mapa|offline)\.html$` stát před ostatními regulárními
   `location` a `/sw.js` musí dojít do Laravelu.
8. Jen pokud běží stálé workery (systemd): přidat `gallery-queue@heavy`.
9. `php artisan gallery:ukazkova-data` — co je ukázka; po kontrole `--smazat`.

## Ověření v prohlížeči (počítač i telefon)

Na telefonu nejdřív zavřít a znovu otevřít aplikaci (starý service worker).

- [ ] Přihlášení heslem i otiskem, obnova stránky — v konzoli žádné 401/500.
- [ ] Staré adresy (`/prehled`, `/timeline`) vedou do aplikace.
- [ ] Hledání: najde i starou fotku (ne jen posledních 240), bez diakritiky
      („lyse hore"), podle měsíce („srpen 2025").
- [ ] Trezor: odemknout, zamknout na jednom zařízení → zavřený i na druhém;
      přesunutá fotka zmizí z Disku (do pár minut).
- [ ] „Do koše" = návrh, druhý schválí; s odebraným přístupem partnera
      nabídne vlastníkovi „Schválit sám".
- [ ] Reakce 👍 a 😂 na tutéž zprávu vedle sebe.
- [ ] Mapa kreslí dlaždice i špendlíky; video z telefonu: náhled hned,
      kopie k přehrávání do pár minut.
- [ ] Upozornění dorazí na oba telefony; e-mail obnovy hesla dorazí.
- [ ] Import výpisu z banky — platby se spárují s cestou podle kódu rezervace.

## Návrat zpět

- **Jen kód (doporučeno):** `php artisan down`, `git checkout <předchozí verze>`,
  `php artisan optimize:clear`, reload PHP-FPM, `php artisan up`. Schéma
  je rozšiřující (nové sloupce a tabulky), starý kód s ním běží.
- **Databáze:** záloha z `deploy.sh` leží v `storage/app/private/zalohy`,
  obnova `php artisan gallery:obnova` — **ztratí zápisy po nasazení**.
  Média se nezálohují.
- `migrate:rollback` vrací celou dávku (smaže návrhy mazání,
  `cloud_copy_deletions`; převod `taken_at` odmítne, když jsou data mimo
  1970–2038). Nevratné datové migrace: komentáře ze stavu, archiv alb,
  oddělení duplicit, skryté dárky, emoji `utf8mb4_bin`, jména v protokolu
  trezoru.
