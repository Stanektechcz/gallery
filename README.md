# Stanektech Gallery

Soukroma foto/video galerie pro Adriana a Makinku na gallery.stanektech.cz.
Plnohodnotna ne-AI alternativa k Immich/Google Photos.

## Rychly start

    composer install
    npm install
    cp .env.example .env
    php artisan key:generate
    # Nastavte DB credentials v .env
    php artisan migrate --seed
    npm run build
    php artisan serve

## Klic vlastnosti

- Neomezene vnorena alba (closure table + materialized path)
- Google Drive jako dlouhodobe uloziste originalu (OAuth 2.0)
- Lokalni nahled — galerie se nacita bez Drive API requestu
- Resumable upload (chunked upload do Laravelu + na Drive)
- PWA s offline podporou a IndexedDB upload frontou
- Timeline s virtualnim scrollingem a memories
- Mapa (GPS + Leaflet)
- Klasicke vyhledavani (MySQL FULLTEXT) — zadne AI
- Hierarchicke tagy, rucni osoby, mista
- Stacks, duplicity (SHA-256 + perceptual hash), XMP sidecary
- Sdilena alba, verejne linky, guest upload
- Kos, archiv, vzpominky, nedestruktivni editace
- Admin dashboard, health endpointy, audit log
- gallery:doctor — komplexni diagnosticky prikaz

## Bez AI

Zadne AI, ML, embeddings, vektorove databaze, rozpoznavani obliceju.

## Stack

Backend: Laravel (PHP 8.3+), MySQL/MariaDB, database queue
Frontend: React 19, TypeScript, Inertia.js, Tailwind CSS, Vite, PWA
Drive: Google Drive API v3, OAuth 2.0 Authorization Code Flow
Media: Intervention Image, FFmpeg, ExifTool
Server: ISPConfig, Apache, PHP-FPM 8.3, systemd, cron