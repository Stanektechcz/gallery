<?php

namespace App\Services\Travel;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class TripReservationImportService
{
    /** @return array{text:string,method:string,error:?string,data:array<string,mixed>} */
    public function analyse(?UploadedFile $file, ?string $pastedText, object $trip): array
    {
        $text = $this->cleanText((string) $pastedText);
        $method = $text !== '' ? 'pasted_text' : 'manual';
        $error = null;

        if ($text === '' && $file) {
            [$text, $method, $error] = $this->extractFileText($file);
        }

        return [
            'text' => $text,
            'method' => $method,
            'error' => $error,
            'data' => $this->parse($text, $file?->getClientOriginalName(), $trip),
        ];
    }

    /** @return array<string,mixed> */
    public function parse(string $text, ?string $filename, object $trip): array
    {
        $haystack = Str::lower($text.' '.($filename ?? ''));
        $type = match (true) {
            Str::contains($haystack, ['regiojet', 'flixbus', 'české dráhy', 'jízdenka', 'ticket', 'letenka', 'boarding pass', 'ryanair', 'wizz air', 'easyjet']) => 'ticket',
            Str::contains($haystack, ['booking.com', 'airbnb', 'hotel', 'hostel', 'ubytování', 'check-in', 'check in']) => 'accommodation',
            Str::contains($haystack, ['pojištění', 'insurance', 'pojistná smlouva']) => 'insurance',
            Str::contains($haystack, ['vstupenka', 'admission', 'tour', 'prohlídka', 'wellness', 'restaurant']) => 'activity',
            default => 'other',
        };
        $provider = $this->provider($haystack);
        $reference = $this->firstMatch($text, [
            '/(?:číslo|kód)\s+(?:rezervace|objednávky)\s*[:#-]?\s*([A-Z0-9][A-Z0-9-]{3,24})/iu',
            '/(?:booking|reservation|confirmation|reference|referenční|rezervace|pnr)(?:\s+(?:number|code|no\.?))?\s*[:#-]?\s*([A-Z0-9][A-Z0-9-]{3,24})/iu',
        ]);
        $dates = $this->dates($text, (string) ($trip->timezone ?? config('app.timezone', 'Europe/Prague')));
        $route = $this->route($text);
        [$amount, $currency] = $this->amount($text, strtoupper((string) ($trip->currency ?? 'CZK')));
        $place = $this->firstMatch($text, [
            '/(?:hotel|ubytování|accommodation|místo|venue|adresa|address)\s*[:\-]\s*([^\r\n]{3,160})/iu',
            '/(?:check-in|check in)\s+(?:at|v)\s+([^\r\n]{3,160})/iu',
        ]);
        $baseName = $filename ? pathinfo($filename, PATHINFO_FILENAME) : null;
        $kindLabel = ['ticket' => 'Jízdenka', 'accommodation' => 'Ubytování', 'insurance' => 'Pojištění', 'activity' => 'Rezervace aktivity', 'other' => 'Rezervace'][$type];
        $title = trim($kindLabel.($provider ? ' · '.$provider : ($baseName ? ' · '.$baseName : '')));
        $filled = collect([$provider, $reference, $dates[0] ?? null, $route['origin'], $route['destination'], $place, $amount])->filter(fn ($value) => filled($value))->count();

        return [
            'type' => $type,
            'title' => Str::limit($title, 255, ''),
            'provider' => $provider,
            'reference' => $reference,
            'starts_at' => $dates[0] ?? null,
            'ends_at' => $dates[1] ?? null,
            'origin' => $route['origin'],
            'destination' => $route['destination'],
            'place_name' => $place,
            'amount' => $amount,
            'currency' => $currency,
            'confidence' => min(0.98, round(0.28 + ($filled * 0.1), 2)),
        ];
    }

    /** @return array{string,string,?string} */
    private function extractFileText(UploadedFile $file): array
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $path = (string) $file->getRealPath();
        if (Str::startsWith($mime, 'text/') || in_array($mime, ['message/rfc822', 'application/json'], true)) {
            return [$this->cleanText((string) file_get_contents($path)), 'plain_text', null];
        }

        try {
            if ($mime === 'application/pdf') {
                return $this->process([(string) config('services.travel_documents.pdftotext_path', 'pdftotext'), '-layout', $path, '-'], 'pdftotext');
            }
            if (Str::startsWith($mime, 'image/')) {
                return $this->process([(string) config('services.travel_documents.tesseract_path', 'tesseract'), $path, 'stdout', '-l', (string) config('services.travel_documents.tesseract_languages', 'ces+eng')], 'tesseract');
            }
        } catch (Throwable $exception) {
            return ['', 'manual', 'Automatické čtení souboru není na serveru dostupné: '.Str::limit($exception->getMessage(), 300)];
        }

        return ['', 'manual', 'Tento typ souboru nelze číst automaticky. Údaje doplňte v následné kontrole.'];
    }

    /** @param array<int,string> $command @return array{string,string,?string} */
    private function process(array $command, string $method): array
    {
        $process = new Process($command);
        $process->setTimeout(25);
        $process->run();
        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'Nástroj skončil bez čitelného výstupu.';
            return ['', 'manual', Str::limit($message, 400)];
        }
        $text = $this->cleanText($process->getOutput());
        return [$text, $text !== '' ? $method : 'manual', $text === '' ? 'V souboru nebyl rozpoznán žádný text.' : null];
    }

    private function cleanText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return Str::limit(trim($text), 100000, '');
    }

    private function provider(string $haystack): ?string
    {
        foreach ([
            'booking.com' => 'Booking.com', 'regiojet' => 'RegioJet', 'flixbus' => 'FlixBus',
            'české dráhy' => 'České dráhy', 'cd.cz' => 'České dráhy', 'ryanair' => 'Ryanair',
            'wizz air' => 'Wizz Air', 'easyjet' => 'easyJet', 'airbnb' => 'Airbnb',
        ] as $needle => $label) if (Str::contains($haystack, $needle)) return $label;
        return null;
    }

    /** @param array<int,string> $patterns */
    private function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) if (preg_match($pattern, $text, $match)) return trim($match[1]);
        return null;
    }

    /** @return array<int,string> */
    private function dates(string $text, string $timezone): array
    {
        preg_match_all('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?:[T\s]+(\d{1,2}):(\d{2}))?|(?<!\d)(\d{1,2})[.\/]\s*(\d{1,2})[.\/]\s*(\d{4})(?:\s+(?:v\s*)?(\d{1,2})[:.]([0-5]\d))?/u', $text, $matches, PREG_SET_ORDER);
        $dates = [];
        foreach ($matches as $match) {
            try {
                if ($match[1] !== '') [$year, $month, $day, $hour, $minute] = [(int) $match[1], (int) $match[2], (int) $match[3], (int) ($match[4] ?: 12), (int) ($match[5] ?: 0)];
                else [$year, $month, $day, $hour, $minute] = [(int) $match[8], (int) $match[7], (int) $match[6], (int) ($match[9] ?: 12), (int) ($match[10] ?: 0)];
                $date = Carbon::create($year, $month, $day, $hour, $minute, 0, $timezone);
                if ($date && $date->year >= 2000 && $date->year <= 2100) $dates[] = $date->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // Invalid calendar dates are ignored and remain editable by the user.
            }
        }
        return array_values(array_unique($dates));
    }

    /** @return array{origin:?string,destination:?string} */
    private function route(string $text): array
    {
        $origin = $this->firstMatch($text, ['/(?:odjezd|departure|from|z)\s*[:\-]\s*([^\r\n]{2,100})/iu']);
        $destination = $this->firstMatch($text, ['/(?:příjezd|arrival|destination|to|do)\s*[:\-]\s*([^\r\n]{2,100})/iu']);
        if ((! $origin || ! $destination) && preg_match('/([^\r\n]{2,80})\s+(?:→|->|–>)\s+([^\r\n]{2,80})/u', $text, $route)) {
            $origin ??= trim($route[1]); $destination ??= trim($route[2]);
        }
        return ['origin' => $origin, 'destination' => $destination];
    }

    /** @return array{?float,string} */
    private function amount(string $text, string $fallbackCurrency): array
    {
        if (! preg_match('/(?:(CZK|EUR|USD|GBP|Kč|€|\$)\s*(\d{1,7}(?:[ .]\d{3})*(?:[,.]\d{1,2})?)|(\d{1,7}(?:[ .]\d{3})*(?:[,.]\d{1,2})?)\s*(CZK|EUR|USD|GBP|Kč|€|\$))/u', $text, $match)) return [null, $fallbackCurrency];
        $symbol = strtoupper((string) (($match[1] ?? '') ?: ($match[4] ?? '') ?: $fallbackCurrency));
        $currency = match ($symbol) { 'KČ' => 'CZK', '€' => 'EUR', '$' => 'USD', default => $symbol };
        $normalized = str_replace([' ', '.'], ['', ''], (string) (($match[2] ?? '') ?: ($match[3] ?? '')));
        $normalized = str_replace(',', '.', $normalized);
        return [round((float) $normalized, 2), in_array($currency, ['CZK', 'EUR', 'USD', 'GBP'], true) ? $currency : $fallbackCurrency];
    }
}
