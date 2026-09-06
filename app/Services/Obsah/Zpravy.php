<?php

namespace App\Services\Obsah;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Zprávy a hlasovky ve tvaru, ve kterém je kreslí prototyp.
 *
 * Aplikace má chat s vlastní tabulkou; prototyp z něj neukazoval nic — jedenáct
 * napsaných replik z jednoho srpnového víkendu, které si dvojice nikdy nenapsala.
 *
 * `who` není iniciála jména, ale **strana**: prototyp porovnává `m.who === 'A'`
 * a myslí tím „moje". Posílá se proto `A` za přihlášeného a `M` za toho druhého —
 * jinak by si každý z dvojice četl vlastní zprávy jako cizí.
 */
class Zpravy implements PoskytovatelObsahu
{
    private const ZPRAV = 200;

    private const DNY = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function skupina(): string
    {
        return 'zpravy';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('chat_messages')) {
            return [];
        }

        $zpravy = $this->zpravy($prostor)
            // Prázdná bublina není zpráva. Zůstávají po hrách a po zrušených
            // přílohách a v chatu by vypadaly jako výpadek.
            ->filter(fn (ChatMessage $m) => $this->text($m) !== '')
            ->values();

        if ($zpravy->isEmpty()) {
            return [];
        }

        return array_filter([
            'MSGS' => $this->radky($zpravy),
            'MSGFILES' => $this->soubory($zpravy),
            // Útržek hovoru na úvodní obrazovce — posledních pár replik.
            'AMSG' => $this->utrzek($zpravy),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Zprávy páru.
     *
     * Přes model, ne přes dotazovač: `body` je v databázi **šifrované**
     * a přímý dotaz by do chatu poslal base64 místo věty. Smazané zprávy
     * (`SoftDeletes`) se neposílají — dvojice je smazala.
     *
     * @return Collection<int, ChatMessage>
     */
    private function zpravy(GallerySpace $prostor): Collection
    {
        return ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(self::ZPRAV)
            ->get()
            // Zpátky do pořadí, ve kterém se to říkalo — strop bere ty poslední.
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Zpráva: `[id, strana, den, čas, druh, text, doplněk]`.
     *
     * @param  Collection<int, object>  $zpravy
     * @return list<array<int, mixed>>
     */
    private function radky(Collection $zpravy): array
    {
        $ja = auth()->id();

        return $zpravy->map(function (object $m) use ($ja) {
            $kdy = CarbonImmutable::parse($m->created_at);

            return [
                $m->uuid,
                (int) $m->created_by === (int) $ja ? 'A' : 'M',
                $this->den($kdy),
                $kdy->format('G:i'),
                $this->druh($m),
                $this->text($m),
                $this->doplnek($m),
            ];
        })->values()->all();
    }

    /**
     * Druh zprávy: text, hlasovka, fotka, soubor.
     *
     * Prototyp podle toho kreslí bublinu — hlasovka má přehrávač, fotka náhled
     * a soubor ikonu podle přípony.
     */
    private function druh(object $m): string
    {
        $typ = (string) ($m->attachment_type ?? '');
        $mime = (string) ($m->media_mime ?? '');

        return match (true) {
            $typ === 'voice' || str_starts_with($mime, 'audio/') => 'v',
            $typ === 'photo' || $typ === 'media' || str_starts_with($mime, 'image/') => 'p',
            $typ === 'file' || $mime !== '' => 'f',
            default => 't',
        };
    }

    /**
     * Hra v chatu je stav, ne replika.
     *
     * Prototyp pro ni bublinu nemá a vyrábět jednu z ničeho by znamenalo psát
     * dvojici do hovoru větu, kterou nikdo neřekl.
     */
    private function jeHra(object $m): bool
    {
        return (string) ($m->attachment_type ?? '') === 'game';
    }

    private function text(object $m): string
    {
        $telo = trim((string) ($m->body ?? ''));

        if ($telo !== '') {
            return $telo;
        }

        if ($this->jeHra($m)) {
            return '';
        }

        // Příloha bez textu se pojmenuje sama; prázdná bublina by nedávala smysl.
        return match ($this->druh($m)) {
            'v' => 'Hlasovka',
            'p' => 'Fotka',
            'f' => basename((string) ($m->media_path ?? 'Soubor')),
            default => '',
        };
    }

    /**
     * Doplněk vpravo dole: délka hlasovky, místo u fotky, velikost u souboru.
     */
    private function doplnek(object $m): string
    {
        return match ($this->druh($m)) {
            'v' => $this->delka($m),
            'p' => (string) ($m->attachment_ref ?? ''),
            'f' => $m->media_size ? $this->velikost((int) $m->media_size) : '',
            default => '',
        };
    }

    /**
     * Přílohy k prohlédnutí: `[jméno, popis, ikona]`.
     *
     * @param  Collection<int, object>  $zpravy
     * @return list<array<int, string>>
     */
    private function soubory(Collection $zpravy): array
    {
        return $zpravy
            ->filter(fn (object $m) => $this->druh($m) === 'f')
            ->map(function (object $m) {
                $jmeno = $this->text($m);
                $pripona = mb_strtolower(pathinfo($jmeno, PATHINFO_EXTENSION));

                return [
                    $jmeno,
                    trim($this->popisTypu($pripona).' · '.($m->media_size ? $this->velikost((int) $m->media_size) : ''), ' ·'),
                    $this->ikona($pripona),
                ];
            })
            ->unique(fn (array $r) => $r[0])
            ->values()
            ->all();
    }

    /**
     * Poslední repliky na úvodní obrazovku: `[strana, text, čas]`.
     *
     * Malá písmena, na rozdíl od chatu — prototyp je tak čte (`who === 'a'`).
     *
     * @param  Collection<int, object>  $zpravy
     * @return list<array<int, string>>
     */
    private function utrzek(Collection $zpravy): array
    {
        $ja = auth()->id();

        return $zpravy
            ->filter(fn (object $m) => $this->druh($m) === 't')
            ->take(-5)
            ->map(fn (object $m) => [
                (int) $m->created_by === (int) $ja ? 'a' : 'm',
                $this->text($m),
                CarbonImmutable::parse($m->created_at)->format('G:i'),
            ])
            ->values()
            ->all();
    }

    // ——— formát ———

    /** „Dnes", „Včera", jinak „Pondělí 10. srpna" — jak to kreslí oddělovač dnů. */
    private function den(CarbonImmutable $kdy): string
    {
        $dni = (int) $kdy->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

        return match (true) {
            $dni === 0 => 'Dnes',
            $dni === 1 => 'Včera',
            default => self::DNY[$kdy->dayOfWeek].' '.$kdy->day.'. '.self::MESICE[$kdy->month],
        };
    }

    private function delka(object $m): string
    {
        // Délka hlasovky se drží v odkazu na přílohu; bez ní se nic nepředstírá.
        $ref = (string) ($m->attachment_ref ?? '');

        return preg_match('/^\d{1,2}:\d{2}$/', $ref) ? $ref : '';
    }

    private function velikost(int $bajtu): string
    {
        $mb = $bajtu / 1_048_576;

        return $mb >= 1
            ? str_replace('.', ',', (string) round($mb, 1)).' MB'
            : str_replace('.', ',', (string) round($bajtu / 1024)).' kB';
    }

    private function popisTypu(string $pripona): string
    {
        return match ($pripona) {
            'pdf' => 'PDF',
            'xls', 'xlsx', 'csv' => 'Tabulka',
            'doc', 'docx' => 'Dokument',
            'png', 'jpg', 'jpeg', 'heic', 'webp' => 'Obrázek',
            'zip', 'rar' => 'Archiv',
            default => 'Soubor',
        };
    }

    private function ikona(string $pripona): string
    {
        return match ($pripona) {
            'pdf' => 'ph-file-pdf',
            'xls', 'xlsx', 'csv' => 'ph-file-xls',
            'doc', 'docx' => 'ph-file-doc',
            'png', 'jpg', 'jpeg', 'heic', 'webp' => 'ph-file-image',
            'zip', 'rar' => 'ph-file-zip',
            default => 'ph-file',
        };
    }
}
