<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use App\Support\VerejnaAdresa;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Somebody's own storage, over WebDAV.
 *
 * The answer to "let me use my account" for every service that has no OAuth: Nextcloud,
 * ownCloud, Koofr, pCloud, Box, a NAS in somebody's flat. One protocol instead of one
 * integration each, and the person keeps their files on hardware or a provider they chose.
 *
 * The credential is an app password, never the account password. Every service worth
 * connecting issues them, they can be revoked without changing anything else, and they
 * usually cannot be used to sign in to the account itself. The screen says so; this refuses
 * to pretend the difference does not matter.
 *
 * There is no refresh: an app password does not expire, which is why this needs no
 * TokenRefresher and why a connection that stops working means somebody revoked it.
 *
 * Adresu zadává zákazník, ale otevírá ji server. Každý požadavek proto jde jen na
 * veřejnou adresu (`VerejnaAdresa`), bez následování přesměrování a s časovým
 * limitem — jinak by šlo přes připojení úložiště zkoumat vnitřní síť serveru.
 */
class WebDavClient
{
    /** Anything larger goes as one PUT anyway; this only stops us buffering a film in memory. */
    private const MAX_BYTES = 200 * 1024 * 1024;

    /** Sekundy na navázání spojení. */
    private const LIMIT_SPOJENI = 5;

    /** Sekundy na ověření a na vytvoření složky. */
    private const LIMIT_DOTAZU = 15;

    /** Sekundy na nahrání jednoho souboru (až 200 MB). */
    private const LIMIT_NAHRAVANI = 300;

    public const HLASKA_ADRESA = 'Adresa nevede na veřejný server. Úložiště v místní nebo vnitřní síti připojit nejde.';

    private const HLASKA_NEDOSTUPNE = 'Server na zadané adrese neodpověděl jako úložiště WebDAV. Zkontrolujte adresu.';

    private const HLASKA_PRIHLASENI = 'Přihlášení odmítnuto. Zkontrolujte uživatele a heslo aplikace.';

    /**
     * Ověří adresu a přihlášení, aniž by cokoli uložil.
     *
     * Připojení se tak zapíše až po úspěšném ověření — dřív se údaje přepsaly
     * předem a nepovedený pokus pak smazal i původní, funkční připojení.
     *
     * Hláška je vždy vlastní a obecná. Kód odpovědi cizího serveru by z ověření
     * udělal průzkumníka adres.
     *
     * @return array{ok: bool, code?: string, error?: string}
     */
    public function overUdaje(string $url, string $user, string $pass): array
    {
        $http = $this->pozadavek(['url' => $url, 'user' => $user, 'pass' => $pass], self::LIMIT_DOTAZU);

        if (! $http) {
            return ['ok' => false, 'code' => 'unsafe_host', 'error' => self::HLASKA_ADRESA];
        }

        // PROPFIND with depth 0 asks "does this exist and may I see it", which is the
        // cheapest question that proves both the address and the password.
        try {
            $response = $http->withHeaders(['Depth' => '0'])->send('PROPFIND', $url);
        } catch (\Throwable $e) {
            Log::info('WebDAV: ověření se nepodařilo', ['error' => $e::class]);

            return ['ok' => false, 'code' => 'unreachable', 'error' => self::HLASKA_NEDOSTUPNE];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'code' => 'unauthorized', 'error' => self::HLASKA_PRIHLASENI];
        }

        // Jen 2xx (WebDAV odpovídá 207). Přesměrování se nenásleduje a úspěchem není —
        // mohlo by vést na adresu, kterou by kontrola neveřejných sítí nepustila.
        if (! $response->successful()) {
            return ['ok' => false, 'code' => 'unreachable', 'error' => self::HLASKA_NEDOSTUPNE];
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function probe(StorageConnection $connection): array
    {
        $credentials = $this->credentials($connection);
        if (! $credentials) {
            return ['ok' => false, 'error' => 'Přihlašovací údaje se nepodařilo přečíst.'];
        }

        $vysledek = $this->overUdaje($credentials['url'], $credentials['user'], $credentials['pass']);

        if (! $vysledek['ok']) {
            $this->fail($connection, $vysledek['code'] ?? 'unreachable', $vysledek['error'] ?? self::HLASKA_NEDOSTUPNE);

            return ['ok' => false, 'error' => $vysledek['error'] ?? self::HLASKA_NEDOSTUPNE];
        }

        $connection->forceFill([
            'connection_status' => StorageConnection::STATUS_HEALTHY,
            'last_successful_request_at' => now(),
            'last_error_at' => null, 'last_error_code' => null, 'last_error_message' => null,
        ])->save();

        return ['ok' => true];
    }

    /** @return array{ok: bool, path?: string, size?: int, error?: string} */
    public function upload(StorageConnection $connection, string $remotePath, string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Soubor je nad 200 MB.'];
        }

        $credentials = $this->credentials($connection);
        if (! $credentials) {
            return ['ok' => false, 'error' => 'Přihlašovací údaje se nepodařilo přečíst.'];
        }

        // Adresa se posuzuje znovu při každém nahrání: řádek mohl vzniknout před
        // touhle kontrolou a jméno v DNS mohlo mezitím začít ukazovat jinam.
        $http = $this->pozadavek($credentials, self::LIMIT_NAHRAVANI);
        if (! $http) {
            $this->fail($connection, 'unsafe_host', self::HLASKA_ADRESA);

            return ['ok' => false, 'error' => self::HLASKA_ADRESA];
        }

        $base = rtrim($credentials['url'], '/');
        $target = $base.'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($remotePath, '/'))));

        try {
            // The folder has to exist first; WebDAV will not make one on the way. MKCOL on a
            // folder that is already there answers 405, which is a success for our purposes.
            $this->ensureFolder(clone $http, $base, dirname(ltrim($remotePath, '/')));

            $response = (clone $http)
                ->withBody($contents, 'application/octet-stream')
                ->put($target);
        } catch (\Throwable $e) {
            Log::info('WebDAV: nahrání se nepodařilo', ['error' => $e::class]);
            $this->fail($connection, 'unreachable', 'Úložiště se nepodařilo zastihnout.');

            return ['ok' => false, 'error' => 'Úložiště se nepodařilo zastihnout.'];
        }

        if (! $response->successful()) {
            $reason = 'Nahrání selhalo (HTTP '.$response->status().').';
            $this->fail($connection, 'upload_failed', $reason);

            return ['ok' => false, 'error' => $reason];
        }

        return ['ok' => true, 'path' => $remotePath, 'size' => strlen($contents)];
    }

    public function folderFor(StorageConnection $connection): string
    {
        return 'MAKI Gallery/prostor-'.$connection->gallery_space_id;
    }

    /** Creates each level in turn; a level that exists answers 405 and is stepped over. */
    private function ensureFolder(PendingRequest $http, string $base, string $folder): void
    {
        $walked = '';

        foreach (array_filter(explode('/', $folder)) as $segment) {
            $walked .= ($walked ? '/' : '').rawurlencode($segment);

            $http->send('MKCOL', $base.'/'.$walked);
        }
    }

    /**
     * Požadavek na úložiště, nebo `null`, když adresa nevede na veřejný server.
     *
     * Jméno se přeloží tady a spojení se na přeloženou adresu připne. Jinak by
     * se jméno při spojení překládalo znovu a mezi kontrolou a spojením mohlo
     * začít ukazovat dovnitř sítě (DNS rebinding).
     *
     * @param  array{url: string, user: string, pass: string}  $credentials
     */
    private function pozadavek(array $credentials, int $limit): ?PendingRequest
    {
        $casti = parse_url($credentials['url']);

        if (! is_array($casti) || strtolower($casti['scheme'] ?? '') !== 'https'
            || ! filled($casti['host'] ?? null) || isset($casti['user']) || isset($casti['pass'])) {
            return null;
        }

        // IPv6 adresa přichází z URL v hranatých závorkách.
        $host = trim(strtolower($casti['host']), '[]');
        $adresy = VerejnaAdresa::adresy($host, prisne: true);

        if ($adresy === null) {
            return null;
        }

        $http = Http::withBasicAuth($credentials['user'], $credentials['pass'])
            ->withoutRedirecting()
            ->connectTimeout(self::LIMIT_SPOJENI)
            ->timeout($limit);

        if (! filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
            $ip = str_contains($adresy[0], ':') ? '['.$adresy[0].']' : $adresy[0];
            $port = (int) ($casti['port'] ?? 443);

            $http = $http->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]]);
        }

        return $http;
    }

    /** @return array{url: string, user: string, pass: string}|null */
    private function credentials(StorageConnection $connection): ?array
    {
        try {
            $raw = json_decode(Crypt::decryptString($connection->encrypted_access_token ?? ''), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($raw) || ! filled($raw['url'] ?? null)) {
            return null;
        }

        return ['url' => $raw['url'], 'user' => $raw['user'] ?? '', 'pass' => $raw['pass'] ?? ''];
    }

    private function fail(StorageConnection $connection, string $code, string $message): void
    {
        $connection->forceFill([
            'connection_status' => StorageConnection::STATUS_ERROR,
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();
    }
}
