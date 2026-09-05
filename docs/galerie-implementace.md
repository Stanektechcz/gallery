# Napojení prototypu Galerie na backend

Plán práce. Prototyp v ZIPu se nemění — mění se jen backend pod ním.

## Co prototyp je

| Část | Rozsah |
| --- | --- |
| Rozvržení | `Galerie.dc.html` (široké, 2 MB), `Galerie mobil aplikace.dc.html` (telefonní, 816 kB) |
| Obrazovky | 44 v katalogu `APP` (`x-*`) + 10 pohledů knihovny = 45 |
| Záložky | 135 v katalogu + záložky Nastavení = 149 |
| Navigace | 56 položek v 9 skupinách (`NAV_GROUPS`) |
| Data | 190 kolekcí v `galerie-data.js` |
| Mechanismy | 24 definic (`mechanismy.json`) + sdílená logika |
| Administrace | 6 záložek: uživatelé, zdraví, úlohy, riziko úložiště, klíče, tarify |
| PWA | manifest, service worker (fronta v IndexedDB + Background Sync), push, offline |
| Scaffold | 1 170 řádků PHP: stav, token, WebAuthn, média, push, mechanismy, 3 úlohy, 5 migrací |

## Dvě architektonická rozhodnutí

**1. Pár = prostor galerie.** Scaffold chce `users.couple_id`. Aplikace už identitu
páru má — `gallery_spaces`. Zavést druhou by znamenalo dvě pravdy o tomtéž. `couple_id`
se proto odvozuje z prostoru uživatele, nová sloupec nevzniká.

**2. Média jdou do existující tabulky.** Scaffold zakládá vlastní `media_items`, jenže
ta v aplikaci už je — a je bohatší (`sha256`, `size_bytes`, `taken_at`, EXIF, perceptuální
otisk). Druhá tabulka fotek by znamenala dva sklady téhož a rozejít se můžou už první den.
Prototypový `MediaController` proto zapisuje do existující.

Zbytek scaffoldu jde do aplikace tak, jak je: jeden JSON dokument na pár (`couple_states`)
s částečnými patchi a `rev`. README prototypu to zdůvodňuje a určuje i pořadí, ve kterém
se klíče později vytahují do vlastních tabulek.

## Úkoly

### 1. Základ
- [ ] `config/galerie.php`, `routes/galerie.php`, registrace bez prefixu
- [ ] Migrace: `couple_states`, `webauthn_credentials`, `push_subscriptions`
- [ ] Model `CoupleState` (otevřený + šifrovaný sloupec, `applyPatch`, `rev`)
- [ ] `StateController`: GET / PATCH / DELETE, konflikt `409` s aktuálním stavem
- [ ] Testy: sloučení po klíčích, konflikt, šifrované klíče, izolace mezi páry

### 2. Přihlášení
- [ ] `TokenController`: `POST /sanctum/token`, `POST /api/logout`
- [ ] Jedno zařízení = jeden token (nové přihlášení ruší staré)
- [ ] Testy: platné, neplatné, limit 20/min, zrušení starého tokenu

### 3. Otisk (WebAuthn)
- [ ] `composer require web-auth/webauthn-lib`
- [ ] 4 endpointy, challenge v cache, kontrola `sign_count`
- [ ] `/login/options` neprozradí existenci e-mailu
- [ ] Testy: registrace, přihlášení, klesající `sign_count`, vypršelá challenge

### 4. Média
- [ ] `MediaController` nad existující `media_items`
- [ ] Deduplikace podle `sha256`, části po 8 MB, koš 30 dní
- [ ] `GET /api/media/{id}/raw` přes aplikaci, ne z `public`
- [ ] `POST /share-target` + přepnutí manifestu na POST
- [ ] Testy: duplicita, části, koš, cizí pár nedostane cizí soubor

### 5. Mechanismy
- [ ] `MechanismController` + `resources/galerie/mechanismy.json`
- [ ] Seeder z `galerie-data.json` (180 kolekcí)
- [ ] Testy: 24 klíčů, 503 bez souboru

### 6. Upozornění a úlohy
- [ ] `PushController` + `PushSender` (VAPID)
- [ ] `galerie:expire` (tichá), `galerie:notify` (jen revize rozhodnutí), `galerie:clean-trash`
- [ ] Testy: neplatný odběr se maže, vypršení neposílá nic

### 7. Administrace — chybí celá
Prototyp má data jen na klientovi. Backend potřebuje:
- [ ] Uživatelé a role (vlastník právě jeden, mění se jen předáním)
- [ ] Protokol změn se jménem přihlášeného
- [ ] Úlohy, klíče k API, tarify, riziko úložiště počítané ze stavu
- [ ] Testy: vlastník nejde odebrat, role se necykluje, každý zásah v protokolu

### 8. Doručení
- [ ] Blade rozvržení, `window.GALERIE_API_BASE`, CSRF
- [ ] `sw.js` z kořene se `Service-Worker-Allowed: /`
- [ ] Statické soubory prototypu do `public/`
- [ ] Ověření: prototyp běží proti serveru bez jediné změny v `.dc.html`

## Hotovo je, když

- Oba účty vidí tentýž stav.
- Offline zápis dorazí po obnovení signálu.
- Otisk projde přes serverovou challenge.
- V administraci se každý zásah objeví v protokolu.
- V `.dc.html` se nezměnil jediný řádek.
