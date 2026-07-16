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

    public function test_root_service_worker_does_not_cache_authenticated_api_or_media(): void
    {
        $workerPath = public_path('sw.js');
        $this->assertFileExists($workerPath);

        $worker = (string) file_get_contents($workerPath);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js'", (string) file_get_contents(resource_path('js/Components/PwaLifecycle.tsx')));
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringNotContainsString("startsWith('/api/", $worker);
        $this->assertStringNotContainsString("startsWith('/files/", $worker);
        $this->assertStringNotContainsString("startsWith('/media/", $worker);
    }

    public function test_application_template_links_root_manifest_and_uses_safe_area_viewport(): void
    {
        $template = (string) file_get_contents(resource_path('views/app.blade.php'));

        $this->assertStringContainsString('href="/manifest.webmanifest"', $template);
        $this->assertStringContainsString('viewport-fit=cover', $template);
        $this->assertStringContainsString('href="/icons/apple-touch-icon.png"', $template);
    }
}
