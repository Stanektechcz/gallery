<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Drive;

class GoogleOAuthService
{
    private GoogleClient $client;

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setScopes([Drive::DRIVE_FILE]);
        $this->client->setAccessType('offline');
        $this->client->setIncludeGrantedScopes(true);
    }

    /**
     * Generate the OAuth authorization URL.
     * Only forces consent when truly needed.
     */
    public function getAuthorizationUrl(bool $forceConsent = false, ?string $state = null): string
    {
        if ($forceConsent) {
            $this->client->setPrompt('consent');
        }

        // Náhodný stav ze sezení (viz GoogleOAuthController::redirect()). Bez něj
        // šlo oběti podstrčit kód cizího Disku a fotky by odtekly tam.
        if ($state !== null) {
            $this->client->setState($state);
        }

        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for tokens and persist them.
     */
    public function handleCallback(string $code, User $user): StorageConnection
    {
        $tokenData = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokenData['error'])) {
            throw new \RuntimeException("OAuth error: {$tokenData['error_description']} ({$tokenData['error']})");
        }

        // Get user email via Drive About (works with drive.file scope — no extra scope needed)
        $this->client->setAccessToken($tokenData);
        $driveService = new Drive($this->client);
        $about = $driveService->about->get(['fields' => 'user']);
        $userEmail = $about->getUser()->getEmailAddress();
        $userId = $about->getUser()->getPermissionId();

        // Find or create storage connection
        $connection = StorageConnection::firstOrNew([
            'owner_user_id' => $user->id,
            'provider' => 'google_drive',
        ]);

        $connection->google_subject_id = $userId;
        $connection->account_email = $userEmail;
        $connection->setAccessToken(json_encode($tokenData));
        $connection->token_expires_at = now()->addSeconds($tokenData['expires_in'] ?? 3600);

        if (isset($tokenData['refresh_token'])) {
            $connection->setRefreshToken($tokenData['refresh_token']);
        }

        $connection->granted_scopes_json = $tokenData['scope'] ?? null;
        $connection->connection_status = 'healthy';
        $connection->connected_at = now();
        $connection->revoked_at = null;
        $connection->save();

        return $connection;
    }

    /**
     * Refresh access token for a connection.
     */
    public function refreshToken(StorageConnection $connection): bool
    {
        $refreshToken = $connection->getRefreshToken();
        if (! $refreshToken) {
            $connection->markStatus('refresh_required');

            return false;
        }

        $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
        $newToken = $this->client->getAccessToken();

        if (isset($newToken['error'])) {
            if ($newToken['error'] === 'invalid_grant') {
                $connection->markStatus('refresh_required');
                $connection->markError('invalid_grant', $newToken['error_description'] ?? '');
            }

            return false;
        }

        $connection->setAccessToken(json_encode($newToken));
        $connection->update([
            'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
        ]);
        $connection->markHealthy();

        return true;
    }

    /**
     * Odvolá souhlas u Googlu; `true` jen tehdy, když ho Google potvrdil.
     *
     * Dvě chyby dohromady tvrdily „odpojeno", i když aplikace přístup k Disku
     * dál měla. `Google\Client::revokeToken()` při odmítnutí nevyhodí výjimku,
     * jen vrátí `false` — a to se zahazovalo. A posílal se mu celý uložený JSON
     * tokenu, takže Google odmítal pokaždé. Posílá se proto refresh token
     * (odvolá celý souhlas), jinak přístupový token vytažený z JSONu.
     *
     * Při odmítnutí se připojení tady neoznačuje — to i s poctivou hláškou
     * řeší `GoogleOAuthController::disconnect()`.
     */
    public function revokeToken(StorageConnection $connection): bool
    {
        try {
            $token = $this->tokenKOdvolani($connection);

            if ($token !== null && ! $this->client->revokeToken($token)) {
                return false;
            }

            $connection->markStatus('revoked');
            $connection->update(['revoked_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Samotný token pro Google — ne JSON, ve kterém je uložený přístupový token. */
    private function tokenKOdvolani(StorageConnection $connection): ?string
    {
        $refresh = $connection->getRefreshToken();

        if (is_string($refresh) && $refresh !== '') {
            return $refresh;
        }

        $ulozeny = $connection->getAccessToken();

        if (! is_string($ulozeny) || $ulozeny === '') {
            return null;
        }

        $data = json_decode($ulozeny, true);
        $pristup = is_array($data) ? ($data['access_token'] ?? null) : $ulozeny;

        return is_string($pristup) && $pristup !== '' ? $pristup : null;
    }
}
