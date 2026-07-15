<?php

namespace App\Services\Banking;

use App\Models\IntegrationSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class GoCardlessBankDataClient
{
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    public function test(): void
    {
        $this->institutions('CZ');
    }

    public function institutions(string $country): array
    {
        return $this->request()->get('/institutions/', ['country' => strtoupper($country)])->throw()->json();
    }

    public function createAgreement(array $payload): array
    {
        return $this->request()->post('/agreements/enduser/', $payload)->throw()->json();
    }

    public function createRequisition(array $payload): array
    {
        return $this->request()->post('/requisitions/', $payload)->throw()->json();
    }

    public function requisition(string $id): array
    {
        return $this->request()->get("/requisitions/{$id}/")->throw()->json();
    }

    public function deleteRequisition(string $id): void
    {
        $this->request()->delete("/requisitions/{$id}/")->throw();
    }

    public function accountDetails(string $id): array
    {
        return $this->request()->get("/accounts/{$id}/details/")->throw()->json();
    }

    public function balances(string $id): array
    {
        return $this->request()->get("/accounts/{$id}/balances/")->throw()->json();
    }

    public function transactions(string $id): array
    {
        return $this->request()->get("/accounts/{$id}/transactions/")->throw()->json();
    }

    private function request(): PendingRequest
    {
        // Consent-creating POST requests must never be retried implicitly: a
        // network timeout could otherwise create two agreements/requisitions.
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->withToken($this->accessToken())->timeout(20);
    }

    private function accessToken(): string
    {
        $setting = IntegrationSetting::where('provider', 'gocardless_bank_data')->where('is_enabled', true)->first();
        $config = $setting?->config() ?? [];
        abort_unless(filled($config['secret_id'] ?? null) && filled($config['secret_key'] ?? null), 424, 'Nejprve v administraci nastavte GoCardless Bank Account Data.');
        $cacheKey = 'gocardless-bank-token:'.hash('sha256', $config['secret_id']);
        if ($encrypted = Cache::get($cacheKey)) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                Cache::forget($cacheKey);
            }
        }
        $response = Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->timeout(20)
            ->post('/token/new/', ['secret_id' => $config['secret_id'], 'secret_key' => $config['secret_key']])->throw()->json();
        abort_unless(filled($response['access'] ?? null), 502, 'Poskytovatel bankovních dat nevrátil přístupový token.');
        Cache::put($cacheKey, Crypt::encryptString($response['access']), max(60, min(82800, ((int) ($response['access_expires'] ?? 86400)) - 300)));

        return $response['access'];
    }
}
