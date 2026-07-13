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

        if (!preg_match('/(?<!\d)(20\d{2})[._-]?(0[1-9]|1[0-2])[._-]?([0-3]\d)(?!\d)/', $base, $match)) {
            return [];
        }

        try {
            $date = Carbon::create((int) $match[1], (int) $match[2], (int) $match[3])->startOfDay();
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
