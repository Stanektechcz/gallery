<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

/**
 * Letting people see and keep the documents issued to them.
 *
 * Scoped to the space, never to a global list. An invoice carries a name, an address and
 * what somebody bought — the sort of thing that must never be one guessed identifier away
 * from the wrong customer.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('invoices')) return response()->json(['invoices' => []]);

        $space = $this->space($request);

        return response()->json([
            'invoices' => Invoice::where('gallery_space_id', $space->id)
                ->orderByDesc('issued_at')->limit(100)->get()
                ->map(fn (Invoice $invoice) => [
                    'uuid' => $invoice->uuid,
                    'number' => $invoice->number,
                    'description' => $invoice->description,
                    'amount' => $invoice->amount,
                    'currency' => $invoice->currency,
                    'issued_at' => $invoice->issued_at?->toIso8601String(),
                    'paid_at' => $invoice->paid_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    /**
     * The document itself, as a page built to be printed.
     *
     * Not a PDF. Generating one needs a library this project would then carry forever, and
     * every browser prints to PDF already — which produces a file the person chose the
     * name and location of, rather than one we guessed at.
     */
    public function show(Request $request, string $uuid): Response
    {
        abort_unless(Schema::hasTable('invoices'), 404);

        $space = $this->space($request);

        // Matched on space as well as uuid. A uuid is unguessable, but "unguessable" is a
        // property of the identifier rather than a permission check.
        $invoice = Invoice::where('uuid', $uuid)->where('gallery_space_id', $space->id)->firstOrFail();

        return response()
            ->view('invoice', ['invoice' => $invoice, 'space' => $space])
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function space(Request $request): GallerySpace
    {
        $space = $request->user()->gallerySpaces()->orderByDesc('is_default')->first();
        abort_unless($space, 404, 'Prostor nebyl nalezen.');

        return $space;
    }
}
