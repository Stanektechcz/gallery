<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\Payment;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\ComgateGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly ComgateGateway $gateway,
    ) {}

    /** Starts a purchase and hands the caller the gateway URL to redirect to. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:plan,module',
            'code' => 'required|string|max:60',
            'period' => 'nullable|in:monthly,yearly',
        ]);

        $user = $request->user();
        $space = $this->space($request);
        abort_unless(in_array($user->role, ['owner', 'admin'], true), 403, 'Předplatné může měnit jen správce prostoru.');

        $period = $data['period'] ?? 'monthly';

        $result = $data['type'] === 'plan'
            ? $this->checkout->startPlanPurchase($space, BillingPlan::where('code', $data['code'])->firstOrFail(), $user, $period)
            : $this->checkout->startModulePurchase($space, BillingModule::where('code', $data['code'])->firstOrFail(), $user, $period);

        return response()->json([
            'redirect' => $result['redirect'],
            'reference' => $result['payment']->reference,
        ]);
    }

    /**
     * Server-to-server notification. This is the only thing that grants an entitlement.
     * Comgate expects a bare "code=0" acknowledgement and will retry without it.
     */
    public function notify(Request $request): Response
    {
        $reference = (string) $request->input('refId');
        $transactionId = (string) $request->input('transId');

        $payment = Payment::where('reference', $reference)->first();
        if (! $payment) {
            Log::warning('Comgate notification for an unknown reference', ['refId' => $reference]);
            // Acknowledged anyway: retrying will not make the payment appear.
            return response('code=0&message=OK', 200)->header('Content-Type', 'text/plain');
        }

        if ($transactionId && ! $payment->transaction_id) {
            $payment->update(['transaction_id' => $transactionId]);
        }

        // settle() re-checks with the gateway; the posted fields are never trusted alone.
        $this->checkout->settle($payment->fresh());

        return response('code=0&message=OK', 200)->header('Content-Type', 'text/plain');
    }

    /** Where the payer's browser lands. Cosmetic only — the notification is the truth. */
    public function return(Request $request): RedirectResponse
    {
        $status = $request->query('status', 'pending');

        $message = match ($status) {
            'paid' => ['success', 'Platba proběhla. Předplatné se aktivuje během několika sekund.'],
            'cancelled' => ['warning', 'Platba byla zrušena, nic jsme neúčtovali.'],
            default => ['warning', 'Platba zatím nebyla potvrzena. Jakmile ji banka potvrdí, tarif se aktivuje sám.'],
        };

        return redirect('/settings/predplatne')->with($message[0], $message[1]);
    }

    /** Lets the subscription screen poll after the payer returns. */
    public function status(Request $request, string $reference): JsonResponse
    {
        $space = $this->space($request);
        $payment = Payment::where('reference', $reference)->where('gallery_space_id', $space->id)->firstOrFail();

        if (! $payment->isPaid() && $payment->transaction_id) {
            $payment = $this->checkout->settle($payment);
        }

        return response()->json([
            'reference' => $payment->reference,
            'status' => $payment->status,
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
        ]);
    }

    /** Whether the gateway is set up at all, so the UI can say so plainly. */
    public function gatewayState(): JsonResponse
    {
        return response()->json([
            'configured' => $this->gateway->configured(),
            'test_mode' => (bool) config('comgate.test'),
        ]);
    }

    private function space(Request $request): GallerySpace
    {
        return GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id))
            ->orderByDesc('is_default')->firstOrFail();
    }
}
