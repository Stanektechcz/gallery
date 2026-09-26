<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PhpToken;
use Tests\TestCase;

/**
 * V `app/` se programy nespouštějí přes shell.
 *
 * Na serveru jsou `shell_exec`, `exec` a spol. v php.ini vypnuté. Volání
 * pak nespadne nahlas, jen vrátí nic: video nemělo náhled ani kopii pro
 * prohlížeč, EXIF chyběl — a nikde nic. Externí programy se spouštějí přes
 * `Illuminate\Support\Facades\Process` (`proc_open`) polem argumentů.
 *
 * Kontroluje se kód, ne text: zmínka v komentáři nebo název funkce
 * v řetězci (diagnostika vypíše, co je vypnuté) nevadí, `curl_exec()`
 * ani metoda `->exec()` také ne.
 */
class BezShelluTest extends TestCase
{
    private const ZAKAZANE = ['shell_exec', 'exec', 'passthru', 'system', 'popen', 'pcntl_exec'];

    public function test_app_nevola_shell(): void
    {
        $nalezy = [];

        foreach (File::allFiles(app_path()) as $soubor) {
            if ($soubor->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->volaniShellu($soubor->getContents()) as [$radek, $co]) {
                $nalezy[] = $soubor->getRelativePathname().":{$radek} {$co}";
            }
        }

        $this->assertSame([], $nalezy,
            "Na serveru jsou tyhle funkce vypnuté — použijte Process::timeout(…)->run([…]):\n".implode("\n", $nalezy));
    }

    /** Hlídač sám musí zákaz poznat, jinak by prošlo cokoli. */
    public function test_hlidac_pozna_volani_a_ignoruje_zminky(): void
    {
        $kod = <<<'PHP'
<?php
// shell_exec('x') v komentáři nevadí
$a = 'exec';
$b = curl_exec($ch);
$c = $pdo->exec('SELECT 1');
$d = shell_exec('ls');
$e = \exec('ls', $out);
$f = `ls`;
PHP;

        $this->assertSame([[6, 'shell_exec()'], [7, 'exec()'], [8, '`…`']], $this->volaniShellu($kod));
    }

    /** @return list<array{int, string}> řádek a co se na něm volá */
    private function volaniShellu(string $kod): array
    {
        $tokeny = array_values(array_filter(
            PhpToken::tokenize($kod),
            fn (PhpToken $t) => ! $t->isIgnorable(),
        ));
        $nalezy = [];

        foreach ($tokeny as $i => $token) {
            if ($token->text === '`') {
                // Otevírací i zavírací obrácený apostrof; stačí jeden nález.
                $posledni = end($nalezy);
                if ($posledni === false || $posledni[0] !== $token->line) {
                    $nalezy[] = [$token->line, '`…`'];
                }

                continue;
            }
            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $jmeno = strtolower(ltrim($token->text, '\\'));
            $dalsi = $tokeny[$i + 1] ?? null;
            $predchozi = $tokeny[$i - 1] ?? null;
            if (! in_array($jmeno, self::ZAKAZANE, true) || $dalsi?->text !== '(') {
                continue;
            }
            if ($predchozi?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                continue;
            }
            $nalezy[] = [$token->line, $jmeno.'()'];
        }

        return $nalezy;
    }
}
