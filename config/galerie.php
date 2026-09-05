<?php

return [
    // Doména, na které aplikace běží. Přihlašovací klíč (WebAuthn) je vázaný
    // právě na ni — po přesunu se otisk registruje znovu. Bez proměnné se bere
    // host z APP_URL, aby vývoj na localhostu fungoval bez dalšího nastavování.
    'rp_id' => env('GALERIE_RP_ID')
        ?: (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'rp_name' => env('GALERIE_RP_NAME', 'Naše vzpomínky'),

    /*
     * Odkud smí otisk přijít (plné originy, čárkou oddělené).
     *
     * Knihovna bez tohohle seznamu trvá na HTTPS — a vývojový server běží na
     * `http://localhost:8765`, takže by se otisk lokálně nedal ani vyzkoušet.
     * Prázdná hodnota tu přísnou kontrolu vrací zpátky, takže produkce, která
     * proměnnou nenastaví, nic neztrácí.
     */
    'rp_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GALERIE_RP_ORIGINS', (string) env('APP_URL', ''))),
    ))),

    // Jak dlouho platí challenge (sekundy). Delší okno = větší prostor pro
    // přehrání odpovědi, kratší = selhání na pomalé síti.
    'webauthn_challenge_ttl' => 120,

    // Data mechanismů pro GET /api/mechanisms. Generuje se z galerie-mechanismy.js
    // (node tools/export-seeds.js) — klíče jsou stejné, aby klient nemusel nic mapovat.
    'mechanisms_path' => resource_path('galerie/mechanismy.json'),

    // Dokumenty prototypu a jeho service worker. Leží mimo `public/` schválně:
    // do dokumentu se při odeslání vkládá napojení na backend a service worker
    // potřebuje hlavičku, kterou by statický soubor nedostal.
    'prototyp_path' => resource_path('galerie'),

    // Kam se ukládají originály: 'local' pro vlastní úložiště, 's3' pro S3/MinIO.
    // Kód nahrávání je pro obojí stejný — mění se jen disk.
    'media_disk' => env('GALERIE_MEDIA_DISK', 'local'),

    /*
     * Klíče pro Web Push (VAPID). Veřejný se posílá do stránky jako
     * window.GALERIE_VAPID_KEY, privátní zůstává na serveru.
     *
     * Berou se ze `config/push.php`, které aplikace používá už dnes. Prototyp
     * navrhoval vlastní `GALERIE_VAPID_*`, jenže druhá dvojice klíčů by znamenala,
     * že se část upozornění posílá pod jednou identitou a část pod druhou —
     * a prohlížeč odběr registrovaný na první klíč u druhého odmítne.
     */
    'vapid_public' => env('VAPID_PUBLIC_KEY'),
    'vapid_private' => env('VAPID_PRIVATE_KEY'),
    'vapid_subject' => env('VAPID_SUBJECT'),
];
