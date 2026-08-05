<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Comgate REST integration.
 *
 * Comgate speaks form-encoded requests and answers `application/x-www-form-urlencoded`
 * bodies, not JSON, so responses are parsed with parse_str. Amounts travel in minor
 * units (haléře), which is also how they are stored, so no conversion happens here.
 *
 * Payment truth comes from the server-to-server notification, never from the URL the
 * payer is returned to — a browser redirect can be forged or simply never happen.
 */
class ComgateGateway
{
    public function configured(): bool
    {
        return filled(config('comgate.merchant')) && filled(config('comgate.secret'));
    }

    private function assertConfigured(): void
    {
        abort_unless(
            $this->configured(),
            503,
            'Platební brána zatím není nastavená. Doplňte COMGATE_MERCHANT a COMGATE_SECRET.'
        );
    }

    /**
     * Registers the payment and returns the URL to send the payer to.
     *
     * @return array{redirect:string, transaction_id:string}
     */
    public function createPayment(Payment $payment, string $label, ?string $email): array
    {
        $this->assertConfigured();

        $response = Http::asForm()
            ->timeout(20)
            ->post(rtrim(config('comgate.base_url'), '/') . '/create', [
                'merchant'  => config('comgate.merchant'),
                'secret'    => config('comgate.secret'),
                'test'      => config('comgate.test') ? 'true' : 'false',
                'price'     => $payment->amount,          // minor units
                'curr'      => $payment->currency,
                'label'     => mb_substr($label, 0, 16),  // Comgate keeps this short
                'refId'     => $payment->reference,
                'method'    => config('comgate.method'),
                'email'     => $email ?? '',
                'country'   => config('comgate.country'),
                'lang'      => 'cs',
                'prepareOnly' => 'true',
                'url_paid'   => route('billing.comgate.return', ['status' => 'paid']),
                'url_cancelled' => route('billing.comgate.return', ['status' => 'cancelled']),
                'url_pending'   => route('billing.comgate.return', ['status' => 'pending']),
                'url_notify'    => route('billing.comgate.notify'),
            ]);

        $body = $this->parse($response->body());

        if (! $response->successful() || ($body['code'] ?? null) !== '0') {
            Log::warning('Comgate refused a payment', ['reference' => $payment->reference, 'body' => $body]);
            throw new RuntimeException($body['message'] ?? 'Platbu se nepodařilo založit.');
        }

        $payment->update([
            'transaction_id'  => $body['transId'] ?? null,
            'gateway_payload' => $body,
        ]);

        return [
            'redirect' => $body['redirect'] ?? '',
            'transaction_id' => (string) ($body['transId'] ?? ''),
        ];
    }

    /**
     * Asks the gateway for the authoritative state of a transaction. Used to confirm a
     * notification rather than trusting its posted fields.
     *
     * @return array<string,string>
     */
    public function status(string $transactionId): array
    {
        $this->assertConfigured();

        $response = Http::asForm()
            ->timeout(20)
            ->post(rtrim(config('comgate.base_url'), '/') . '/status', [
                'merchant' => config('comgate.merchant'),
                'secret'   => config('comgate.secret'),
                'transId'  => $transactionId,
            ]);

        return $this->parse($response->body());
    }

    /** Comgate answers url-encoded key/value pairs. */
    private function parse(string $body): array
    {
        $parsed = [];
        parse_str($body, $parsed);

        return array_map(static fn ($value) => is_string($value) ? $value : $value, $parsed);
    }
}
