<?php

namespace App\Support;

use App\Models\User;

/**
 * A member's own colours for the interface.
 *
 * Only the tokens that carry the look are customisable; the surface, glass and scrollbar
 * tokens are derived from them so a chosen palette stays internally consistent and cannot
 * be broken into an unreadable combination by editing one value in isolation.
 */
class ThemePalette
{
    /** Token => label shown in the editor. Order is the order of the editor. */
    public const TOKENS = [
        'bg-primary'     => 'Pozadí aplikace',
        'bg-secondary'   => 'Pozadí panelů',
        'bg-card'        => 'Pozadí karet',
        'border'         => 'Linky a okraje',
        'text-primary'   => 'Hlavní text',
        'text-secondary' => 'Vedlejší text',
        'accent'         => 'Zvýrazňující barva',
        'accent-hover'   => 'Zvýraznění při najetí',
    ];

    public const DEFAULTS = [
        'dark' => [
            'bg-primary' => '#0f0f1a', 'bg-secondary' => '#1a1a2e', 'bg-card' => '#16213e',
            'border' => '#2d2d4e', 'text-primary' => '#f0f0f5', 'text-secondary' => '#9ca3af',
            'accent' => '#6c63ff', 'accent-hover' => '#7c74ff',
        ],
        'light' => [
            'bg-primary' => '#f6f5fb', 'bg-secondary' => '#ffffff', 'bg-card' => '#ffffff',
            'border' => '#e2dfec', 'text-primary' => '#16151f', 'text-secondary' => '#5b5770',
            'accent' => '#5b51e8', 'accent-hover' => '#4a3fd6',
        ],
    ];

    /** @return array{dark:array<string,string>, light:array<string,string>} */
    public static function forUser(?User $user): array
    {
        $stored = is_array($user?->preferences) ? ($user->preferences['theme_palette'] ?? null) : null;
        if (! is_array($stored)) return ['dark' => [], 'light' => []];

        return [
            'dark' => self::clean($stored['dark'] ?? []),
            'light' => self::clean($stored['light'] ?? []),
        ];
    }

    /**
     * Keeps only known tokens holding a valid hex colour, so nothing a client sends can
     * end up inside a stylesheet.
     *
     * @return array<string,string>
     */
    public static function clean(mixed $values): array
    {
        if (! is_array($values)) return [];

        $clean = [];
        foreach (self::TOKENS as $token => $label) {
            $value = $values[$token] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $clean[$token] = strtolower($value);
            }
        }

        return $clean;
    }

    /**
     * Inline stylesheet for the chosen palettes, injected server-side so a member never
     * sees the stock colours flash before their own.
     *
     * Derived tokens are recomputed from the palette: surfaces and the glass panel take
     * their tint from the accent, and the scrollbar from the text colour, so a light
     * palette never keeps dark-mode overlays.
     */
    public static function css(?User $user): string
    {
        $palettes = self::forUser($user);
        $blocks = [];

        foreach (['dark', 'light'] as $mode) {
            $palette = $palettes[$mode];
            if ($palette === []) continue;

            $lines = [];
            foreach ($palette as $token => $value) {
                $lines[] = "--color-{$token}:{$value}";
            }

            $accent = $palette['accent'] ?? self::DEFAULTS[$mode]['accent'];
            $text = $palette['text-primary'] ?? self::DEFAULTS[$mode]['text-primary'];
            $card = $palette['bg-card'] ?? self::DEFAULTS[$mode]['bg-card'];
            [$ar, $ag, $ab] = self::rgb($accent);
            [$tr, $tg, $tb] = self::rgb($text);
            [$cr, $cg, $cb] = self::rgb($card);

            $lines[] = "--color-surface-muted:rgb({$ar} {$ag} {$ab} / " . ($mode === 'light' ? '0.05' : '0.14') . ')';
            $lines[] = "--color-surface-hover:rgb({$ar} {$ag} {$ab} / " . ($mode === 'light' ? '0.08' : '0.18') . ')';
            $lines[] = "--color-glass-bg:rgb({$cr} {$cg} {$cb} / 0.88)";
            $lines[] = "--color-glass-border:rgb({$tr} {$tg} {$tb} / 0.1)";
            $lines[] = "--color-scrollbar:rgb({$tr} {$tg} {$tb} / 0.22)";

            $declarations = implode(';', $lines);

            if ($mode === 'dark') {
                // Dark is the default, so it also applies when no attribute is set.
                $blocks[] = ":root:not([data-theme=\"light\"]){{$declarations}}";
            } else {
                $blocks[] = ":root[data-theme=\"light\"]{{$declarations}}";
                $blocks[] = "@media (prefers-color-scheme: light){:root:not([data-theme]){{$declarations}}}";
            }
        }

        return implode('', $blocks);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
