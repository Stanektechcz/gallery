# Zálohování a obnova

## Co zálohovatzálohovat

| Zdroj                          | Obsah                                         | Priorita            |
| ------------------------------ | --------------------------------------------- | ------------------- |
| MySQL dump                     | Kompletní metadata, alba, tagy, osoby, vztahy | Kritická            |
| `.env`                         | Konfigurace (BEZ client_secret v plain)       | Kritická            |
| `storage/app/public/variants/` | Lokální náhledy                               | Vysoká              |
| Google Drive                   | Originální soubory                            | Primární (external) |

## Automatický metadata backup

Scheduler denně spouští `BackupMetadataJob`, který:

1. Exportuje DB dump (bez OAuth tokenů v plain textu)
2. Uloží JSON snapshot alb, médií, tagů, osob, Drive ID mapování
3. Nahraje do `System/Metadata Backups/` na Google Drive

```bash
php artisan gallery:backup-metadata
```

## Obnova po havárii

### Scénář 1: Ztráta MySQL (Drive OK)

1. Obnovte .env a DB credentials
2. `php artisan migrate`
3. Nahrajte nejnovější metadata backup z Drive do lokálního souboru
4. `php artisan gallery:restore-from-backup /path/to/backup.json`
5. `php artisan gallery:rebuild-albums`
6. `php artisan gallery:rebuild-search`

### Scénář 2: Ztráta Google Drive přístupu (MySQL OK)

1. Galerie přejde automaticky do degraded mode
2. Metadata a lokální náhledy zůstávají dostupné
3. Reautorizujte Drive (`/settings/storage/google`)
4. `php artisan gallery:sync-drive` pro ověření integrity

### Scénář 3: Ztráta obojího

1. Obnovte MySQL z databázové zálohy
2. Reautorizujte Drive
3. `php artisan gallery:deep-integrity-scan`

## Varování

Google Drive patří školnímu Educannet účtu. Po ukončení studia může být přístup ztracen.

**Doporučeno:** Pravidelně stahovat lokální zálohu originálů nebo nastavit druhý storage provider.

Příkaz pro export:

```bash
php artisan gallery:export --all --format=zip --output=/backup/
```
