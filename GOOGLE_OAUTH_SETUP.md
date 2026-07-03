# Google OAuth 2.0 Setup

## Přehled

Aplikace používá OAuth 2.0 Authorization Code Flow pro přístup k Google Drive Educannet školního účtu.
Scope: `https://www.googleapis.com/auth/drive.file` (Managed App Storage Mode).

## Kroky nastavení

### 1. Google Cloud projekt

1. Jděte na [console.cloud.google.com](https://console.cloud.google.com)
2. Vytvořte nový projekt: **Stanektech Gallery**
3. Aktivujte **Google Drive API**

### 2. OAuth Consent Screen

1. Jděte na **APIs & Services → OAuth consent screen**
2. User Type: **External**
3. App name: `Stanektech Gallery`
4. Přidejte Authorized domain: `stanektech.cz`
5. Scopes: přidejte `https://www.googleapis.com/auth/drive.file`
6. Test users: přidejte váš Educannet email

### 3. OAuth 2.0 Web Client

1. Jděte na **APIs & Services → Credentials**
2. Vytvořte **OAuth 2.0 Client ID** → Web application
3. Authorized redirect URI:
    ```
    https://gallery.stanektech.cz/oauth/google/callback
    ```
4. Zkopírujte **Client ID** a **Client Secret**

### 4. Nastavení v .env

```env
GOOGLE_DRIVE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_DRIVE_CLIENT_SECRET=your-client-secret
GOOGLE_DRIVE_REDIRECT_URI=https://gallery.stanektech.cz/oauth/google/callback
```

### 5. Připojení v aplikaci

1. Přihlaste se jako Adrian (owner)
2. Jděte na **Nastavení → Google Drive**
3. Klikněte **Připojit Google Drive**
4. Přihlaste se Educannet účtem a povolte přístup
5. Aplikace vytvoří root strukturu na Drive

## Refresh token

- `access_type=offline` zajišťuje získání refresh tokenu
- Refresh token je uložen šifrovaně v databázi
- Aplikace token automaticky obnovuje
- Pokud token vyprší → stav `refresh_required`

## Bezpečnost

- Client Secret nikdy není zobrazen v UI po uložení
- Tokeny jsou šifrované pomocí Laravel `Crypt::encryptString()`
- Tokeny nejsou logovány ani posílány do browseru
- OAuth token nikdy není součástí veřejných shared linků

## Educannet specifika

Školní Google Workspace účet může mít omezení:

- OAuth aplikace může být zablokována Workspace adminem → stav `admin_blocked`
- Po ukončení studia může být účet deaktivován
- Galerie má degraded mode — metadata zůstávají dostupná i bez Drive přístupu

## Callback URL pro vývoj

Přidejte do Authorized redirect URIs:

```
http://localhost:8000/oauth/google/callback
```
