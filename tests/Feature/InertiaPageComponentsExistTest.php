<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every Inertia::render('X') needs resources/js/Pages/X.tsx to exist, otherwise the
 * client-side resolver throws "Page not found" and the user gets a blank screen.
 * The server still answers 200, so ordinary HTTP assertions never catch it.
 */
class InertiaPageComponentsExistTest extends TestCase
{
    public function test_every_rendered_inertia_component_has_a_page_file(): void
    {
        $components = $this->renderedComponents();

        $this->assertNotEmpty($components, 'No Inertia::render() calls were found — the scanner is probably broken.');

        $missing = array_values(array_filter(
            $components,
            fn (string $component) => ! file_exists(resource_path("js/Pages/{$component}.tsx"))
        ));

        $this->assertSame([], $missing, sprintf(
            "These components are rendered but resources/js/Pages/<name>.tsx is missing:\n  %s",
            implode("\n  ", $missing)
        ));
    }

    /** @return list<string> */
    private function renderedComponents(): array
    {
        $found = [];

        foreach ([app_path(), base_path('routes')] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/Inertia::render\(\s*'([^']+)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches
                );

                foreach ($matches[1] as $component) {
                    $found[$component] = true;
                }
            }
        }

        return array_keys($found);
    }
}
