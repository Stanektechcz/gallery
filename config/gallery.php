<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gallery Application Settings
    |--------------------------------------------------------------------------
    */

    'drive_root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', 'Stanektech Gallery'),

    'ffmpeg_path'   => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path'  => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    'exiftool_path' => env('EXIFTOOL_PATH', '/usr/bin/exiftool'),

    'media_temp_disk'    => env('MEDIA_TEMP_DISK', 'local'),
    'media_variants_disk' => env('MEDIA_VARIANTS_DISK', 'public'),

    // Trash retention (days)
    'trash_retention_days' => env('GALLERY_TRASH_RETENTION', 30),

    // Cache settings
    'variant_cache_max_size_gb' => env('GALLERY_VARIANT_CACHE_GB', 20),
    'variant_cache_max_age_days' => env('GALLERY_VARIANT_CACHE_DAYS', 90),

    // Upload limits
    'max_chunk_size_mb'  => env('GALLERY_MAX_CHUNK_MB', 64),
    'max_upload_size_gb' => env('GALLERY_MAX_UPLOAD_GB', 32),

    // Supported photo extensions
    'photo_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'heic', 'heif', 'tiff', 'tif'],

    // Supported video extensions
    'video_extensions' => ['mp4', 'mov', 'webm', 'm4v', 'mkv', 'avi'],

    // Geocoding
    'geocoding_enabled'  => env('GEOCODING_ENABLED', false),
    'geocoding_provider' => env('GEOCODING_PROVIDER', 'nominatim'),
    'geocoding_api_key'  => env('GEOCODING_API_KEY', null),
    'geocoding_rate_limit_per_second' => env('GEOCODING_RATE_LIMIT', 1),

    // Google Cast (optional feature)
    'google_cast_enabled' => env('GOOGLE_CAST_ENABLED', false),

    // Invite-only registration
    'invite_only' => env('GALLERY_INVITE_ONLY', true),

    // Admin user seeder config
    'owner_name'  => env('GALLERY_OWNER_NAME', 'Adrian'),
    'owner_email' => env('GALLERY_OWNER_EMAIL', ''),

    'partner_name'  => env('GALLERY_PARTNER_NAME', 'Makinka'),
    'partner_email' => env('GALLERY_PARTNER_EMAIL', ''),

    'default_space_name' => env('GALLERY_DEFAULT_SPACE', 'Naše galerie'),

];
