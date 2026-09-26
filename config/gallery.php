<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gallery Application Settings
    |--------------------------------------------------------------------------
    */

    'drive_root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', 'Stanektech Gallery'),

    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    'exiftool_path' => env('EXIFTOOL_PATH', '/usr/bin/exiftool'),

    // Kolik vteřin smí trvat převod jednoho videa na kopii k přehrávání
    // (hardwarový pokus i softwarový náhradní dohromady). Čtyřminutové 4K
    // z telefonu převádí slabší server softwarově klidně deset minut; výchozích
    // 3000 s (50 min) stačí i na dlouhé záznamy. Víc než 3300 s služba nepustí —
    // úloha (`GenerateVideoCompatibilityVariantJob::$timeout`) má 3600 s
    // a převod musí skončit dřív, než ji worker zabije.
    'video_transcode_timeout' => (int) env('VIDEO_TRANSCODE_TIMEOUT', 3000),

    // Kodér obrazu pro kopii videa k přehrávání. "auto" nechá službu vyzkoušet
    // hardwarové kodéry skutečným zkušebním snímkem a vybrat první funkční
    // (viz `VideoProcessingService::selectVideoEncoder()`); jakákoli jiná
    // hodnota se použije napřímo a žádný proces se přitom nespouští — pro
    // server, kde je hardwarový kodér ověřený předem.
    'video_encoder' => env('VIDEO_ENCODER', 'auto'),

    'media_temp_disk' => env('MEDIA_TEMP_DISK', 'local'),
    'media_variants_disk' => env('MEDIA_VARIANTS_DISK', 'public'),

    // Trash retention (days)
    'trash_retention_days' => env('GALLERY_TRASH_RETENTION', 30),

    // Cache settings. Vynucuje je `gallery:uklid-variant`: velikost rozhoduje,
    // stáří jen vybírá, co zahodit dřív.
    'variant_cache_max_size_gb' => env('GALLERY_VARIANT_CACHE_GB', 20),
    'variant_cache_max_age_days' => env('GALLERY_VARIANT_CACHE_DAYS', 90),

    // Kolik dní protokolu běhů úloh nechat. Sám tep plánovače je řádek každou
    // minutu; bez úklidu tabulka roste o zhruba 1,8 milionu řádků ročně a čte
    // se z ní při každém otevření administrace. Poslední běh každé úlohy
    // zůstává i mimo tuhle lhůtu — bere se z něj sloupec „naposledy".
    'task_log_retention_days' => env('GALLERY_TASK_LOG_DAYS', 90),

    // Kolik smí na jeden sdílený odkaz čekat nahrávek od hostů, než je dvojice
    // projde. Do schválení leží na disku serveru a nahrát je může kdokoli
    // s odkazem, bez přihlášení — bez stropu to bylo bez konce.
    'guest_upload_pending_mb' => env('GALLERY_GUEST_UPLOAD_PENDING_MB', 2048),

    // Kolik hlasových vzkazů od hostů (v MB) smí k jednomu odkazu ležet na
    // disku. Nahrávka má až 10 MB a poslat ji může kdokoli s odkazem; limit
    // požadavků je jen na adresu. Dvě stě megabajtů je přes dvacet plných
    // nahrávek — víc, než k jedněm fotkám namluví celá rodina.
    'guest_voice_pending_mb' => env('GALLERY_GUEST_VOICE_PENDING_MB', 200),

    // Kolik vzkazů (psaných i hlasových) přijme jeden odkaz za 24 hodin.
    // Brání zaplavení stránky odkazu i obrazovky dvojice z víc adres.
    'guest_comments_per_day' => env('GALLERY_GUEST_COMMENTS_PER_DAY', 200),

    // Kolik nočních záloh databáze (`gallery:zaloha`) držet. Leží ve
    // `storage/app/private/zalohy`; starší se mažou.
    'backup_keep' => (int) env('GALLERY_BACKUP_KEEP', 14),

    // Upload limits
    'max_chunk_size_mb' => env('GALLERY_MAX_CHUNK_MB', 64),
    'max_upload_size_gb' => env('GALLERY_MAX_UPLOAD_GB', 32),
    // Server-to-Drive resumable upload chunk. Keep this below PHP/proxy
    // request limits; the job clamps it to 8–256 MB and 256 KB alignment.
    'drive_upload_chunk_mb' => env('GOOGLE_DRIVE_UPLOAD_CHUNK_MB', 64),

    // Supported photo extensions
    'photo_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'heic', 'heif', 'tiff', 'tif'],

    // Supported video extensions
    'video_extensions' => ['mp4', 'mov', 'webm', 'm4v', 'mkv', 'avi'],

    // Geocoding
    'geocoding_enabled' => env('GEOCODING_ENABLED', false),
    'geocoding_provider' => env('GEOCODING_PROVIDER', 'nominatim'),
    'geocoding_api_key' => env('GEOCODING_API_KEY', null),
    'geocoding_rate_limit_per_second' => env('GEOCODING_RATE_LIMIT', 1),

    // Unified transport search. Transitous is a volunteer, best-effort source;
    // keep requests cached and always send a contact URL in the User-Agent.
    'transport' => [
        'transitous_enabled' => env('TRANSITOUS_ENABLED', true),
        'transitous_url' => env('TRANSITOUS_URL', 'https://api.transitous.org/api/v6/plan'),
        'transitous_geocode_url' => env('TRANSITOUS_GEOCODE_URL', 'https://api.transitous.org/api/v1/geocode'),
        'transitous_timeout' => env('TRANSITOUS_TIMEOUT', 8),
        'regiojet_enabled' => env('REGIOJET_SEARCH_ENABLED', true),
        'cache_minutes' => env('TRANSPORT_SEARCH_CACHE_MINUTES', 15),
        'contact' => env('TRANSPORT_API_CONTACT', env('APP_URL')),
    ],

    // Google Cast (optional feature)
    'google_cast_enabled' => env('GOOGLE_CAST_ENABLED', false),

    // Po kolika dnech bez použití přestane platit jakýkoli token — klíč k API
    // i starší přihlášení zařízení bez vlastní platnosti (0 = nikdy). Nová
    // přihlášení navíc vyprší 60 dní od posledního použití (`PrihlaseniZarizeni`),
    // takže u nich rozhoduje kratší z obou lhůt.
    'token_idle_days' => (int) env('GALLERY_TOKEN_IDLE_DAYS', 90),

    // Invite-only registration
    'invite_only' => env('GALLERY_INVITE_ONLY', true),

    // Admin user seeder config
    // Výchozí účty dvojice — stejné, jaké nastavuje migrace `sjednotit_ucty_dvojice`.
    'owner_name' => env('GALLERY_OWNER_NAME', 'Adrian'),
    'owner_email' => env('GALLERY_OWNER_EMAIL', 'info@stanektech.cz'),

    // Provozovatel celé instalace (čárkami oddělené e-maily): tržby, tarify,
    // všechny účty, klíče integrací, plánované úlohy. Vlastník galerie to není —
    // `users.role = owner` má každý, kdo si galerii založí. Bez nastavení je
    // provozovatelem vlastník instalace (`owner_email`).
    'operator_emails' => env('GALLERY_OPERATOR_EMAILS'),

    'partner_name' => env('GALLERY_PARTNER_NAME', 'Makinka Kubíčková'),
    'partner_email' => env('GALLERY_PARTNER_EMAIL', 'marketa@stanektech.cz'),

    'default_space_name' => env('GALLERY_DEFAULT_SPACE', 'Naše galerie'),

    // Public sign-up. Off by default so the instance stays invitation-only until
    // the operator deliberately opens it as a service.
    'registration_open' => (bool) env('GALLERY_REGISTRATION_OPEN', false),

];
