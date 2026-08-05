<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a purchase into a Comgate payment, and a confirmed payment into an entitlement.
 *
 * Only `markPaid()` grants anything, and it is idempotent: Comgate may deliver the same
 * notification more than once, and a payer may also return to the browser URL first.
 */
class CheckoutService
{
    public function __construct(
        private readonly ComgateGateway $gateway,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @return array{payment:Payment, redirect:string}
     */
    public function startPlanPurchase(GallerySpace $space, BillingPlan $plan, User $buyer, string $period): array
    {
        $amount = $this->priceFor($plan->price_monthly, $plan->price_yearly, $period);
        abort_if($amount <= 0, 422, 'Tenhle tarif je zdarma, platba není potřeba.');

        $payment = Payment::create([
            'gallery_space_id' => $space->id,
            'created_by' => $buyer->id,
            'purchase_type' => 'plan',
            'billing_plan_id' => $plan->id,
            'billing_period' => $period,
            'amount' => $amount,
            'currency' => $plan->currency,
        ]);

        $created = $this->gateway->createPayment($payment, 'Tarif ' . $plan->code, $buyer->email);

        return ['payment' => $payment->fresh(), 'redirect' => $created['redirect']];
    }

    /**
     * @return array{payment:Payment, redirect:string}
     */
    public function startModulePurchase(GallerySpace $space, BillingModule $module, User $buyer, string $period): array
    {
        $amount = $this->priceFor($module->price_monthly, $module->price_monthly * 10, $period);
        abort_if($amount <= 0, 422, 'Tenhle modul je zdarma.');

        $payment = Payment::create([
            'gallery_space_id' => $space->id,
            'created_by' => $buyer->id,
            'purchase_type' => 'module',
            'billing_module_id' => $module->id,
            'billing_period' => $period,
            'amount' => $amount,
            'currency' => $module->currency,
        ]);

        $created = $this->gateway->createPayment($payment, 'Modul ' . $module->code, $buyer->email);

        return ['payment' => $payment->fresh(), 'redirect' => $created['redirect']];
    }

    /** A yearly purchase is ten months' worth — two months free. */
    private function priceFor(int $monthly, int $yearly, string $period): int
    {
        return $period === 'yearly'
            ? ($yearly > 0 ? $yearly : $monthly * 10)
            : $monthly;
    }

    /**
     * Confirms against the gateway and grants the entitlement. Safe to call repeatedly.
     */
    public function settle(Payment $payment): Payment
    {
        if ($payment->isPaid()) return $payment;

        // Never trust the caller: ask Comgate what really happened.
        $status = $payment->transaction_id ? $this->gateway->status($payment->transaction_id) : [];
        $state = strtoupper((string) ($status['status'] ?? ''));

        if ($state !== 'PAID') {
            $payment->update([
                'status' => match ($state) {
                    'CANCELLED' => 'cancelled',
                    'AUTHORIZED', 'PENDING' => 'pending',
                    default => $payment->status,
                },
                'gateway_payload' => $status ?: $payment->gateway_payload,
            ]);

            return $payment->fresh();
        }

        return $this->markPaid($payment, $status);
    }

    /** @param array<string,string> $status */
    public function markPaid(Payment $payment, array $status = []): Payment
    {
        if ($payment->isPaid()) return $payment;

        DB::transaction(function () use ($payment, $status): void {
            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'method' => $status['method'] ?? $payment->method,
                'payer_email' => $status['email'] ?? $payment->payer_email,
                'gateway_payload' => $status ?: $payment->gateway_payload,
            ]);

            $space = $payment->space;
            if (! $space) return;

            $until = $payment->billing_period === 'yearly' ? now()->addYear() : now()->addMonth();
            $buyer = $payment->created_by ? User::find($payment->created_by) : null;

            if ($payment->purchase_type === 'plan' && $payment->plan) {
                $subscription = $this->entitlements->assignPlan($space, $payment->plan, $buyer, $payment->billing_period, $until);
                $subscription->update(['last_payment_id' => $payment->id]);
            }

            if ($payment->purchase_type === 'module' && $payment->module) {
                $this->entitlements->enableModule($space, $payment->module, $buyer, $payment->billing_period, $until);
            }
        });

        AuditLog::record('billing.payment.paid', $payment, [
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ]);

        Log::info('Payment settled', ['reference' => $payment->reference, 'space' => $payment->gallery_space_id]);

        return $payment->fresh();
    }
}
