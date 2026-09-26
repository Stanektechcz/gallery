<?php

namespace Tests\Unit;

use App\Support\OtiskySkriptu;
use PHPUnit\Framework\TestCase;

/**
 * Otisky inline skriptů musí sedět na bajt s tím, co spočítá prohlížeč —
 * jinak politika prototypu zablokuje jeho vlastní hlavičku a aplikace
 * se nespustí. Hlídají se místa, kde se čtení HTML liší od „najdi text".
 */
class OtiskySkriptuTest extends TestCase
{
    public function test_otisk_je_sha256_obsahu_v_base64(): void
    {
        $this->assertSame(["'sha256-".base64_encode(hash('sha256', 'var a = 1;', true))."'"],
            OtiskySkriptu::z('<p>x</p><script>var a = 1;</script>'));
    }

    /**
     * CRLF z pracovní kopie na Windows je v prohlížeči `\n`.
     *
     * Parser HTML sjednotí konce řádků dřív, než čte značky; otisk surových
     * bajtů by na počítači s `core.autocrlf` neseděl.
     */
    public function test_konce_radku_se_sjednoti_jako_v_parseru_html(): void
    {
        $this->assertSame(OtiskySkriptu::z("<script>\na();\nb();\n</script>"),
            OtiskySkriptu::z("<script>\r\na();\rb();\r\n</script>"));
    }

    public function test_obsah_se_bere_doslova_bez_orezani_a_dekodovani(): void
    {
        $obsah = "\n  if (a &amp;&amp; b < 2) { x = '&lt;'; }\n";

        $this->assertSame([OtiskySkriptu::otisk($obsah)], OtiskySkriptu::z('<script>'.$obsah.'</script>'));
    }

    public function test_vnejsi_skripty_a_datove_bloky_otisk_nedostanou(): void
    {
        $html = '<script src="./support.js"></script>'
            .'<script type="text/x-dc" data-props="{&quot;w&quot;:1}">šablona</script>'
            .'<script type="application/json">{"a":1}</script>'
            .'<script type="module">m()</script>'
            .'<script type="TEXT/JavaScript">j()</script>'
            .'<script type="">e()</script>';

        $this->assertSame(
            [OtiskySkriptu::otisk('m()'), OtiskySkriptu::otisk('j()'), OtiskySkriptu::otisk('e()')],
            OtiskySkriptu::z($html),
        );
    }

    /** `>` v uvozovkách značku neukončí a `</SCRIPT >` skript ano. */
    public function test_znacky_se_ctou_jako_v_prohlizeci(): void
    {
        $html = '<script data-x="a > b">prvni()</SCRIPT >'
            .'<scripts>ne</scripts>'
            .'<script>var s = "<script>";</script>';

        $this->assertSame(
            [OtiskySkriptu::otisk('prvni()'), OtiskySkriptu::otisk('var s = "<script>";')],
            OtiskySkriptu::z($html),
        );
    }

    /** Obsah datového bloku se nečte jako HTML — `<script>` v něm není značka. */
    public function test_skript_uvnitr_datoveho_bloku_neni_skript(): void
    {
        $html = '<script type="text/x-dc">const t = `<script>zlo()`;</script><script>ok()</script>';

        $this->assertSame([OtiskySkriptu::otisk('ok()')], OtiskySkriptu::z($html));
    }

    public function test_stejne_skripty_maji_jeden_otisk(): void
    {
        $this->assertCount(1, OtiskySkriptu::z('<script>a()</script><div></div><script>a()</script>'));
    }
}
