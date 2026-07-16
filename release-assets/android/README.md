# Android release APK

Tato složka obsahuje podepsané instalační APK určené k publikaci přes aplikaci.
Soukromý release keystore ani jeho hesla se do repozitáře neukládají.

Po aktualizaci serveru publikujte aktuální balíček z kořene projektu:

```bash
php artisan gallery:publish-android-app "$PWD/release-assets/android/maki-gallery-1.0.0.apk" --app-version=1.0.0
php artisan config:clear
```

Před publikací lze ověřit kontrolní součet:

```bash
sha256sum -c release-assets/android/SHA256SUMS.txt
```
