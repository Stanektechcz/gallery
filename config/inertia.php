<?php

/*
 * Jen to, v čem se aplikace liší od výchozího nastavení balíčku.
 *
 * Balíček hledá stránky v `resources/js/pages` (malé „p"), ale naše složka je
 * `resources/js/Pages`. Na Windows to nevadí, protože souborový systém velikost
 * písmen nerozlišuje — na Linuxu (CI i produkce) ale `assertInertia` hlásil,
 * že stránka neexistuje, a 23 testů padalo jen tam.
 *
 * Laravel slučuje konfiguraci balíčku s touhle jen po klíčích nejvyšší úrovně,
 * takže `pages` musí být celé: kdyby tu byla jen `paths`, zmizely by přípony
 * a kontrola by nenašla žádný soubor.
 */
return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/Pages'),
        ],
        'extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],
];
