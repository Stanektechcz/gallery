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
echo "== Migrace =="
# Nejdřív se ukáže, co se chystá, a teprve pak se to provede. Migrace je jediný krok
# tohoto skriptu, který nejde vzít zpět.
"$PHP" artisan migrate --force

echo
echo "== Čistím cache =="
# Kód se změnil, takže config, routy i pohledy uložené v cache jsou zastaralé.
"$PHP" artisan optimize:clear

# Běžícím workerům se řekne, ať doběhnou a nastartují znovu — jinak by až do
# příštího restartu serveru zpracovávali úlohy starým kódem. Když žádný neběží,
# je to prázdná operace.
"$PHP" artisan queue:restart >/dev/null 2>&1 || true

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
echo "Hotovo."
