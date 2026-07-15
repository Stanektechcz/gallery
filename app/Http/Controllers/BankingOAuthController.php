<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BankConnection;
use App\Services\Banking\BankingIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankingOAuthController extends Controller
{
    public function callback(Request $request, BankingIntegrationService $banking): RedirectResponse
    {
        $connection = BankConnection::where('uuid', $request->string('connection')->toString())->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
        try {
            $result = $banking->complete($connection, $request->string('state')->toString());
            AuditLog::record('bank.connection.complete', $connection, collect($result)->except('connection')->all());
            $tripId = data_get($connection->encrypted_metadata, 'return_trip_id');

            return redirect($tripId ? "/trips/{$tripId}/plan#bank-finance" : '/finances#connection')->with('success', 'Společný Revolut účet byl bezpečně připojen.');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect('/finances#connection')->with('error', 'Připojení Revolutu se nepodařilo dokončit. Zkontrolujte stav souhlasu a zkuste připojení znovu.');
        }
    }
}
