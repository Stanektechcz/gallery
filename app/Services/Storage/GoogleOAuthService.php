<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use App\Models\User;
use Google\Client as GoogleClient;

class GoogleOAuthService
{
    private GoogleClient $client;

    public function __construct()
    {
        $this->client = new GoogleClient();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setScopes([\Google\Service\Drive::DRIVE_FILE]);
        $this->client->setAccessType('offline');
        $this->client->setIncludeGrantedScopes(true);
    }

    /**
     * Generate the OAuth authorization URL.
     * Only forces consent when truly needed.
     */
    public function getAuthorizationUrl(bool $forceConsent = false): string
    {
        if ($forceConsent) {
            $this->client->setPrompt('consent');
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

        // Get user info
        $this->client->setAccessToken($tokenData);
        $oauth2   = new \Google\Service\Oauth2($this->client);
        $userInfo = $oauth2->userinfo->get();

        // Find or create storage connection
        $connection = StorageConnection::firstOrNew([
            'owner_user_id'    => $user->id,
            'provider'         => 'google_drive',
        ]);

        $connection->google_subject_id = $userInfo->getId();
        $connection->account_email     = $userInfo->getEmail();
        $connection->setAccessToken(json_encode($tokenData));
        $connection->token_expires_at  = now()->addSeconds($tokenData['expires_in'] ?? 3600);

        if (isset($tokenData['refresh_token'])) {
            $connection->setRefreshToken($tokenData['refresh_token']);
        }

        $connection->granted_scopes_json = $tokenData['scope'] ?? null;
        $connection->connection_status   = 'healthy';
        $connection->connected_at        = now();
        $connection->revoked_at          = null;
        $connection->save();

        return $connection;
    }

    /**
     * Refresh access token for a connection.
     */
    public function refreshToken(StorageConnection $connection): bool
    {
        $refreshToken = $connection->getRefreshToken();
        if (!$refreshToken) {
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
     * Revoke token at Google.
     */
    public function revokeToken(StorageConnection $connection): bool
    {
        try {
            $token = $connection->getAccessToken();
            if ($token) {
                $this->client->revokeToken($token);
            }
            $connection->markStatus('revoked');
            $connection->update(['revoked_at' => now()]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
