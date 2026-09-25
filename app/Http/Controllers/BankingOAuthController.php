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
            $cil = $tripId ? "/trips/{$tripId}/plan#bank-finance" : '/finances#connection';

            // Připojení je hotové i bez prvního stažení — viz `complete()`.
            // Hlásí se to zvlášť, aby „nepodařilo se" nevedlo k novému pokusu.
            // Klíč `error`, ne `warning`: rozvržení ukazuje jen `success` a `error`.
            if ($result['first_sync_failed'] ?? false) {
                return redirect($cil)->with('error', 'Společný Revolut účet je připojený, ale první stažení pohybů se nepovedlo. '
                    .'Zkusí se znovu při automatické synchronizaci (každých šest hodin), nebo ji spusťte ručně u připojení banky.');
            }

            return redirect($cil)->with('success', 'Společný Revolut účet byl bezpečně připojen.');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect('/finances#connection')->with('error', 'Připojení Revolutu se nepodařilo dokončit. Zkontrolujte stav souhlasu a zkuste připojení znovu.');
        }
    }
}
