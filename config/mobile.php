<?php

return [
    'android' => [
        // Stable application id. Never change it after the first signed release.
        'package_name' => env('ANDROID_APP_PACKAGE', 'cz.stanektech.maki'),
        'version' => env('ANDROID_APP_VERSION', '1.0.0'),
        'disk' => env('ANDROID_APP_DISK', 'local'),
        'path' => env('ANDROID_APP_PATH', 'mobile/maki-gallery.apk'),
        'metadata_path' => env('ANDROID_APP_METADATA_PATH', 'mobile/maki-gallery.json'),
        // Read-only fallback committed with a release. It keeps downloads working
        // when CLI and PHP-FPM use different storage owners or mounts.
        'bundled_path' => env('ANDROID_APP_BUNDLED_PATH', 'release-assets/android/maki-gallery-1.0.0.apk'),
        // Optional CDN/release URL. The stable /app/android/download endpoint redirects to it.
        'download_url' => env('ANDROID_APP_DOWNLOAD_URL'),
        'sha256' => env('ANDROID_APP_SHA256'),
        // SHA-256 fingerprint of the release signing certificate for TWA verification.
        'certificate_fingerprint' => env('ANDROID_APP_SHA256_FINGERPRINT'),
    ],
];
