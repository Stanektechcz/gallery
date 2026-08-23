#!/usr/bin/env bash
#
# Nasazení MAKI Gallery na produkci.
#
# Vznikl proto, že se nasazení jednou zaseklo v půlce: příkazy byly zřetězené přes &&
# za `npm run build`, ten na serveru spadnout musel — a migrace, které stály za ním, se
# tím pádem nespustily. Nahrávání pak den hlásilo SQL chybu, protože kód čekal sloupec,
# který v databázi nebyl.
#
# Build se tu nedělá schválně. `public/build` je verzovaný v gitu, takže hotové assety
# přináší `git pull`. Server tedy Node vůbec nepotřebuje — a ten, co na něm je, by vite
# stejně nespustil.
#
# Použití:  ./deploy.sh
set -euo pipefail

PHP="${PHP_BIN:-/www/server/php/85/bin/php}"

if [ ! -x "$PHP" ]; then
    echo "PHP nenalezeno na $PHP — nastavte PHP_BIN=cesta/k/php" >&2
    exit 1
fi

echo "== Stahuji kód a assety =="
git pull --ff-only

echo
echo "== Migrace =="
# Nejdřív se ukáže, co se chystá, a teprve pak se to provede. Migrace je jediný krok
# tohoto skriptu, který nejde vzít zpět.
"$PHP" artisan migrate --force

echo
echo "== Čistím cache =="
# Kód se změnil, takže config, routy i pohledy uložené v cache jsou zastaralé.
"$PHP" artisan optimize:clear

echo
echo "== Kontrola stavu =="
# Neblokuje nasazení: hlásí, co server neumí (typicky HEIC náhledy), ne co je rozbité.
"$PHP" artisan gallery:doctor || true

echo
echo "Hotovo."
