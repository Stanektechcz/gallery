# Architektura

## Hlavní přehled

```
Browser/PWA
    ↓ HTTPS
Apache + PHP-FPM 8.3
    ↓
Laravel (Monolit s Inertia.js)
    ├── MySQL (hlavní zdroj pravdy)
    ├── Database Queue (jobs)
    ├── Local Storage (thumbnails, variants, temp)
    └── Google Drive API v3 (originály)
```

## Princip fungování

1. **Upload**: Browser → Laravel (chunked) → assembled → pipeline → Drive (resumable)
2. **Prohlížení**: Browser → Laravel → MySQL + lokální varianty (NIKDY přímé Drive URL)
3. **Album operace**: DB transakce + queue job pro Drive sync (optimistické UI)
4. **Webhooky**: Drive change notification → queue job → DB sync

## Proč ne Immich

Immich není použit v žádné podobě (backend, frontend, API, knihovna, kontejner, inspirace pro kopírování kódu).

## Vrstvy aplikace

### Models / Business Logic

- `app/Models/` — Eloquent modely
- `app/Services/` — business logika (AlbumService, GoogleDriveStorageProvider, ...)
- `app/Policies/` — Laravel Policies (kontrola každé operace)

### HTTP Layer

- `app/Http/Controllers/` — Inertia.js + API controllers
- `app/Http/Requests/` — validace vstupů

### Queue Jobs

- `app/Jobs/Upload/` — chunked upload assembly
- `app/Jobs/Media/` — EXIF, varianty, Drive upload
- `app/Jobs/Drive/` — Drive folder operace, webhooky

### Frontend

- `resources/js/Pages/` — Inertia React stránky
- `resources/js/Layouts/` — AppLayout (sidebar + mobile nav)
- `resources/js/types/` — TypeScript typy

## Datové toky

### Upload flow

```
1. Browser → POST /api/v1/uploads (initiate session)
2. Browser → PUT /api/v1/uploads/{uuid}/chunks/{n} (chunk-by-chunk)
3. IndexedDB — ukládá stav fronty (přežije refresh)
4. Browser → POST /api/v1/uploads/{uuid}/complete
5. AssembleUploadChunksJob → sestavení + validace
6. CalculateMediaHashesJob → SHA-256, MD5
7. ExtractMediaMetadataJob → EXIF (ExifTool)
8. GenerateImageVariantsJob → WebP varianty (Intervention Image)
   nebo GenerateVideoPosterJob → FFmpeg poster/compat
9. InitiateDriveResumableUploadJob → Drive session URI
10. UploadDriveChunkJob(s) → chunked upload na Drive
11. Media status → ready
```

### Album sync flow

```
1. User vytvoří album → DB transakce → closure table
2. CreateDriveFolderJob → Queue(drive)
3. Google Drive API → vytvořit složku
4. Album.drive_folder_id uloženo, sync_status = synced
5. UI zobrazí stav (pending/synced/failed)
```

## Closure table pro alba

Albums používají kombinaci:

- `parent_id` — přímý rodič
- `album_closure` — ancestor/descendant pro libovolnou hloubku
- `materialized_path` — rychlé subpath queries
- `full_display_path` — zobrazení breadcrumbu

Přesun alba:

1. Validace proti cyklu
2. DB transakce: update parent_id + rebuild closure
3. MoveDriveFolderJob pro Drive

## Storage abstrakce

`StorageProviderInterface` definuje kontrakt. `GoogleDriveStorageProvider` implementuje.
Budoucí změna storage (jiný Google účet, jiný provider) nevyžaduje přepis business logiky.

## Degraded mode

Při výpadku Google Drive:

- Galerie funguje dál (metadata + lokální náhledy)
- Upload joby se pozastavují (ne zahazují)
- Stav `degraded` je jasně zobrazen
- Reautorizace je nabídnuta
- Po obnovení přístupu se joby obnoví
