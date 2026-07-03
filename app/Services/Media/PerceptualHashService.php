<?php

namespace App\Services\Media;

use App\Models\MediaItem;

/**
 * Perceptual hash calculation service.
 * NOT AI - uses classical image hashing algorithms (pHash, dHash, aHash).
 */
class PerceptualHashService
{
    /**
     * Calculate difference hash (dHash) - 8x8 grid, 64-bit hash.
     */
    public function calculateDHash(string $imagePath): ?string
    {
        try {
            $image = imagecreatefromstring(file_get_contents($imagePath));
            if (!$image) return null;

            // Resize to 9x8 for dHash
            $resized = imagecreatetruecolor(9, 8);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
            imagedestroy($image);

            // Convert to grayscale and compute differences
            $hash = '';
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $left  = $this->grayValue($resized, $x, $y);
                    $right = $this->grayValue($resized, $x + 1, $y);
                    $hash .= ($left > $right) ? '1' : '0';
                }
            }

            imagedestroy($resized);
            return $this->binaryToHex($hash);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Calculate average hash (aHash).
     */
    public function calculateAHash(string $imagePath): ?string
    {
        try {
            $image = imagecreatefromstring(file_get_contents($imagePath));
            if (!$image) return null;

            // Resize to 8x8
            $resized = imagecreatetruecolor(8, 8);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));
            imagedestroy($image);

            // Compute average gray
            $total = 0;
            $pixels = [];
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $gray    = $this->grayValue($resized, $x, $y);
                    $pixels[] = $gray;
                    $total   += $gray;
                }
            }
            $avg = $total / 64;

            $hash = '';
            foreach ($pixels as $p) {
                $hash .= ($p >= $avg) ? '1' : '0';
            }

            imagedestroy($resized);
            return $this->binaryToHex($hash);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hamming distance between two hex hashes.
     */
    public function hammingDistance(string $hash1, string $hash2): int
    {
        $b1 = $this->hexToBinary($hash1);
        $b2 = $this->hexToBinary($hash2);

        $distance = 0;
        $len = min(strlen($b1), strlen($b2));
        for ($i = 0; $i < $len; $i++) {
            if ($b1[$i] !== $b2[$i]) $distance++;
        }
        return $distance;
    }

    /**
     * Two images are "similar" if hamming distance <= threshold.
     */
    public function areSimilar(string $hash1, string $hash2, int $threshold = 10): bool
    {
        return $this->hammingDistance($hash1, $hash2) <= $threshold;
    }

    private function grayValue(\GdImage $image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
    }

    private function binaryToHex(string $binary): string
    {
        $hex = '';
        foreach (str_split($binary, 4) as $nibble) {
            $hex .= base_convert($nibble, 2, 16);
        }
        return $hex;
    }

    private function hexToBinary(string $hex): string
    {
        $binary = '';
        foreach (str_split($hex) as $char) {
            $binary .= str_pad(base_convert($char, 16, 2), 4, '0', STR_PAD_LEFT);
        }
        return $binary;
    }
}
