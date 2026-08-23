<?php

namespace App\Services\Media;

use Carbon\Carbon;

/** Derives safe, explicitly marked fallback metadata from camera filenames. */
class FilenameMetadataService
{
    /**
     * Examples accepted: 20260627.mp4, VID_2026-06-27_153012.mp4,
     * 2026_06_27-video.mov. File metadata always takes precedence later.
     */
    public function infer(string $filename, string $mediaType): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        // Datum a — pokud ho název nese — i čas. Telefony píšou 20260701_145133 a ta
        // druhá půlka je stejně spolehlivá jako první: je to čas, který ukazovaly hodiny
        // ve fotoaparátu. Zahodit ho znamenalo poslat celý den fotek na půlnoc, kde se
        // pak v archivu seřadily náhodně.
        if (!preg_match('/(?<!\d)(20\d{2})[._-]?(0[1-9]|1[0-2])[._-]?([0-3]\d)(?!\d)(?:[._\-T ]?([0-2]\d)[._:-]?([0-5]\d)(?:[._:-]?([0-5]\d))?)?/', $base, $match)) {
            return [];
        }

        $hodina = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : null;
        if ($hodina !== null && $hodina > 23) $hodina = null;

        try {
            $date = Carbon::create((int) $match[1], (int) $match[2], (int) $match[3])->startOfDay();

            if ($hodina !== null) {
                $date->setTime($hodina, (int) ($match[5] ?? 0), (int) ($match[6] ?? 0));
            }
        } catch (\Throwable) {
            return [];
        }

        // Carbon normalizes invalid dates (e.g. 20260231), which must not be
        // silently accepted as a different real date.
        if ($date->format('Ymd') !== $match[1] . $match[2] . $match[3]) {
            return [];
        }

        $label = $mediaType === 'video' ? 'Video' : 'Fotografie';

        return [
            'taken_at' => $date,
            'display_title' => $label . ' z ' . $date->locale('cs')->isoFormat('D. M. YYYY'),
        ];
    }
}
