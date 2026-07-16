<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MobileAppController extends Controller
{
    public function index(Request $request): Response
    {
        [$localAvailable, $size] = $this->localPackageStatus();
        $externalUrl = $this->externalDownloadUrl();
        $metadata = $this->packageMetadata();

        return Inertia::render('MobileApp/Index', [
            'android' => [
                'available' => $localAvailable || $externalUrl !== null,
                'download_url' => route('mobile-app.android.download'),
                'version' => (string) ($metadata['version'] ?? config('mobile.android.version', '1.0.0')),
                'package_name' => (string) config('mobile.android.package_name', 'cz.stanektech.maki'),
                'sha256' => $metadata['sha256'] ?? config('mobile.android.sha256'),
                'size_bytes' => $metadata['size_bytes'] ?? $size,
                'verified_origin' => filled(config('mobile.android.certificate_fingerprint')),
            ],
            'apkStatus' => $request->string('apk')->toString(),
        ]);
    }

    public function download(): StreamedResponse|RedirectResponse
    {
        [$localAvailable] = $this->localPackageStatus();
        if ($localAvailable) {
            $disk = Storage::disk((string) config('mobile.android.disk', 'local'));
            $path = (string) config('mobile.android.path', 'mobile/maki-gallery.apk');
            $metadata = $this->packageMetadata();
            $version = preg_replace('/[^0-9A-Za-z._-]+/', '-', (string) ($metadata['version'] ?? config('mobile.android.version', '1.0.0')));

            return $disk->download($path, "maki-gallery-{$version}.apk", [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($externalUrl = $this->externalDownloadUrl()) {
            return redirect()->away($externalUrl);
        }

        return redirect()->route('mobile-app.index', ['apk' => 'preparing']);
    }

    public function assetLinks(): JsonResponse
    {
        $fingerprint = trim((string) config('mobile.android.certificate_fingerprint', ''));
        $packageName = trim((string) config('mobile.android.package_name', ''));

        $links = $fingerprint !== '' && $packageName !== '' ? [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $packageName,
                'sha256_cert_fingerprints' => [$fingerprint],
            ],
        ]] : [];

        return response()->json($links, headers: [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{bool, int|null} */
    private function localPackageStatus(): array
    {
        try {
            $disk = Storage::disk((string) config('mobile.android.disk', 'local'));
            $path = (string) config('mobile.android.path', 'mobile/maki-gallery.apk');
            if ($path === '' || ! $disk->exists($path)) return [false, null];

            return [true, $disk->size($path)];
        } catch (Throwable) {
            return [false, null];
        }
    }

    private function externalDownloadUrl(): ?string
    {
        $url = trim((string) config('mobile.android.download_url', ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) return null;

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === 'https') return $url;
        if ($scheme === 'http' && app()->environment(['local', 'testing'])) return $url;

        return null;
    }

    /** @return array<string, mixed> */
    private function packageMetadata(): array
    {
        try {
            $disk = Storage::disk((string) config('mobile.android.disk', 'local'));
            $path = (string) config('mobile.android.metadata_path', 'mobile/maki-gallery.json');
            if ($path === '' || ! $disk->exists($path)) return [];

            $metadata = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);
            return is_array($metadata) ? $metadata : [];
        } catch (Throwable) {
            return [];
        }
    }
}
