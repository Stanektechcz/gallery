<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankBalanceSnapshot;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BankingIntegrationService
{
    public function __construct(private readonly GoCardlessBankDataClient $client, private readonly BankTransactionClassifier $classifier,
        private readonly TripBankReconciliationService $reconciliation) {}

    public function institutions(string $country = 'CZ'): array
    {
        return collect($this->client->institutions($country))->filter(fn ($item) => str_contains(strtolower($item['name'] ?? ''), 'revolut'))
            ->map(fn ($item) => collect($item)->only(['id', 'name', 'bic', 'logo', 'transaction_total_days', 'max_access_valid_for_days', 'max_access_valid_for_days_reconfirmation'])->all())->values()->all();
    }

    public function connect(GallerySpace $space, User $user, array $data): array
    {
        $institution = collect($this->client->institutions($data['country'] ?? 'CZ'))->firstWhere('id', $data['institution_id']);
        abort_unless($institution, 422, 'Vybraná banka není pro tuto zemi dostupná.');
        $state = Str::random(64);
        $connection = BankConnection::create(['gallery_space_id' => $space->id, 'connected_by' => $user->id,
            'provider' => 'gocardless', 'institution_id' => $institution['id'], 'institution_name' => $institution['name'],
            'oauth_state_hash' => hash('sha256', $state), 'status' => 'pending', 'sync_enabled' => true,
            'consent_expires_at' => now()->addDays(min(90, (int) ($institution['max_access_valid_for_days'] ?? 90))),
            'encrypted_metadata' => ['return_trip_id' => $data['return_trip_id'] ?? null]]);
        try {
            $historyDays = min(730, max(90, (int) ($institution['transaction_total_days'] ?? 90)));
            $accessDays = min(90, max(1, (int) ($institution['max_access_valid_for_days'] ?? 90)));
            $agreement = $this->client->createAgreement(['institution_id' => $institution['id'], 'max_historical_days' => $historyDays,
                'access_valid_for_days' => $accessDays, 'access_scope' => ['balances', 'details', 'transactions']]);
            $redirect = route('banking.callback', ['connection' => $connection->uuid, 'state' => $state]);
            $requisition = $this->client->createRequisition(['redirect' => $redirect, 'institution_id' => $institution['id'],
                'reference' => $connection->uuid, 'agreement' => $agreement['id'], 'user_language' => 'CS']);
            $connection->update(['agreement_id' => $agreement['id'], 'requisition_id' => $requisition['id']]);

            return ['connection' => $connection->fresh(), 'authorization_url' => $requisition['link']];
        } catch (\Throwable $exception) {
            $connection->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }

    public function complete(BankConnection $connection, string $state): array
    {
        abort_unless(hash_equals((string) $connection->oauth_state_hash, hash('sha256', $state)), 403, 'Neplatný stav bankovního připojení.');
        abort_unless($connection->provider === 'gocardless' && $connection->requisition_id, 422, 'Bankovní připojení nelze dokončit.');
        $requisition = $this->client->requisition($connection->requisition_id);
        abort_unless(in_array($requisition['status'] ?? null, ['LN', 'LINKED'], true), 422, 'Revolut zatím připojení nepotvrdil.');
        $connection->update(['status' => 'active', 'oauth_state_hash' => null, 'last_error' => null]);

        return $this->sync($connection->fresh());
    }

    public function disconnect(BankConnection $connection): array
    {
        $providerRevoked = false;
        $providerError = null;
        if ($connection->provider === 'gocardless' && $connection->requisition_id) {
            try {
                $this->client->deleteRequisition($connection->requisition_id);
                $providerRevoked = true;
            } catch (\Throwable $exception) {
                $providerError = mb_substr($exception->getMessage(), 0, 1000);
                report($exception);
            }
        }
        $connection->update(['sync_enabled' => false, 'status' => 'revoked', 'revoked_at' => now(), 'last_error' => $providerError]);

        return ['disconnected' => true, 'provider_consent_revoked' => $providerRevoked, 'history_preserved' => true];
    }

    public function sync(BankConnection $connection): array
    {
        $lock = Cache::lock("bank-sync:{$connection->id}", 240);
        abort_unless($lock->get(), 409, 'Toto bankovní připojení se právě synchronizuje. Počkejte na jeho dokončení.');
        try {
            return $this->syncUnlocked($connection);
        } finally {
            $lock->release();
        }
    }

    private function syncUnlocked(BankConnection $connection): array
    {
        abort_unless($connection->provider === 'gocardless' && $connection->requisition_id, 422, 'Toto připojení nepodporuje automatickou synchronizaci.');
        abort_if($connection->revoked_at || ! $connection->sync_enabled, 409, 'Synchronizace tohoto připojení je vypnutá.');
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);
        try {
            $requisition = $this->client->requisition($connection->requisition_id);
            if (in_array($requisition['status'] ?? null, ['EX', 'EXPIRED'], true)) {
                $connection->update(['status' => 'expired']);
                abort(409, 'Souhlas s bankou vypršel. Připojte Revolut znovu.');
            }
            $inserted = 0;
            $updated = 0;
            foreach (($requisition['accounts'] ?? []) as $externalAccountId) {
                [$new, $changed] = $this->syncAccount($connection, $externalAccountId);
                $inserted += $new;
                $updated += $changed;
            }
            $connection->update(['status' => 'active', 'last_success_at' => now(), 'last_error' => null]);
            $links = $this->reconciliation->reconcileSpace($connection->space);

            return ['connection' => $connection->fresh(), 'accounts' => $connection->accounts()->count(), 'transactions_inserted' => $inserted,
                'transactions_updated' => $updated, 'trip_links_created' => $links];
        } catch (\Throwable $exception) {
            $connection->update(['last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }

    private function syncAccount(BankConnection $connection, string $externalId): array
    {
        $detailsResponse = $this->client->accountDetails($externalId);
        $details = $detailsResponse['account'] ?? $detailsResponse;
        if (array_is_list($details)) {
            $details = $details[0] ?? [];
        }
        $currency = strtoupper($details['currency'] ?? 'CZK');
        $iban = $details['iban'] ?? data_get($details, 'account.iban') ?? data_get($details, 'account.0.identification');
        $owner = $details['ownerName'] ?? $details['owner_name'] ?? null;
        $multipleOwners = is_array($owner) && count(array_filter($owner)) > 1;
        if (is_array($owner)) {
            $owner = implode(' & ', $owner);
        }
        $hash = hash('sha256', $externalId);
        $account = BankAccount::updateOrCreate(['bank_connection_id' => $connection->id, 'external_id_hash' => $hash], [
            'encrypted_external_id' => $externalId, 'name' => $details['name'] ?? $details['displayName'] ?? $connection->institution_name,
            'owner_name' => $owner, 'iban_last4' => $iban ? substr(preg_replace('/\s+/', '', $iban), -4) : null,
            'currency' => $currency, 'account_type' => $details['cashAccountType'] ?? $details['accountType'] ?? null,
            'is_joint' => $multipleOwners || str_contains(strtolower(($owner ?? '').' '.($details['usage'] ?? '').' '.($details['accountType'] ?? '')), 'joint'), 'is_enabled' => true,
        ]);

        $balanceResponse = $this->client->balances($externalId);
        $balances = collect($balanceResponse['balances'] ?? []);
        $bookedRow = $balances->first(fn ($row) => str_contains(strtolower($row['balanceType'] ?? ''), 'booked')) ?? $balances->first();
        $availableRow = $balances->first(fn ($row) => str_contains(strtolower($row['balanceType'] ?? ''), 'available'));
        $booked = $this->amount(data_get($bookedRow, 'balanceAmount.amount') ?? data_get($bookedRow, 'amount.amount'));
        $available = $this->amount(data_get($availableRow, 'balanceAmount.amount') ?? data_get($availableRow, 'amount.amount'));
        $balanceCurrency = strtoupper(data_get($bookedRow, 'balanceAmount.currency') ?? $currency);
        $account->update(['current_balance' => $booked, 'available_balance' => $available, 'balance_updated_at' => now(), 'currency' => $balanceCurrency]);
        BankBalanceSnapshot::firstOrCreate(['bank_account_id' => $account->id, 'snapshot_key' => hash('sha256', 'api:'.now()->format('Y-m-d-H'))],
            ['booked_balance' => $booked, 'available_balance' => $available, 'currency' => $balanceCurrency, 'captured_at' => now(), 'source' => 'api']);

        $response = $this->client->transactions($externalId);
        $groups = $response['transactions'] ?? [];
        $inserted = 0;
        $updated = 0;
        $oldest = null;
        foreach (['booked' => 'booked', 'pending' => 'pending'] as $group => $status) {
            foreach (($groups[$group] ?? []) as $row) {
                $normalized = $this->normalizeTransaction($connection->space, $account, $row, $status);
                $oldest = ! $oldest || $normalized['booked_at']->lt($oldest) ? $normalized['booked_at'] : $oldest;
                $existing = BankTransaction::where('bank_account_id', $account->id)->where('external_id_hash', $normalized['external_id_hash'])->first();
                if ($existing?->category_is_manual) {
                    unset($normalized['category']);
                }
                if ($existing) {
                    $existing->update($normalized);
                    $updated++;
                } else {
                    BankTransaction::create($normalized);
                    $inserted++;
                }
            }
        }
        if ($oldest && (! $account->history_available_from || $oldest->lt($account->history_available_from))) {
            $account->update(['history_available_from' => $oldest->toDateString()]);
        }

        return [$inserted, $updated];
    }

    private function normalizeTransaction(GallerySpace $space, BankAccount $account, array $row, string $status): array
    {
        $amount = $this->amount(data_get($row, 'transactionAmount.amount')) ?? 0.0;
        $indicator = strtoupper((string) ($row['creditDebitIndicator'] ?? ''));
        if ($indicator === 'DBIT' && $amount > 0) {
            $amount *= -1;
        } if ($indicator === 'CRDT' && $amount < 0) {
            $amount = abs($amount);
        }
        $description = collect([$row['remittanceInformationUnstructured'] ?? null, $row['additionalInformation'] ?? null,
            is_array($row['remittanceInformationUnstructuredArray'] ?? null) ? implode(' ', $row['remittanceInformationUnstructuredArray']) : null])->filter()->implode(' · ');
        $merchant = data_get($row, 'merchantName') ?? data_get($row, 'additionalDataStructured.merchantName');
        $counterparty = $amount < 0 ? ($row['creditorName'] ?? null) : ($row['debtorName'] ?? null);
        $external = $row['transactionId'] ?? $row['internalTransactionId'] ?? null;
        $bookedAt = Carbon::parse($row['bookingDateTime'] ?? $row['bookingDate'] ?? $row['valueDate'] ?? now());
        $fallback = implode('|', [$bookedAt->toIso8601String(), $amount, data_get($row, 'transactionAmount.currency'), $description, $merchant, $counterparty]);
        $transactionType = $row['proprietaryBankTransactionCode'] ?? null;
        if (is_array($transactionType)) {
            $transactionType = json_encode($transactionType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $classification = $this->classifier->classify($space, compact('amount', 'description', 'merchant', 'counterparty') + [
            'merchant_name' => $merchant, 'counterparty_name' => $counterparty,
            'transaction_type' => $transactionType, 'bank_transaction_code' => $row['bankTransactionCode'] ?? null]);

        return [
            'bank_account_id' => $account->id, 'external_id_hash' => hash('sha256', $external ?: $fallback),
            'encrypted_external_id' => $external, 'status' => $status, 'direction' => $amount < 0 ? 'debit' : 'credit',
            'amount' => $amount, 'currency' => strtoupper(data_get($row, 'transactionAmount.currency') ?? $account->currency),
            'original_amount' => $this->amount(data_get($row, 'currencyExchange.instructedAmount.amount')),
            'original_currency' => data_get($row, 'currencyExchange.instructedAmount.currency'),
            'fee_amount' => $this->amount(data_get($row, 'charges.0.amount.amount')),
            'balance_after' => $this->amount(data_get($row, 'balanceAfterTransaction.balanceAmount.amount')),
            'booked_at' => $bookedAt, 'value_date' => $row['valueDate'] ?? null, 'merchant_name' => $merchant,
            'counterparty_name' => $counterparty, 'description' => $description ?: null,
            'bank_transaction_code' => is_array($row['bankTransactionCode'] ?? null) ? json_encode($row['bankTransactionCode']) : ($row['bankTransactionCode'] ?? null),
            'transaction_type' => $transactionType, 'category' => $classification['category'], 'trip_action' => $classification['trip_action'],
            'is_internal_transfer' => $classification['is_internal_transfer'], 'is_refund' => $classification['is_refund'],
            'is_fee' => $classification['is_fee'], 'is_cash_withdrawal' => $classification['is_cash_withdrawal'], 'provider_payload' => $row,
        ];
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
