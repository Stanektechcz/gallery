<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MobileAppDistributionTest extends TestCase
{
    public function test_public_installation_centre_is_available_without_login(): void
    {
        Storage::fake('local');

        $this->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MobileApp/Index')
                ->where('android.available', false)
                ->where('android.download_url', route('mobile-app.android.download'))
                ->where('android.package_name', 'cz.stanektech.maki'));
    }

    public function test_signed_apk_can_be_downloaded_from_stable_public_link(): void
    {
        Storage::fake('local');
        config()->set('mobile.android.disk', 'local');
        config()->set('mobile.android.path', 'mobile/maki-gallery.apk');
        config()->set('mobile.android.metadata_path', 'mobile/maki-gallery.json');
        Storage::disk('local')->put('mobile/maki-gallery.apk', "PK\x03\x04signed-apk");
        Storage::disk('local')->put('mobile/maki-gallery.json', json_encode(['version' => '1.4.0', 'sha256' => 'abc', 'size_bytes' => 14]));

        $this->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('android.available', true)
                ->where('android.version', '1.4.0')
                ->where('android.sha256', 'abc'));

        $this->get('/app/android/download')
            ->assertOk()
            ->assertDownload('maki-gallery-1.4.0.apk')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_missing_apk_returns_to_installation_help_instead_of_404(): void
    {
        Storage::fake('local');

        $this->get('/app/android/download')
            ->assertRedirect(route('mobile-app.index', ['apk' => 'preparing']));
    }

    public function test_asset_links_are_published_only_after_release_fingerprint_is_configured(): void
    {
        config()->set('mobile.android.package_name', 'cz.stanektech.maki');
        config()->set('mobile.android.certificate_fingerprint', null);
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);

        config()->set('mobile.android.certificate_fingerprint', 'AA:BB:CC');
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.package_name', 'cz.stanektech.maki')->assertJsonPath('0.target.sha256_cert_fingerprints.0', 'AA:BB:CC');
    }

    public function test_publish_command_stores_apk_and_integrity_metadata(): void
    {
        Storage::fake('local');
        config()->set('mobile.android.disk', 'local');
        config()->set('mobile.android.path', 'mobile/maki-gallery.apk');
        config()->set('mobile.android.metadata_path', 'mobile/maki-gallery.json');
        $temporary = tempnam(sys_get_temp_dir(), 'maki-apk-');
        $apk = $temporary.'.apk';
        rename($temporary, $apk);
        file_put_contents($apk, "PK\x03\x04release-content");

        try {
            $status = Artisan::call('gallery:publish-android-app', ['apk' => $apk, '--app-version' => '2.3.1']);
            $this->assertSame(0, $status, Artisan::output());

            $this->assertTrue(
                Storage::disk('local')->exists('mobile/maki-gallery.apk'),
                'Publikované soubory: '.json_encode(Storage::disk('local')->allFiles(), JSON_UNESCAPED_SLASHES),
            );
            Storage::disk('local')->assertExists('mobile/maki-gallery.json');
            $metadata = json_decode(Storage::disk('local')->get('mobile/maki-gallery.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('2.3.1', $metadata['version']);
            $this->assertSame(hash_file('sha256', $apk), $metadata['sha256']);
            $this->assertSame(filesize($apk), $metadata['size_bytes']);
        } finally {
            @unlink($apk);
        }
    }

    public function test_publish_command_explains_that_missing_example_path_must_be_uploaded_first(): void
    {
        $missing = storage_path('app/definitely-missing-app-release-signed.apk');

        $this->artisan('gallery:publish-android-app', ['apk' => $missing, '--app-version' => '1.0.0'])
            ->expectsOutputToContain('Tento příkaz APK nevytváří')
            ->expectsOutputToContain('Nahrajte jej na server')
            ->assertFailed();
    }
}
