# Přímá distribuce Android aplikace Maki

Web nabízí instalační centrum na `/app` a stabilní APK odkaz `/app/android/download`. Běžná PWA instalace funguje bez APK. Přímé stažení začne fungovat po publikování podepsaného release balíčku.

## Bezpečné první sestavení

Na vývojovém počítači s Java JDK a Android SDK nainstalujte aktuální Bubblewrap CLI a inicializujte Trusted Web Activity z produkčního manifestu:

```bash
npm install --global @bubblewrap/cli
bubblewrap init --manifest=https://gallery.stanektech.cz/manifest.webmanifest
bubblewrap build
```

Použijte stabilní package id `cz.stanektech.maki`. Release keystore vytvořený při prvním sestavení bezpečně zazálohujte mimo repozitář a server. Stejným certifikátem musí být podepsány všechny budoucí aktualizace.

Před zveřejněním vždy ověřte podpis:

```bash
apksigner verify --verbose --print-certs app-release-signed.apk
```

Otisk `SHA-256 digest` release certifikátu vložte na server do `ANDROID_APP_SHA256_FINGERPRINT`. Aplikace jej publikuje jako `/.well-known/assetlinks.json`, čímž Android ověří, že aplikace a web mají stejného vlastníka.

## Zveřejnění na serveru

Podepsané APK přeneste na server mimo Git a spusťte:

```bash
php artisan gallery:publish-android-app /bezpecna/cesta/app-release-signed.apk --app-version=1.0.0
php artisan config:clear
```

Příkaz APK zpřístupní přes Laravel storage, uloží verzi, velikost a SHA-256 a vypíše stabilní odkaz. Podpisový klíč ani hesla se na aplikační server neposílají.

Alternativně lze nastavit `ANDROID_APP_DOWNLOAD_URL` na důvěryhodný HTTPS release/CDN odkaz. Stabilní aplikační URL pak na tento soubor bezpečně přesměruje.

## Aktualizace

1. Zvyšte Android `versionCode` a veřejné číslo verze.
2. Podepište APK stejným release certifikátem.
3. Ověřte jej pomocí `apksigner verify`.
4. Znovu spusťte `gallery:publish-android-app` s novou verzí.
5. Ověřte `/app`, přímé stažení a `/.well-known/assetlinks.json`.

Webová aplikace i TWA vždy načítají aktuální web. Nový Android balíček je nutný jen při změně nativního wrapperu, package id, oprávnění nebo dalších nativních vlastností.
