<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing the document that goes with a payment.
 *
 * The number is the part that has to be right. It runs YYYY0001 upward within a year and
 * must have no gaps and no repeats, because both are things an accountant will ask about
 * years later when nobody remembers what happened.
 *
 * Allocated inside a transaction with the last row locked, so two payments settling in the
 * same instant cannot read the same maximum and both claim it. The unique index is the
 * second line of defence: if the lock is ever wrong, the database refuses rather than
 * quietly issuing two of the same.
 */
class InvoiceService
{
    /** One per payment, ever. Settling a payment twice must not produce two documents. */
    public function forPayment(Payment $payment): Invoice
    {
        $existing = Invoice::where('payment_id', $payment->id)->first();
        if ($existing) return $existing;

        $buyer = $payment->buyer;

        return DB::transaction(function () use ($payment, $buyer) {
            return Invoice::create([
                'uuid' => (string) Str::uuid(),
                'number' => $this->nextNumber(),
                'payment_id' => $payment->id,
                'gallery_space_id' => $payment->gallery_space_id,
                'issued_to' => $payment->created_by,
                'customer_name' => $buyer?->name,
                'customer_email' => $buyer?->email ?? $payment->payer_email,
                'description' => $this->describe($payment),
                'amount' => $payment->amount,
                'currency' => $payment->currency ?? 'CZK',
                // Not VAT registered until told otherwise. Guessing a rate would put a
                // wrong number on a tax document, which is worse than putting none.
                'vat_rate' => (int) config('billing.vat_rate', 0),
                'issued_at' => now(),
                'paid_at' => $payment->paid_at ?? now(),
            ]);
        });
    }

    /**
     * The next number in this year's series.
     *
     * lockForUpdate, not max()+1 on its own: two settlements a millisecond apart would
     * otherwise both read the same last number.
     */
    private function nextNumber(): string
    {
        $year = now()->year;
        $prefix = (string) $year;

        $last = Invoice::where('number', 'like', $prefix . '%')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $sequence = $last ? ((int) substr($last, 4)) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** What the customer bought, in words they will recognise on a bank statement. */
    private function describe(Payment $payment): string
    {
        $period = $payment->billing_period === 'yearly' ? 'roční' : 'měsíční';

        if ($payment->purchase_type === 'plan' && $payment->plan) {
            return 'Tarif ' . $payment->plan->name . ' — ' . $period . ' předplatné';
        }

        if ($payment->purchase_type === 'module' && $payment->module) {
            return 'Modul ' . $payment->module->name . ' — ' . $period . ' předplatné';
        }

        return 'Předplatné MAKI Gallery';
    }
}
