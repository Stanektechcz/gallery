# Přímá distribuce Android aplikace Maki

Web nabízí instalační centrum na `/app` a stabilní APK odkaz `/app/android/download`. Běžná PWA instalace funguje bez APK. Přímé stažení začne fungovat po publikování podepsaného release balíčku.

## Bezpečné první sestavení

### Automaticky přes GitHub Actions

Repozitář obsahuje workflow **Build signed Android app** a konfiguraci `android-twa/twa-manifest.json`. Před prvním spuštěním nastavte v GitHub repozitáři `Settings → Secrets and variables → Actions`:

- `ANDROID_KEYSTORE_BASE64` – celý release keystore zakódovaný pomocí `base64`;
- `ANDROID_KEYSTORE_PASSWORD` – heslo keystore;
- `ANDROID_KEY_PASSWORD` – heslo klíče;
- `ANDROID_KEY_ALIAS` – standardně `maki`.

Workflow spusťte v záložce Actions pomocí **Run workflow**, zadejte `version_name` a vždy rostoucí `version_code`. Výsledný artefakt obsahuje podepsané APK, AAB, kontrolní součty a otisk certifikátu. Keystore se do Gitu ani výsledného artefaktu nepřidává.

Pokud release keystore ještě neexistuje, vytvořte jej jednou na důvěryhodném počítači s JDK 17 a bezpečně jej zazálohujte:

```bash
keytool -genkeypair -v -keystore maki-release.keystore -alias maki -keyalg RSA -keysize 2048 -validity 10000
base64 -w 0 maki-release.keystore
```

Na PowerShellu lze druhý příkaz nahradit:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes('maki-release.keystore'))
```

### Ručně pomocí Bubblewrap

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

`/bezpecna/cesta/app-release-signed.apk` je pouze příklad. Soubor je nejprve nutné z Actions stáhnout a na server přenést, například:

```bash
scp maki-gallery-1.0.0.apk root@vmi2254765:/root/app-release-signed.apk
ssh root@vmi2254765
cd /www/wwwroot/gallery.stanektech.cz
php artisan gallery:publish-android-app /root/app-release-signed.apk --app-version=1.0.0
```

Alternativně lze nastavit `ANDROID_APP_DOWNLOAD_URL` na důvěryhodný HTTPS release/CDN odkaz. Stabilní aplikační URL pak na tento soubor bezpečně přesměruje.

## Aktualizace

1. Zvyšte Android `versionCode` a veřejné číslo verze.
2. Podepište APK stejným release certifikátem.
3. Ověřte jej pomocí `apksigner verify`.
4. Znovu spusťte `gallery:publish-android-app` s novou verzí.
5. Ověřte `/app`, přímé stažení a `/.well-known/assetlinks.json`.

Webová aplikace i TWA vždy načítají aktuální web. Nový Android balíček je nutný jen při změně nativního wrapperu, package id, oprávnění nebo dalších nativních vlastností.
