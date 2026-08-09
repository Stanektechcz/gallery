# Plán rozvoje: profil, tarify, automatizace, sidebar

Rozsah zadání je na několik samostatných průchodů. Tenhle dokument je rozděluje na fáze,
které jdou dodávat po jedné a každá končí něčím funkčním — ne rozpracovaným.

Pořadí není libovolné: každá fáze staví na tom, co dokončila předchozí.

---

## Fáze 1 — Sidebar podle uživatele

**Proč první:** je celá v našich rukou, nemá vnější závislosti, a dotýká se jí každý
uživatel při každém použití aplikace.

Dnes je navigace tvrdě zapsaná v `AppLayout.tsx` a jediné, co si člověk může uložit, je
šest připnutých odkazů v `localStorage` — tedy per zařízení, ne per účet.

### Datový model

```
user_navigation_items
  user_id, gallery_space_id
  href            odkaz na existující položku, nebo null u vlastní kategorie
  label           přepsaný název, null = ponechat výchozí
  parent_id       vnoření pod jinou položku
  position        pořadí mezi sourozenci
  is_hidden       skryto bez smazání
  is_group        vlastní kategorie, kterou si člověk vytvořil
```

Uložení je **rozdílové**: bez řádku platí výchozí navigace. Kdo si nic neupravil, nemá
v tabulce nic a dostane to, co dnes.

### Co dodat

1. `GET/PUT /api/v1/navigace` — načtení a uložení celého stromu naráz.
2. Editor: přetahování, přejmenování, skrývání, vnoření o jednu úroveň, vlastní kategorie.
3. `AppLayout` čte uspořádání ze sdílené Inertia prop a padá zpátky na výchozí.
4. Tlačítko „obnovit výchozí" — smaže řádky, ne položky.

**Hotovo znamená:** uspořádání drží po odhlášení, na jiném zařízení i po přidání nové
funkce do aplikace (nová položka se objeví, nesmaže cizí uspořádání).

---

## Fáze 2 — Profil a moderní přihlášení

### 2a. Dokončení profilu

Obrazovka existuje. Chybí: změna avatara z mobilu s ořezem, dvoufázové ověření, historie
přihlášení, export vlastních dat, smazání účtu.

### 2b. Otisk prstu a Face ID — **WebAuthn / passkeys**

Otisk prstu není samostatná technologie, kterou by šlo „zapnout". Prohlížeč ho zpřístupní
přes **WebAuthn**, kde biometrie odemyká klíč uložený v zařízení. Aplikace otisk nikdy
nevidí a nemá ho kde uložit — dostane jen podpis.

```
webauthn_credentials
  user_id, credential_id, public_key, sign_count,
  transports, device_label, last_used_at
```

Tok:
1. `POST /api/v1/webauthn/registrace/start` → challenge
2. prohlížeč vyzve k otisku, vrátí podpis
3. `POST /api/v1/webauthn/registrace/dokoncit` → uloží veřejný klíč
4. při přihlášení totéž s `/prihlaseni/*`

Knihovna: `web-auth/webauthn-lib` — bez placených služeb.

**Podmínka, kterou je nutné znát:** WebAuthn funguje **jen přes HTTPS** a klíč je vázaný
na doménu. Na `gallery.stanektech.cz` to funguje, na IP adrese ne.

**Vždy musí zůstat záložní cesta.** Zařízení se ztrácejí; heslo nebo obnovovací kód musí
zůstat, jinak si člověk účet zamkne.

### 2c. Další moderní funkce, které dávají smysl

| Funkce | Přínos | Náročnost |
|---|---|---|
| Passkeys (2b) | přihlášení bez hesla | střední |
| Dvoufázové ověření (TOTP) | pro ty, kdo passkeys nechtějí | nízká |
| Export dat (GDPR) | zákonný nárok, dnes chybí | nízká |
| Smazání účtu s odkladem | důvěra, GDPR | nízká |
| Přihlášení odkazem v e-mailu | pro toho, kdo zapomněl heslo | nízká |

---

## Fáze 3 — Tarify

### Co dnes chybí

Katalog je v **seederu**, takže přidání tarifu znamená zásah do kódu a nasazení. Operátor
má obrazovku matice, ale nemůže tarif založit.

### Co dodat

1. **Správa tarifů v administraci** — vytvořit, upravit, archivovat, bez zásahu do kódu.
2. **Odvozování tarifu z funkcí** místo pevných seznamů: tarif je množina funkcí a limitů,
   ne kód v seederu.
3. **Roční platba se slevou** — sloupce už existují, chybí volba na obrazovce.
4. **Zkušební období** — `trial_ends_at` na předplatném, hlídané `EntitlementService`.
5. **Změna tarifu uprostřed období** s poměrným doúčtováním.
6. **Přehled pro operátora**: kolik prostorů na kterém tarifu, obrat, odchody.

### Výkon

`EntitlementService` už má cache v rámci requestu (130 → 13 dotazů). Další krok je cache
mezi requesty s invalidací při změně tarifu — teprve až bude prostorů víc, dřív je to
optimalizace naslepo.

---

## Fáze 4 — Automatizace

Záložka existuje, obsah ne. Návrh je postavit ji jako **spouštěč → podmínka → akce**,
protože to je jediný tvar, který unese věci, o kterých ještě nevíme.

### Spouštěče

- čas (každý den v 8:00, každé pondělí)
- událost v kalendáři se blíží / skončila
- přibyla fotka, recept, zápisek
- někdo dorazil na místo (geofence)
- vyčerpaná kvóta úložiště
- výročí a narozeniny

### Akce

- upozornění do aplikace / push / Discord webhook
- vytvořit úkol, událost, zápisek do deníku
- poslat zprávu do kanálu chatu
- označit médium, přesunout do alba

### Datový model

```
automations
  gallery_space_id, name, is_enabled,
  trigger_type, trigger_config (json),
  conditions (json), actions (json),
  last_run_at, run_count
automation_runs
  automation_id, status, message, ran_at
```

Běh přes existující plánovač; každý spuštěný běh se zapíše, aby šlo dohledat, proč se
něco stalo nebo nestalo.

**Rozsah první dodávky:** tři spouštěče (čas, blížící se událost, výročí) a tři akce
(upozornění, úkol, zpráva do chatu). Zbytek přidávat po jednom — tvar to unese.

---

## Co doporučuju dělat v jakém pořadí

1. **Sidebar** — největší denní přínos, žádné riziko
2. **Automatizace** — dokončí nedodělanou záložku
3. **Tarify** — obchodní hodnota, ale až bude komu prodávat
4. **Passkeys** — nejmodernější, ale nejmenší denní dopad

Každou fázi lze nasadit samostatně a žádná nerozbije to, co je hotové.
