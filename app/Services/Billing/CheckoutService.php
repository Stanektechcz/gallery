<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\Payment;
use App\Models\SpaceSubscription;
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
        $full = $this->priceFor($plan->price_monthly, $plan->price_yearly, $period);
        abort_if($full <= 0, 422, 'Tenhle tarif je zdarma, platba není potřeba.');

        $credit = $this->unusedCredit($space);
        $amount = max(0, $full - $credit);

        // A credit larger than the new plan means a downgrade mid-period. Charging nothing
        // and letting the paid time run out is right; refunding the difference is not
        // something this system can do, and pretending otherwise would owe people money.
        abort_if($amount <= 0, 422,
            'Za současný tarif máte předplaceno víc, než stojí nový. Změnu proveďte až ke konci období.');

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

        return ['payment' => $payment->fresh(), 'redirect' => $created['redirect'], 'credit' => $credit];
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

    /**
     * What the space has already paid for and not yet used, in hundredths.
     *
     * Somebody who upgrades on the third day of a month has twenty-seven days of the old
     * plan in hand. Charging them the full new price takes that money twice, and the
     * complaint that follows is entirely fair — so the remainder comes off the new price.
     *
     * Measured against the subscription's own window rather than a calendar month, because
     * a yearly subscription changed in month eight has four months left, not three weeks.
     * A plan that was granted rather than bought has no price to credit, which the join
     * handles by simply finding nothing.
     */
    private function unusedCredit(GallerySpace $space): int
    {
        $current = SpaceSubscription::where('gallery_space_id', $space->id)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->with('plan')
            ->latest('started_at')
            ->first();

        if (! $current?->plan || ! $current->started_at || ! $current->ends_at) return 0;

        $total = $current->started_at->diffInSeconds($current->ends_at);
        $left = now()->diffInSeconds($current->ends_at, false);

        if ($total <= 0 || $left <= 0) return 0;

        // The window tells us which price was paid: anything close to a year was yearly.
        $paid = $total > 60 * 60 * 24 * 60
            ? $this->priceFor($current->plan->price_monthly, $current->plan->price_yearly, 'yearly')
            : $current->plan->price_monthly;

        return (int) floor($paid * min(1, $left / $total));
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
