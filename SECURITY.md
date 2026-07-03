# Bezpečnost

## OWASP Top 10 opatření

### SQL Injection

- Všechny dotazy přes Eloquent ORM a Query Builder s prepared statements
- Parametrizované queries v raw SQL
- Žádné dynamické SQL stringy se vstupem od uživatele

### Cross-Site Scripting (XSS)

- Blade escapuje výstupy automaticky
- React DOM escapuje výstupy automaticky
- Content Security Policy hlavičky v Apache konfiguraci
- `X-Content-Type-Options: nosniff`

### CSRF

- Laravel CSRF protection na všech web POST/PATCH/DELETE
- API endpointy autentizovány přes Sanctum tokeny

### Autentizace a session

- Bezpečné heslo hashing (bcrypt přes Laravel `hashed` cast)
- `SESSION_ENCRYPT=true` v produkci
- `SameSite=Strict` cookies
- HTTPS only v produkci
- Rate limiting na login endpointu
- Audit log pro každý neúspěšný login pokus

### Autorizace

- Každá operace kontroluje Laravel Policy (nestačí schovat tlačítko)
- `AlbumPolicy`, `MediaPolicy` — kontrola space membership
- Read-only mode pro bezpečné prohlížení
- Admin operace (permanentní smazání, Drive migrace) vyžadují `owner` roli

### Upload bezpečnost

- MIME detection server-side (nikdy nevěřit Content-Type hlavičce)
- Extension whitelist (photo a video typy)
- File size limit (max chunk size)
- Image bomb protection při zpracování
- Safe filename — originální jméno souboru nepoužito jako storage identifikátor
- Chunky ukládány do soukromého `local` disku, nikoli `public`

### Tokeny a secrets

- Google OAuth tokeny šifrované v DB (`Crypt::encryptString`)
- Client Secret nikdy zobrazen v UI po uložení
- Tokeny nejsou logovány
- Tokeny nejsou posílány do browseru
- API tokeny zobrazeny pouze jednou při vytvoření

### Rate limiting

- Login: 5 pokusů / minuta
- Upload initiation: 60 / minuta
- API endpoints: Laravel throttle middleware

### Veřejné shared linky

- Bezpečný náhodný token (40 znaků, URL-safe)
- Volitelné heslo (bcrypt hash)
- Volitelná expirace
- Limit počtu použití
- GPS souřadnice lze skrýt
- Nikdy neodhalí OAuth token
