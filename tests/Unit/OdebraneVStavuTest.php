<?php

namespace Tests\Unit;

use App\Services\Provoz\OdebraneVStavu;
use App\Support\Vejde;
use PHPUnit\Framework\TestCase;

/**
 * Odebrané identifikátory se zkracují stejně jako `client_id`.
 *
 * Zkracovalo se na 80 znaků, ale `client_id` jich má 64 — odebraný
 * identifikátor delší než 64 se s uloženým klíčem nikdy neshodl.
 */
class OdebraneVStavuTest extends TestCase
{
    public function test_odebrane_se_zkrati_na_sirku_client_id(): void
    {
        $dlouhy = 'x-'.str_repeat('a', 78);

        $odebrane = OdebraneVStavu::pro(['__odebrane' => ['dues' => [$dlouhy]]], 'dues');

        $this->assertSame([Vejde::do($dlouhy, Vejde::KLIENT)], $odebrane);
        $this->assertSame(64, mb_strlen($odebrane[0]));
    }

    public function test_uuid_zustane_cele(): void
    {
        $uuid = '0b0f6f2e-8f7c-4c1e-9d65-3b4b9f1d2a10';

        $this->assertSame([$uuid], OdebraneVStavu::pro(['__odebrane' => ['wishes' => [$uuid]]], 'wishes'));
    }
}
