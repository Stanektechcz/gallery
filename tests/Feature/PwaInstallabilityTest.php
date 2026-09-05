<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaInstallabilityTest extends TestCase
{
    public function test_manifest_has_android_installability_metadata_and_existing_icons(): void
    {
        $manifestPath = public_path('manifest.webmanifest');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('cs-CZ', $manifest['lang']);
        $this->assertFalse($manifest['prefer_related_applications']);

        $icons = collect($manifest['icons'])->keyBy('sizes');
        $this->assertTrue($icons->has('192x192'));
        $this->assertTrue($icons->has('512x512'));

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    /**
     * Kořenový service worker je od nasazení prototypu ten jeho.
     *
     * Původní test hlídal, že worker `/api/` **necachuje**. To už neplatí, a je
     * to záměr: prototyp bez toho neumí offline. Podstatné zůstává, že se z paměti
     * nikdy nečte, dokud je síť — jinak by dvojice viděla včerejší čísla a nepoznala
     * to. Zbytek (dosah, hlavička) hlídá `Galerie\DoruceniTest`.
     */
    public function test_root_service_worker_prefers_the_network_for_documents_and_api(): void
    {
        $worker = (string) $this->get('/sw.js')->assertOk()->getContent();

        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js'", (string) file_get_contents(resource_path('js/Components/PwaLifecycle.tsx')));

        // Dokument i data: nejdřív síť, paměť je jen záložka pro offline.
        $this->assertStringContainsString("req.mode === 'navigate'", $worker);
        $this->assertStringContainsString('const r = await fetch(req);', $worker);

        // Zápisy, které vznikly bez signálu, se nesmí ztratit — jdou do fronty.
        $this->assertStringContainsString("req.method === 'PATCH'", $worker);
        $this->assertStringContainsString('queuePush', $worker);
    }

    /**
     * Upozornění musí otevřít aplikaci, ne soubor ze statického hostu.
     *
     * Worker ze ZIPu otevírá `Galerie mobil aplikace.dc.html`; na serveru je
     * aplikace na `/`, takže by kliknutí skončilo na neexistující adrese.
     */
    public function test_notification_opens_the_application_not_a_file(): void
    {
        $worker = (string) $this->get('/sw.js')->assertOk()->getContent();

        $this->assertStringNotContainsString('Galerie%20mobil%20aplikace.dc.html', $worker);
        $this->assertStringContainsString("const target = '/' + (route ? '#' + route : '');", $worker);
        $this->assertStringContainsString('self.registration.scope', $worker);
    }

    public function test_application_template_links_root_manifest_and_uses_safe_area_viewport(): void
    {
        $template = (string) file_get_contents(resource_path('views/app.blade.php'));

        $this->assertStringContainsString('href="/manifest.webmanifest"', $template);
        $this->assertStringContainsString('viewport-fit=cover', $template);
        $this->assertStringContainsString('href="/icons/apple-touch-icon.png"', $template);
    }
}
