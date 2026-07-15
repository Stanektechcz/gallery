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
use ZipArchive;

class RevolutStatementImportService
{
    private const ALIASES = [
        'type' => ['type', 'typ'], 'product' => ['product', 'produkt', 'account', 'ucet'],
        'started_at' => ['started date', 'started', 'datum zahajeni', 'zacatek', 'created date'],
        'booked_at' => ['completed date', 'completed', 'datum dokonceni', 'dokonceno', 'booking date', 'date', 'datum'],
        'description' => ['description', 'popis', 'merchant', 'obchodnik', 'counterparty'],
        'amount' => ['amount', 'castka', 'částka'], 'fee' => ['fee', 'poplatek'],
        'currency' => ['currency', 'mena', 'měna'], 'state' => ['state', 'stav', 'status'],
        'balance' => ['balance', 'zustatek', 'zůstatek'], 'transaction_id' => ['transaction id', 'id transakce', 'transactionid'],
    ];

    public function __construct(private readonly BankTransactionClassifier $classifier, private readonly TripBankReconciliationService $reconciliation) {}

    public function import(GallerySpace $space, User $user, UploadedFile $file): array
    {
        $sha = hash_file('sha256', $file->getRealPath());
        if ($existing = BankImport::where('gallery_space_id', $space->id)->where('file_sha256', $sha)->first()) {
            return ['import' => $this->payload($existing), 'duplicate_file' => true];
        }
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['csv', 'xlsx'], true), 422, 'Nahrajte výpis Revolut ve formátu CSV nebo XLSX.');
        $connection = BankConnection::firstOrCreate(['gallery_space_id' => $space->id, 'provider' => 'revolut_statement', 'status' => 'active'],
            ['connected_by' => $user->id, 'institution_name' => 'Revolut · import výpisu', 'sync_enabled' => false]);
        $import = BankImport::create(['gallery_space_id' => $space->id, 'bank_connection_id' => $connection->id,
            'imported_by' => $user->id, 'original_filename' => $file->getClientOriginalName(), 'file_sha256' => $sha,
            'format' => $extension, 'status' => 'processing']);
        try {
            $rows = $extension === 'xlsx' ? $this->xlsxRows($file->getRealPath()) : $this->csvRows($file->getRealPath());
            abort_if(count($rows) < 2, 422, 'Výpis neobsahuje žádné transakce.');
            $headers = array_shift($rows);
            $columns = $this->columns($headers);
            foreach (['amount', 'currency', 'description'] as $required) {
                abort_unless(isset($columns[$required]), 422, "Ve výpisu chybí sloupec {$required}.");
            }
            abort_unless(isset($columns['booked_at']) || isset($columns['started_at']), 422, 'Ve výpisu chybí datum transakce.');
            $counts = ['total' => count($rows), 'imported' => 0, 'duplicate' => 0, 'failed' => 0];
            $dates = collect();
            $firstAccount = null;
            $fallbackOccurrences = [];
            foreach ($rows as $index => $row) {
                try {
                    if ($this->emptyRow($row)) {
                        continue;
                    }
                    $mapped = $this->mapped($row, $columns);
                    $date = $this->date($mapped['booked_at'] ?? $mapped['started_at'] ?? null);
                    $amount = $this->number($mapped['amount'] ?? null);
                    abort_unless($date && $amount !== null, 422, 'Řádek nemá platné datum nebo částku.');
                    $currency = strtoupper(trim((string) ($mapped['currency'] ?? 'CZK')));
                    $product = trim((string) ($mapped['product'] ?? 'Společný účet')) ?: 'Společný účet';
                    $accountKey = "statement:{$product}:{$currency}";
                    $account = BankAccount::firstOrCreate([
                        'bank_connection_id' => $connection->id, 'external_id_hash' => hash('sha256', $accountKey),
                    ], ['encrypted_external_id' => $accountKey, 'name' => $product, 'currency' => $currency,
                        'account_type' => 'joint_current', 'is_joint' => true, 'is_enabled' => true]);
                    $firstAccount ??= $account;
                    $description = trim((string) ($mapped['description'] ?? ''));
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
                        'is_cash_withdrawal' => $classification['is_cash_withdrawal'], 'provider_payload' => ['source' => 'revolut_statement', 'row' => $index + 2]]);
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
                } catch (\Throwable) {
                    $counts['failed']++;
                }
            }
            $import->update(['bank_account_id' => $firstAccount?->id, 'status' => $counts['failed'] && ! $counts['imported'] ? 'failed' : 'completed',
                'rows_total' => $counts['total'], 'rows_imported' => $counts['imported'], 'rows_duplicate' => $counts['duplicate'],
                'rows_failed' => $counts['failed'], 'period_from' => $dates->min()?->toDateString(), 'period_to' => $dates->max()?->toDateString(),
                'error_summary' => $counts['failed'] ? "{$counts['failed']} řádků nebylo možné načíst." : null]);
            $links = $this->reconciliation->reconcileSpace($space);

            return ['import' => $this->payload($import->fresh()), 'duplicate_file' => false, 'trip_links_created' => $links];
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
        $first = strtok($content, "\r\n") ?: '';
        $delimiters = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($delimiters);
        $delimiter = array_key_first($delimiters);
        $stream = fopen('php://temp', 'r+');
        abort_unless($stream, 500, 'Výpis nelze dočasně zpracovat.');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (! $this->emptyRow($row)) {
                $rows[] = $row;
            }
        }
        fclose($stream);

        return $rows;
    }

    private function xlsxRows(string $path): array
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'Server nemá rozšíření ZIP potřebné pro XLSX. Použijte CSV.');
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'XLSX soubor nelze otevřít.');
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml) {
            $xml = simplexml_load_string($sharedXml);
            foreach ($xml->si ?? [] as $item) {
                $shared[] = isset($item->t) ? (string) $item->t : collect($item->r ?? [])->map(fn ($part) => (string) $part->t)->implode('');
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        abort_unless($sheetXml, 422, 'XLSX neobsahuje první list.');
        $sheet = simplexml_load_string($sheetXml);
        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                preg_match('/([A-Z]+)\d+/', (string) $cell['r'], $match);
                $column = $this->columnIndex($match[1] ?? 'A');
                $type = (string) $cell['t'];
                $raw = (string) ($cell->v ?? '');
                $values[$column] = $type === 's' ? ($shared[(int) $raw] ?? '') : ($type === 'inlineStr' ? (string) $cell->is->t : $raw);
            }
            if ($values) {
                ksort($values);
                $max = max(array_keys($values));
                $rows[] = array_map(fn ($index) => $values[$index] ?? '', range(0, $max));
            }
        }

        return $rows;
    }

    private function columns(array $headers): array
    {
        $result = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->classifier->normalize($header);
            foreach (self::ALIASES as $key => $aliases) {
                if (in_array($normalized, array_map(fn ($value) => $this->classifier->normalize($value), $aliases), true)) {
                    $result[$key] = $index;
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

    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = preg_replace('/[^0-9,.-]/', '', (string) $value);
        if (str_contains($text, ',') && str_contains($text, '.')) {
            if (strrpos($text, ',') > strrpos($text, '.')) {
                $text = str_replace(',', '.', str_replace('.', '', $text));
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        } if (is_numeric($value) && (float) $value > 20000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        } foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'm/d/Y H:i:s', 'm/d/Y'] as $format) {
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

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

return $index - 1;
    }

    private function payload(BankImport $import): array
    {
        return ['uuid' => $import->uuid, 'filename' => $import->original_filename, 'status' => $import->status,
            'rows_total' => $import->rows_total, 'rows_imported' => $import->rows_imported, 'rows_duplicate' => $import->rows_duplicate,
            'rows_failed' => $import->rows_failed, 'period_from' => $import->period_from?->toDateString(), 'period_to' => $import->period_to?->toDateString(), 'error' => $import->error_summary];
    }
}
