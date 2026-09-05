<?php

return [
    // Origin, na kterém aplikace běží. Přihlašovací klíč (WebAuthn) je vázaný
    // právě na tuhle doménu — po přesunu se otisk registruje znovu.
    'rp_id' => env('GALERIE_RP_ID', 'vzpominky.example'),
    'rp_name' => env('GALERIE_RP_NAME', 'Naše vzpomínky'),

    // Jak dlouho platí challenge (sekundy). Delší okno = větší prostor pro
    // přehrání odpovědi, kratší = selhání na pomalé síti.
    'webauthn_challenge_ttl' => 120,

    // Data mechanismů pro GET /api/mechanisms. Generuje se z galerie-mechanismy.js
    // (node tools/export-seeds.js) — klíče jsou stejné, aby klient nemusel nic mapovat.
    'mechanisms_path' => resource_path('galerie/mechanismy.json'),

    // Kam se ukládají originály: 'local' pro vlastní úložiště, 's3' pro S3/MinIO.
    // Kód nahrávání je pro obojí stejný — mění se jen disk.
    'media_disk' => env('GALERIE_MEDIA_DISK', 'local'),

    // Klíče pro Web Push (VAPID). Veřejný se posílá do stránky jako
    // window.GALERIE_VAPID_KEY, privátní zůstává na serveru.
    'vapid_public' => env('GALERIE_VAPID_PUBLIC'),
    'vapid_private' => env('GALERIE_VAPID_PRIVATE'),
    'vapid_subject' => env('GALERIE_VAPID_SUBJECT', 'mailto:adrian.stanek@gmail.com'),
];
