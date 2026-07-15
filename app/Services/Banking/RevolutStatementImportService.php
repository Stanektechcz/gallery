<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankBalanceSnapshot;
use App\Models\BankConnection;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RevolutStatementImportService
{
    private const ALIASES = [
        'type' => ['type', 'typ', 'transaction type', 'typ transakce'],
        'product' => ['product', 'produkt', 'account', 'account name', 'ucet', 'název účtu', 'wallet'],
        'started_at' => ['started date', 'started', 'datum zahajeni', 'zacatek', 'created date', 'transaction date', 'datum transakce', 'transaction started'],
        'booked_at' => ['completed date', 'completed', 'datum dokonceni', 'dokonceno', 'booking date', 'booked date', 'posting date', 'date', 'datum', 'transaction completed'],
        'description' => ['description', 'transaction description', 'popis', 'popis transakce', 'transaction', 'details', 'detail', 'note', 'poznámka', 'poznamka'],
        'merchant' => ['merchant', 'merchant name', 'obchodnik', 'obchodník', 'payee'],
        'counterparty' => ['counterparty', 'protistrana', 'recipient', 'prijemce', 'příjemce', 'beneficiary'],
        'reference' => ['reference', 'payment reference', 'reference platby', 'variable symbol', 'variabilni symbol'],
        'amount' => ['amount', 'castka', 'částka', 'transaction amount', 'gross amount', 'amount payment currency', 'original amount original currency', 'orig amount orig currency', 'value'],
        'debit' => ['paid out', 'money out', 'penize ven', 'peníze ven', 'debit', 'debet', 'withdrawal', 'odchozi', 'odchozí', 'odchozi platba', 'odchozí platba', 'vydaj', 'výdaj', 'vydej', 'výdej', 'platba'],
        'credit' => ['paid in', 'money in', 'penize dovnitr', 'peníze dovnitř', 'credit', 'kredit', 'deposit', 'prichozi', 'příchozí', 'prichozi platba', 'příchozí platba', 'prijem', 'příjem'],
        'direction' => ['direction', 'credit debit', 'credit debit indicator', 'smer', 'směr'],
        'fee' => ['fee', 'fees', 'poplatek', 'poplatky'],
        'currency' => ['currency', 'mena', 'měna', 'currency code', 'payment currency', 'original currency', 'orig currency'],
        'state' => ['state', 'stav', 'status'],
        'balance' => ['balance', 'running balance', 'zustatek', 'zůstatek'],
        'transaction_id' => ['transaction id', 'id transakce', 'transactionid', 'reference id'],
    ];

    public function __construct(private readonly BankTransactionClassifier $classifier, private readonly TripBankReconciliationService $reconciliation) {}

    public function import(GallerySpace $space, User $user, UploadedFile $file): array
    {
        $sha = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['csv', 'xls', 'xlsx'], true), 422, 'Nahrajte výpis Revolut ve formátu CSV, XLS nebo XLSX.');
        $existing = BankImport::where('gallery_space_id', $space->id)->where('file_sha256', $sha)->first();
        if ($existing?->status === 'completed') {
            $hasImportedTransactions = BankTransaction::where('bank_import_id', $existing->id)->exists();
            $wasFullyDuplicate = (int) $existing->rows_imported === 0 && (int) $existing->rows_duplicate > 0;
            if ($hasImportedTransactions || $wasFullyDuplicate) {
                return ['import' => $this->payload($existing), 'duplicate_file' => true, 'retried_import' => false];
            }
        }
        abort_if($existing?->status === 'processing' && $existing->updated_at?->gt(now()->subMinutes(15)), 409, 'Tento výpis se právě zpracovává. Počkejte prosím na dokončení.');

        $connection = BankConnection::firstOrCreate(['gallery_space_id' => $space->id, 'provider' => 'revolut_statement', 'status' => 'active'],
            ['connected_by' => $user->id, 'institution_name' => 'Revolut · import výpisu', 'sync_enabled' => false]);
        $retriedImport = (bool) $existing;
        if ($existing) {
            $existing->update([
                'bank_connection_id' => $connection->id, 'bank_account_id' => null, 'imported_by' => $user->id,
                'original_filename' => $file->getClientOriginalName(), 'format' => $extension, 'status' => 'processing',
                'rows_total' => 0, 'rows_imported' => 0, 'rows_duplicate' => 0, 'rows_failed' => 0,
                'period_from' => null, 'period_to' => null, 'error_summary' => null,
            ]);
            $import = $existing->fresh();
        } else {
            $import = BankImport::create(['gallery_space_id' => $space->id, 'bank_connection_id' => $connection->id,
                'imported_by' => $user->id, 'original_filename' => $file->getClientOriginalName(), 'file_sha256' => $sha,
                'format' => $extension, 'status' => 'processing']);
        }
        try {
            $rows = $extension === 'csv' ? $this->csvRows($file->getRealPath()) : $this->spreadsheetRows($file->getRealPath());
            abort_if(count($rows) < 2, 422, 'Výpis neobsahuje žádné transakce.');
            $defaultCurrency = $this->detectedCurrency($rows);
            [$rows, $columns, $headerRow] = $this->table($rows);
            $counts = ['total' => count($rows), 'imported' => 0, 'duplicate' => 0, 'failed' => 0];
            $dates = collect();
            $firstAccount = null;
            $fallbackOccurrences = [];
            $firstFailure = null;
            foreach ($rows as $index => $row) {
                try {
                    if ($this->emptyRow($row)) {
                        continue;
                    }
                    $mapped = $this->mapped($row, $columns);
                    $date = $this->date($mapped['booked_at'] ?? $mapped['started_at'] ?? null);
                    $amount = $this->amount($mapped);
                    abort_unless($date && $amount !== null, 422, 'Řádek nemá platné datum nebo částku.');
                    $currency = $this->currency($mapped, $defaultCurrency);
                    $product = trim((string) ($mapped['product'] ?? 'Společný účet')) ?: 'Společný účet';
                    $accountKey = "statement:{$product}:{$currency}";
                    $account = BankAccount::firstOrCreate([
                        'bank_connection_id' => $connection->id, 'external_id_hash' => hash('sha256', $accountKey),
                    ], ['encrypted_external_id' => $accountKey, 'name' => $product, 'currency' => $currency,
                        'account_type' => 'joint_current', 'is_joint' => true, 'is_enabled' => true]);
                    $firstAccount ??= $account;
                    $description = $this->description($mapped);
                    $canonical = implode('|', [$date->toIso8601String(), $amount, $currency, $description, $mapped['type'] ?? '']);
                    $occurrence = ($fallbackOccurrences[$canonical] ?? 0) + 1;
                    $fallbackOccurrences[$canonical] = $occurrence;
                    // The occurrence counter keeps genuinely repeated same-second payments distinct while
                    // making the same row stable across overlapping statement exports.
                    $external = trim((string) ($mapped['transaction_id'] ?? '')) ?: "{$canonical}|{$occurrence}";
                    $externalHash = hash('sha256', $external);
                    if (BankTransaction::where('bank_account_id', $account->id)->where('external_id_hash', $externalHash)->exists()) {
                        $counts['duplicate']++;

                        continue;
                    }
                    $classification = $this->classifier->classify($space, ['amount' => $amount, 'description' => $description, 'merchant_name' => $description,
                        'transaction_type' => $mapped['type'] ?? null]);
                    $balance = $this->number($mapped['balance'] ?? null);
                    $fee = $this->number($mapped['fee'] ?? null);
                    BankTransaction::create(['bank_account_id' => $account->id, 'bank_import_id' => $import->id,
                        'external_id_hash' => $externalHash, 'encrypted_external_id' => $mapped['transaction_id'] ?? null,
                        'status' => $this->status($mapped['state'] ?? null), 'direction' => $amount < 0 ? 'debit' : 'credit',
                        'amount' => $amount, 'currency' => $currency, 'fee_amount' => $fee, 'balance_after' => $balance,
                        'booked_at' => $date, 'value_date' => $date->toDateString(), 'merchant_name' => $description ?: null,
                        'description' => $description ?: null, 'transaction_type' => $mapped['type'] ?? null,
                        'category' => $classification['category'], 'trip_action' => $classification['trip_action'], 'is_internal_transfer' => $classification['is_internal_transfer'],
                        'is_refund' => $classification['is_refund'], 'is_fee' => $classification['is_fee'],
                        'is_cash_withdrawal' => $classification['is_cash_withdrawal'], 'provider_payload' => ['source' => 'revolut_statement', 'row' => $headerRow + $index + 2]]);
                    if ($balance !== null) {
                        BankBalanceSnapshot::firstOrCreate(['bank_account_id' => $account->id,
                            'snapshot_key' => hash('sha256', "import:{$sha}:{$index}")], ['booked_balance' => $balance,
                                'available_balance' => $balance, 'currency' => $currency, 'captured_at' => $date, 'source' => 'statement']);
                    }
                    $accountUpdates = ['history_available_from' => ! $account->history_available_from || $date->lt($account->history_available_from) ? $date->toDateString() : $account->history_available_from];
                    if ($balance !== null && (! $account->balance_updated_at || $date->gte($account->balance_updated_at))) {
                        $accountUpdates += ['current_balance' => $balance, 'available_balance' => $balance, 'balance_updated_at' => $date];
                    }
                    $account->update($accountUpdates);
                    $dates->push($date);
                    $counts['imported']++;
                } catch (\Throwable $rowException) {
                    $counts['failed']++;
                    $firstFailure ??= $rowException->getMessage();
                }
            }
            abort_if(! $counts['imported'] && ! $counts['duplicate'] && $counts['failed'], 422, 'Žádnou transakci se nepodařilo načíst. '.($firstFailure ?: 'Zkontrolujte datum a částku ve výpisu.'));
            $import->update(['bank_account_id' => $firstAccount?->id, 'status' => $counts['failed'] && ! $counts['imported'] ? 'failed' : 'completed',
                'rows_total' => $counts['total'], 'rows_imported' => $counts['imported'], 'rows_duplicate' => $counts['duplicate'],
                'rows_failed' => $counts['failed'], 'period_from' => $dates->min()?->toDateString(), 'period_to' => $dates->max()?->toDateString(),
                'error_summary' => $counts['failed'] ? "{$counts['failed']} řádků nebylo možné načíst. První chyba: ".mb_substr((string) $firstFailure, 0, 700) : null]);
            $links = $this->reconciliation->reconcileSpace($space);

            return ['import' => $this->payload($import->fresh()), 'duplicate_file' => false, 'retried_import' => $retriedImport, 'trip_links_created' => $links];
        } catch (\Throwable $exception) {
            $import->update(['status' => 'failed', 'error_summary' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }

    private function csvRows(string $path): array
    {
        $content = file_get_contents($path);
        abort_if($content === false, 422, 'Výpis nelze přečíst.');
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $sampleLines = array_slice(preg_split('/\R/u', $content) ?: [], 0, 30);
        $delimiter = $this->delimiter($sampleLines);
        $stream = fopen('php://temp', 'r+');
        abort_unless($stream, 500, 'Výpis nelze dočasně zpracovat.');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (! $this->emptyRow($row)) {
                $rows[] = array_map(fn ($value) => $this->repairText($value), $row);
            }
        }
        fclose($stream);

        return $rows;
    }

    private function spreadsheetRows(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $bestRows = [];
            $bestScore = -1;
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                // Keep raw Excel serial values; this avoids ambiguous 09/10 dates caused by localized cell formatting.
                $rows = collect($sheet->toArray(null, true, false, false))->reject(fn (array $row) => $this->emptyRow($row))->values()->all();
                $rows = $this->expandDelimitedSpreadsheetRows($rows);
                $score = collect(array_slice($rows, 0, 50))->max(fn (array $row) => count($this->columns($row))) ?? 0;
                if ($score > $bestScore) {
                    $bestRows = $rows;
                    $bestScore = $score;
                }
            }
            $spreadsheet->disconnectWorksheets();

            return $bestRows;
        } catch (\Throwable $exception) {
            abort(422, 'Tabulku XLS/XLSX nelze přečíst. Ověřte, že není chráněná heslem ani poškozená. '.$exception->getMessage());
        }
    }

    private function expandDelimitedSpreadsheetRows(array $rows): array
    {
        $repaired = collect($rows)->map(fn (array $row) => array_map(fn ($value) => $this->repairText($value), $row))->values();
        $singleCellLines = $repaired->map(function (array $row) {
            $filled = collect($row)->filter(fn ($value) => trim((string) $value) !== '')->values();

            return $filled->count() === 1 ? (string) $filled->first() : null;
        })->filter(fn ($value) => $value !== null)->values();

        if ($singleCellLines->count() < max(2, (int) ceil($repaired->count() * 0.6))) {
            return $repaired->all();
        }
        $delimiter = $this->delimiter($singleCellLines->take(30)->all());
        if ($singleCellLines->sum(fn (string $line) => substr_count($line, $delimiter)) === 0) {
            return $repaired->all();
        }

        return $singleCellLines->map(fn (string $line) => str_getcsv($line, $delimiter, '"', ''))->all();
    }

    private function delimiter(array $lines): string
    {
        $delimiters = collect([',', ';', "\t"])->mapWithKeys(fn (string $candidate) => [
            $candidate => collect($lines)->sum(fn ($line) => substr_count((string) $line, $candidate)),
        ])->all();
        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }

    private function repairText(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/[ĂÄĹ]/u', $value)) {
            return $value;
        }
        $repaired = @iconv('UTF-8', 'Windows-1250//IGNORE', $value);
        if (! is_string($repaired) || ! mb_check_encoding($repaired, 'UTF-8')) {
            return $value;
        }
        $before = preg_match_all('/[ĂÄĹ]/u', $value);
        $after = preg_match_all('/[ĂÄĹ]/u', $repaired);

        return $after < $before ? $repaired : $value;
    }

    private function table(array $rows): array
    {
        $best = null;
        foreach (array_slice($rows, 0, 50, true) as $index => $candidate) {
            $columns = $this->columns($candidate);
            $hasDate = isset($columns['booked_at']) || isset($columns['started_at']);
            $hasAmount = isset($columns['amount']) || isset($columns['debit']) || isset($columns['credit']);
            $score = count($columns) + ($hasDate ? 5 : 0) + ($hasAmount ? 5 : 0);
            if ($hasDate && $hasAmount && ($best === null || $score > $best['score'])) {
                $best = compact('index', 'columns', 'score');
            }
        }
        if (! $best) {
            $seen = collect(array_slice($rows, 0, 10))->flatten()->map(fn ($value) => trim((string) $value))->filter()->take(12)->implode(', ');
            abort(422, 'Ve výpisu nebylo rozpoznáno záhlaví s datem a částkou. Podporujeme Amount/Částka i dvojici Paid out/Paid in, Výdaj/Příjem nebo Debit/Credit.'.($seen ? " Nalezeno: {$seen}." : ''));
        }

        return [array_values(array_slice($rows, $best['index'] + 1)), $best['columns'], (int) $best['index']];
    }

    private function columns(array $headers): array
    {
        $result = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->classifier->normalize($header);
            foreach (self::ALIASES as $key => $aliases) {
                $normalizedAliases = array_map(fn ($value) => $this->classifier->normalize($value), $aliases);
                $matches = in_array($normalized, $normalizedAliases, true) || collect($normalizedAliases)->contains(
                    fn (string $alias) => mb_strlen($alias) >= 8 && (str_starts_with($normalized, $alias.' ') || str_ends_with($normalized, ' '.$alias))
                );
                if ($matches) {
                    $result[$key] ??= $index;
                    break;
                }
            }
        }

        return $result;
    }

    private function mapped(array $row, array $columns): array
    {
        return collect($columns)->mapWithKeys(fn ($index, $key) => [$key => $row[$index] ?? null])->all();
    }

    private function emptyRow(array $row): bool
    {
        return collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty();
    }

    private function status(?string $value): string
    {
        return str_contains($this->classifier->normalize($value), 'pending') || str_contains($this->classifier->normalize($value), 'ceka') ? 'pending' : 'booked';
    }

    private function amount(array $mapped): ?float
    {
        $amount = $this->number($mapped['amount'] ?? null);
        if ($amount !== null) {
            $direction = $this->classifier->normalize($mapped['direction'] ?? '');
            if (str_contains($direction, 'debit') || str_contains($direction, 'debet') || str_contains($direction, 'out')) {
                return -abs($amount);
            }
            if (str_contains($direction, 'credit') || str_contains($direction, 'kredit') || str_contains($direction, 'in')) {
                return abs($amount);
            }

            return $amount;
        }
        $credit = $this->number($mapped['credit'] ?? null);
        $debit = $this->number($mapped['debit'] ?? null);
        if ($credit !== null && abs($credit) > 0) {
            return abs($credit);
        }
        if ($debit !== null) {
            return -abs($debit);
        }

        return $credit;
    }

    private function currency(array $mapped, ?string $defaultCurrency = null): string
    {
        $haystack = collect(['currency', 'amount', 'debit', 'credit', 'balance', 'product'])
            ->map(fn (string $key) => (string) ($mapped[$key] ?? ''))->implode(' ');
        $currency = strtoupper(trim((string) ($mapped['currency'] ?? '')));
        if (preg_match('/\b[A-Z]{3}\b/', $currency, $match) || preg_match('/\b(CZK|EUR|USD|GBP|PLN|HUF|CHF|SEK|NOK|DKK|RON|BGN|TRY)\b/i', $haystack, $match)) {
            return strtoupper($match[0]);
        }
        if (str_contains($haystack, '€')) {
            return 'EUR';
        }
        if (str_contains($haystack, '£')) {
            return 'GBP';
        }
        if (str_contains($haystack, '$')) {
            return 'USD';
        }

        return $defaultCurrency ?: 'CZK';
    }

    private function detectedCurrency(array $rows): ?string
    {
        $text = collect(array_slice($rows, 0, 50))->flatten()->map(fn ($value) => (string) $value)->implode(' ');
        if (preg_match('/\b(CZK|EUR|USD|GBP|PLN|HUF|CHF|SEK|NOK|DKK|RON|BGN|TRY)\b/i', $text, $match)) {
            return strtoupper($match[1]);
        }
        foreach (['€' => 'EUR', '£' => 'GBP', '$' => 'USD'] as $symbol => $currency) {
            if (str_contains($text, $symbol)) {
                return $currency;
            }
        }

        return null;
    }

    private function description(array $mapped): string
    {
        foreach (['description', 'merchant', 'counterparty', 'reference', 'type'] as $key) {
            $value = trim((string) ($mapped[$key] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 1000);
            }
        }

        return 'Transakce Revolut';
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $original = trim((string) $value);
        $negative = str_starts_with($original, '(') && str_ends_with($original, ')');
        $text = preg_replace('/[^0-9,.\-]/u', '', str_replace(["\u{00A0}", "\u{202F}", '−', "'"], ['', '', '-', ''], $original));
        if (str_contains($text, ',') && str_contains($text, '.')) {
            if (strrpos($text, ',') > strrpos($text, '.')) {
                $text = str_replace(',', '.', str_replace('.', '', $text));
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }

        if (! is_numeric($text)) {
            return null;
        }
        $number = (float) $text;

        return $negative ? -abs($number) : $number;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        } if (is_numeric($value) && (float) $value > 20000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        } foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'm/d/Y H:i:s', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, trim((string) $value));
                if ($date !== false) {
                    return $date;
                }
            } catch (\Throwable) {
            }
        } try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function payload(BankImport $import): array
    {
        return ['uuid' => $import->uuid, 'filename' => $import->original_filename, 'status' => $import->status,
            'rows_total' => $import->rows_total, 'rows_imported' => $import->rows_imported, 'rows_duplicate' => $import->rows_duplicate,
            'rows_failed' => $import->rows_failed, 'period_from' => $import->period_from?->toDateString(), 'period_to' => $import->period_to?->toDateString(), 'error' => $import->error_summary];
    }
}
