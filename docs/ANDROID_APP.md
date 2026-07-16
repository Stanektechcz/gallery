# Maki na Androidu a tabletu

## Zvolená architektura

Web zůstává jediným zdrojem pravdy. Android i tablet používají stejný Laravel/Inertia backend, stejné přihlášení, API, data a oprávnění. Každé nasazení webu je proto po potvrzení aktualizace dostupné i v instalované aplikaci bez paralelního vývoje obrazovek.

První distribuční vrstva je instalovatelná PWA. Pro publikaci do Google Play je doporučen tenký Trusted Web Activity (TWA) obal vytvořený přes Bubblewrap. Capacitor má smysl až pro funkce, které webové API neumí spolehlivě zajistit, například plně nativní upload na pozadí, FCM notifikace nebo hlubší práci se systémovou galerií.

## Co je připraveno

- kořenový manifest `/manifest.webmanifest` v češtině pro telefon i tablet;
- kořenový service worker `/sw.js`, který kontroluje celou aplikaci;
- bezpečné aktualizace s výzvou uživateli a kontrolou při návratu do aplikace;
- instalační výzva na podporovaných Android prohlížečích;
- samostatný režim bez lišty prohlížeče, adaptivní ikona a bezpečné okraje displeje;
- Android Web Share Target pro odeslání fotografií a videí do Maki;
- offline obrazovka bez ukládání přihlášených HTML stránek, API, financí nebo soukromých médií do sdílené cache;
- automatické odstranění původního service workeru omezeného jen na `/build/` a jeho privátních cache.

## Instalace bez Google Play

1. Otevřít produkční HTTPS adresu v Chrome na Androidu.
2. Přihlásit se a zvolit nabídku **Nainstalovat Maki do zařízení** nebo Chrome → **Nainstalovat aplikaci**.
3. Aplikace se objeví na ploše a v seznamu aplikací. Telefon a tablet nadále čtou aktuální web.

## Publikace do Google Play přes TWA

Po nasazení a ověření PWA:

1. určit finální Android package ID, například `cz.stanektech.maki`;
2. vygenerovat TWA projekt pomocí aktuálního Bubblewrap CLI z produkčního manifestu;
3. vytvořit a bezpečně zazálohovat signing key;
4. vložit Play App Signing SHA-256 fingerprint do `public/.well-known/assetlinks.json`;
5. ověřit Digital Asset Links na produkční doméně;
6. sestavit podepsané AAB, otestovat telefon/tablet a publikovat přes interní test Google Play.

`assetlinks.json` nelze dokončit před volbou package ID a získáním skutečného podpisového fingerprintu. Do repozitáře se nesmí ukládat privátní signing key ani hesla.

## Další nativní vlna

Pro plné notifikace na pozadí a spolehlivé dlouhé uploady po uspání aplikace je vhodné přidat FCM/Web Push a případně malou nativní Android vrstvu. Tato vrstva má používat existující API; nemá kopírovat business logiku ani obrazovky webu.
