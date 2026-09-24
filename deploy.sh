#!/usr/bin/env bash
#
# Nasazení MAKI Gallery na produkci.
#
# Vznikl proto, že se nasazení jednou zaseklo v půlce: příkazy byly zřetězené přes &&
# za `npm run build`, ten na serveru spadnout musel — a migrace, které stály za ním, se
# tím pádem nespustily. Nahrávání pak den hlásilo SQL chybu, protože kód čekal sloupec,
# který v databázi nebyl.
#
# Pořadí kroků z toho vychází dodnes: **nejdřív se udělá to, bez čeho aplikace neběží**
# (závislosti, migrace, cache) a teprve pak to, co se dá přežít (build assetů, kontrola
# stavu). Build ani doctor nesmí zabránit migraci.
#
# Použití:
#     ./deploy.sh
#     PHP_BIN=/cesta/k/php ./deploy.sh          # když se PHP nenajde samo
#     COMPOSER_BIN=/cesta/k/composer ./deploy.sh
set -euo pipefail

# ——— PHP ———
#
# Systémové `php` je na tomhle serveru 8.1 a aplikace potřebuje aspoň 8.4.1
# (`bootstrap/preflight.php`). Bez tohohle hledání spadne každý `artisan` na hlášku
# o starém PHP — a `composer`, který je jen PHAR, by se spustil pod tímtéž starým
# PHP a odmítl by nainstalovat balíčky.
#
# Vybírá se **nejnovější** vyhovující, ne první nalezené: na panelu bývá vedle sebe
# několik verzí a po upgradu má nasazení jet na té nové, aniž by to někdo přepisoval.
PHP_MIN=80401

# Číslo verze, nebo 0.
#
# `-n` je tu podstatné: bez něj PHP načte php.ini, a když v něm stojí rozšíření
# zkompilované pro jinou verzi (na tomhle serveru jich je pět), vypíše před odpověď
# odstavec varování. Ta varování chodí na výstup, ne na chybový výstup, takže by se
# do proměnné dostalo „PHP Warning: … 20210902 … 80401" — a porovnání čísel by pod
# `set -e` celý skript shodilo. `-n` php.ini přeskočí; verze je zkompilovaná uvnitř
# a žádné rozšíření k jejímu zjištění není potřeba.
verze_php() {
    local vysledek
    vysledek="$("$1" -n -r 'echo PHP_VERSION_ID;' 2>/dev/null || true)"

    case "$vysledek" in
        '' | *[!0-9]*) echo 0 ;;
        *) echo "$vysledek" ;;
    esac
}

popis_php() {
    "$1" -n -r 'echo PHP_VERSION;' 2>/dev/null || echo '?'
}

najdi_php() {
    if [ -n "${PHP_BIN:-}" ]; then
        echo "$PHP_BIN"
        return
    fi

    local nejlepsi=''
    local nejvyssi=0
    local kandidat verze

    for kandidat in /www/server/php/*/bin/php /usr/local/php/*/bin/php "$(command -v php || true)"; do
        [ -x "$kandidat" ] || continue
        verze="$(verze_php "$kandidat")"

        if [ "$verze" -gt "$nejvyssi" ]; then
            nejvyssi="$verze"
            nejlepsi="$kandidat"
        fi
    done

    echo "$nejlepsi"
}

PHP="$(najdi_php)"

if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
    echo "PHP nenalezeno — nastavte PHP_BIN=cesta/k/php" >&2
    exit 1
fi

PHP_VERZE="$(verze_php "$PHP")"

if [ "$PHP_VERZE" -lt "$PHP_MIN" ]; then
    echo "PHP $(popis_php "$PHP") na $PHP je staré — aplikace potřebuje aspoň 8.4.1." >&2
    echo "Nastavte PHP_BIN na novější instalaci." >&2
    exit 1
fi

echo "== PHP =="
echo "$PHP ($(popis_php "$PHP"))"

# ——— assety, které vznikají buildem ———
#
# `public/build` je verzovaný v gitu: hotové assety přináší `git pull` a server je
# vyrábět nemusí. Když si je ale někdo přesto přebuildoval, má pracovní strom změněný
# a `git pull --ff-only` by odmítl. Vyhazují se proto rovnou — autoritativní kopie je
# ta v gitu.
git checkout -- public/build 2>/dev/null || true
# A soubory, které po buildu zůstaly navíc: vite dává do jména otisk obsahu, takže
# přebuildováním vzniknou nové soubory vedle starých. Adresář je celý generovaný.
git clean -qfd public/build 2>/dev/null || true

PRED="$(git rev-parse HEAD)"

echo
echo "== Stahuji kód a assety =="
git pull --ff-only

# ——— závislosti ———
#
# Instaluje se, **jen když se `composer.lock` opravdu změnil**. Jinak by každé
# nasazení čekalo minutu na to, aby composer zjistil, že nemá co dělat.
#
# Když se lock změnil a composer chybí, je to důvod nasazení zastavit: kód by běžel
# proti starému `vendor/` a chyběla by mu třída, o které neví, že ji nemá.
if ! git diff --quiet "$PRED" HEAD -- composer.lock composer.json; then
    COMPOSER="${COMPOSER_BIN:-$(command -v composer || true)}"

    if [ -z "$COMPOSER" ]; then
        for kandidat in /usr/local/bin/composer /www/server/php/composer.phar ./composer.phar; do
            [ -f "$kandidat" ] && COMPOSER="$kandidat" && break
        done
    fi

    if [ -z "$COMPOSER" ]; then
        echo "composer.lock se změnil, ale composer se nenašel — nastavte COMPOSER_BIN." >&2
        exit 1
    fi

    echo
    echo "== Závislosti (composer.lock se změnil) =="
    # Přes `$PHP`, ne přímo: composer je PHAR a sám by se spustil pod systémovým
    # PHP 8.1, které balíčky pro 8.4 odmítne nainstalovat.
    "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
else
    echo
    echo "== Závislosti =="
    echo "composer.lock beze změny — přeskakuji."
fi

echo
echo "== Kontrola před nasazením =="
# `galerie:pred-nasazenim` vrací FAILURE při zapnutém ladění, chybějícím APP_KEY,
# HTTP adrese, nešifrovaných sezeních, frontě v režimu `sync` nebo chybějícím
# prototypu. Příkaz existoval a měl vlastní test, jen ho nikdo nespouštěl —
# takže z brány zbyla věta v dokumentu. Díky `set -e` nasazení opravdu zastaví.
"$PHP" artisan galerie:pred-nasazenim

echo
echo "== Záloha před migrací =="
# Migrace je jediný krok tohoto skriptu, který nejde vzít zpět — a do kola 2ag
# před ní žádná záloha neběžela (žádná ani nebyla). Když se záloha nepovede,
# `set -e` nasazení zastaví dřív, než se schéma změní. Obnova: BACKUP_AND_RESTORE.md.
"$PHP" artisan gallery:zaloha

echo
echo "== Migrace =="
"$PHP" artisan migrate --force

echo
echo "== Úpravy fotek ze stavu =="
# Popisky, místa, data a štítky upravené v prototypu dřív zůstaly jen ve
# společném stavu — průběžný zápis propisuje jen to, co se od minulého zápisu
# změnilo, takže starší úpravy by v databázi neskončily nikdy. Příkaz je dožene.
#
# Jeho docblock říká „jednou po nasazení", a přesně sem tedy patří. Opakování
# nevadí: zapisuje tytéž hodnoty a od kola 27 se srovnává proti databázi,
# takže druhý běh nemá co dělat.
"$PHP" artisan gallery:upravy-ze-stavu || true

echo
echo "== Veřejný disk =="
# `public/storage` (odkaz z `artisan storage:link`) vydává originály fotek webovým
# serverem bez přihlášení — mimo kontrolu aplikace. Soubory chodí přes `/files`,
# kde se ověřuje podpis nebo členství; přímý odkaz se proto odstraňuje.
if [ -L public/storage ]; then
    rm public/storage
    echo "Odkaz public/storage odstraněn — fotky jdou jen přes /files."
else
    echo "Odkaz public/storage není — v pořádku."
fi

echo
echo "== Čistím cache =="
# Kód se změnil, takže config, routy i pohledy uložené v cache jsou zastaralé.
"$PHP" artisan optimize:clear

# Běžícím workerům se řekne, ať doběhnou a nastartují znovu — jinak by až do
# příštího restartu serveru zpracovávali úlohy starým kódem. Když žádný neběží,
# je to prázdná operace.
"$PHP" artisan queue:restart >/dev/null 2>&1 || true

echo
echo "== Práva a PHP-FPM =="
# `artisan` běží pod tím, kdo nasazuje (často root), takže nově vzniklé soubory
# v `storage/framework` patří jemu. PHP-FPM do nich pak nesmí zapisovat a
# aplikace odpoví 500 — na obrazovce to nevypadá jako chyba nasazení, ale jako
# rozbitá aplikace. Uživatele webu bere z vlastníka `public/index.php`, aby se
# nemuselo hádat mezi `www-data`, `www` a jménem podle panelu.
WEB_USER="$(stat -c '%U:%G' public/index.php 2>/dev/null || echo '')"

if [ -n "$WEB_USER" ] && [ "$(id -u)" = "0" ]; then
    chown -R "$WEB_USER" storage bootstrap/cache
    echo "Vlastník storage a bootstrap/cache: $WEB_USER"
else
    echo "Práva neměním (nejsem root nebo neznám uživatele webu) — zkontrolujte ručně."
fi

# Při `opcache.validate_timestamps=0` se nový kód **vůbec** neprojeví, dokud se
# PHP-FPM nenačte znovu: `route:list` ukazuje novou cestu, ale přes HTTP pořád
# běží ta stará. Reload je bezpečný, požadavky doběhnou.
if [ "$(id -u)" = "0" ] && command -v systemctl >/dev/null 2>&1; then
    FPM_UNIT="$(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk 'NR==1{print $1}')"

    if [ -n "$FPM_UNIT" ]; then
        systemctl reload "$FPM_UNIT" && echo "PHP-FPM načten znovu: $FPM_UNIT"
    else
        echo "PHP-FPM jednotku jsem nenašel — načtěte ji ručně, jinak poběží starý kód."
    fi
else
    echo "PHP-FPM nenačítám (nejsem root nebo tu není systemd) — načtěte ho ručně."
fi

# ——— build assetů ———
#
# Až za migracemi, a schválně: kvůli tomuhle kroku se kdysi nasazení zaseklo v půlce.
# Když spadne, aplikace tím nepřijde o nic — `public/build` už dorazil s `git pull`.
#
# Node se kontroluje předem. Vite 8 a Tailwind 4 potřebují Node 20; na serveru bývá
# starší a `npm run build` na něm skončí hláškou „bad interpreter", která vypadá
# hůř, než co se opravdu stalo.
echo
echo "== Assety =="

NODE_MIN=20
NODE="${NODE_BIN:-$(command -v node || true)}"
NODE_VERZE=0

if [ -n "$NODE" ] && [ -x "$NODE" ]; then
    NODE_VERZE="$("$NODE" -p 'process.versions.node.split(".")[0]' 2>/dev/null || true)"

    # Totéž pojištění jako u PHP: co není číslo, je nula.
    case "$NODE_VERZE" in
        '' | *[!0-9]*) NODE_VERZE=0 ;;
    esac
fi

if [ "$NODE_VERZE" -ge "$NODE_MIN" ]; then
    echo "Node $("$NODE" -v) — sestavuji."

    if ! (npm ci --no-audit --no-fund && npm run build); then
        echo "Build selhal — jede se dál s assety z gitu (public/build)." >&2
        git checkout -- public/build 2>/dev/null || true
    fi
else
    echo "Node ${NODE_VERZE}.x je starý (potřeba ${NODE_MIN}+) — přeskakuji."
    echo "Nevadí: hotové assety přinesl git pull, v repozitáři je public/build verzovaný."
fi

echo
echo "== Kontrola stavu =="
# Neblokuje nasazení: hlásí, co server neumí (typicky HEIC náhledy), ne co je rozbité.
"$PHP" artisan gallery:doctor || true

echo
echo "== Ukázková data v databázi =="
# Jen výpis, nic se nemaže. Řádky, které dřív zápis stavu vložil z ukázky
# prototypu („Dune: Part Two", „Máma, Olomouc"), se odstraní až ručně:
#     "$PHP" artisan gallery:ukazkova-data --smazat
"$PHP" artisan gallery:ukazkova-data || true

echo
echo "Hotovo."
