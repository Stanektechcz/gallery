<?php

namespace App\Console\Commands;

use App\Services\ExifExtractorService;
use Illuminate\Console\Command;

class ExifDiagnosticsCommand extends Command
{
    protected $signature   = 'gallery:exif-diagnostics {file? : Path to a test HEIC/JPEG file}';
    protected $description = 'Diagnose EXIF/GPS extraction capabilities and show required PHP.ini settings';

    public function handle(): int
    {
        $this->info('=== EXIF/GPS Diagnostics ===');
        $this->newLine();

        // 1. PHP functions
        $this->line('<fg=cyan>PHP Functions:</>');
        $functions = [
            'proc_open'      => 'Run exiftool via process (bypasses disable_functions)',
            'shell_exec'     => 'Run exiftool via shell (less critical if proc_open works)',
            'exec'           => 'Alternative process execution',
            'exif_read_data' => 'PHP EXIF extension (JPEG/TIFF only)',
            'getimagesize'   => 'Image dimensions',
        ];

        $procOpenWorks = false;
        foreach ($functions as $fn => $desc) {
            $available = function_exists($fn);
            $status    = $available ? '<fg=green>✓ available</>' : '<fg=red>✗ DISABLED</>';
            $this->line("  {$status}  {$fn} — {$desc}");
            if ($fn === 'proc_open') $procOpenWorks = $available;
        }

        $this->newLine();

        // 2. PHP Extensions
        $this->line('<fg=cyan>PHP Extensions:</>');
        $extensions = [
            'imagick' => 'ImageMagick — reads HEIC EXIF IFD segment',
            'exif'    => 'PHP EXIF — exif_read_data() for JPEG/TIFF',
            'gd'      => 'GD — thumbnail generation',
        ];
        foreach ($extensions as $ext => $desc) {
            $loaded = extension_loaded($ext);
            $status = $loaded ? '<fg=green>✓ loaded</>' : '<fg=yellow>○ not loaded</>';
            $this->line("  {$status}  {$ext} — {$desc}");
        }

        $this->newLine();

        // 3. exiftool binary
        $this->line('<fg=cyan>exiftool binary:</>');
        $exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');
        $exists = file_exists($exiftoolPath);
        $this->line('  Path: ' . $exiftoolPath . ($exists ? ' <fg=green>✓ exists</>' : ' <fg=red>✗ NOT FOUND</>'));

        if ($exists && $procOpenWorks) {
            try {
                $proc = proc_open([$exiftoolPath, '-ver'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (is_resource($proc)) {
                    $ver = trim(stream_get_contents($pipes[1]));
                    fclose($pipes[1]); fclose($pipes[2]);
                    proc_close($proc);
                    $this->line("  Version: <fg=green>{$ver}</>");
                }
            } catch (\Throwable $e) {
                $this->line('  proc_open test: <fg=red>' . $e->getMessage() . '</>');
            }
        } elseif ($exists && !$procOpenWorks) {
            $this->line('  <fg=yellow>exiftool exists but proc_open is disabled — cannot run it</>');
        }

        // 4. Test on real file
        if ($file = $this->argument('file')) {
            $this->newLine();
            $this->line('<fg=cyan>Test extraction from: ' . $file . '</>');
            if (!file_exists($file)) {
                $this->error('File not found: ' . $file);
            } else {
                $result = (new ExifExtractorService())->extract($file);
                if (empty($result)) {
                    $this->error('No EXIF data extracted!');
                } else {
                    foreach ($result as $key => $value) {
                        $val = $value instanceof \Carbon\Carbon ? $value->toDateTimeString() : (string) $value;
                        $icon = in_array($key, ['latitude', 'longitude', 'altitude']) ? '<fg=green>GPS</> ' : '';
                        $this->line("  {$icon}<fg=yellow>{$key}</>: {$val}");
                    }
                    if (isset($result['latitude'])) {
                        $this->info('✓ GPS extraction WORKING');
                    } else {
                        $this->warn('✗ GPS not found in file (or file has no GPS data)');
                    }
                }
            }
        }

        $this->newLine();

        // 5. Recommendations
        $this->line('<fg=cyan>ISPConfig PHP.ini recommendations:</>');
        $this->newLine();

        $disabled = ini_get('disable_functions');
        $blockedFunctions = [];
        if ($disabled) {
            $list = array_map('trim', explode(',', $disabled));
            $needed = ['proc_open', 'proc_close', 'proc_get_status', 'exec', 'shell_exec'];
            $blockedFunctions = array_intersect($list, $needed);
        }

        if (!empty($blockedFunctions)) {
            $this->warn('Currently blocked functions: ' . implode(', ', $blockedFunctions));
            $this->newLine();
            $this->line('  In ISPConfig → Web → PHP Settings → Custom php.ini, ADD:');
            $this->line('');

            // Show what to remove from disable_functions
            $newDisabled = array_filter(
                array_map('trim', explode(',', $disabled)),
                fn($f) => !in_array($f, ['proc_open', 'proc_close', 'proc_get_status', 'shell_exec', 'exec'])
            );
            $this->line('  <fg=yellow>disable_functions = ' . implode(', ', $newDisabled) . '</>');
        } elseif (!$procOpenWorks) {
            $this->warn('proc_open is not available. Add to php.ini:');
            $this->line('  <comment>Ensure proc_open is NOT in disable_functions</comment>');
        } else {
            $this->info('✓ proc_open is available — exiftool will work');
        }

        $this->newLine();
        $this->line('  <fg=cyan>Recommended ISPConfig php.ini for this web:</>');
        $this->line('');
        $this->line('  <fg=yellow>; Required for EXIF extraction via exiftool</>');
        $this->line('  <fg=yellow>; Remove proc_open, proc_close, exec from disable_functions</>');

        if (!extension_loaded('imagick')) {
            $this->line('');
            $this->line('  <fg=yellow>; Install ImageMagick PHP extension for HEIC support:</>');
            $this->line('  <fg=yellow>; apt install php-imagick  (then restart PHP-FPM)</>');
        }

        if (!extension_loaded('exif')) {
            $this->line('');
            $this->line('  <fg=yellow>; Enable EXIF extension:</>');
            $this->line('  <fg=yellow>; extension=exif</>');
        }

        $this->newLine();
        $this->line('<fg=cyan>Current disable_functions value:</>');
        $this->line('  ' . (ini_get('disable_functions') ?: '(none — all functions available)'));

        return 0;
    }
}
