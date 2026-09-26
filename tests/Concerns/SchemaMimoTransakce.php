<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Test, který mění schéma (zahodí tabulku, index, spustí migraci s DDL),
 * si databázi staví sám a běží bez obalové transakce.
 *
 * `RefreshDatabase` drží každý test v transakci a na konci ji vrátí. Na SQLite
 * to se změnou schématu projde — tam je i DDL transakční. MySQL (provoz, CI)
 * ale před každým `DROP`/`CREATE`/`ALTER` transakci potichu potvrdí. Zbytek
 * testu pak běží bez transakce, Laravel si přitom myslí, že je v ní: aplikace
 * v `DB::transaction()` založí SAVEPOINT mimo transakci a jeho uvolnění spadne
 * na „SAVEPOINT trans2 does not exist". A co test zahodil nebo zapsal, už se
 * nevrátí — zůstane dalším testům.
 *
 * Proto: čisté schéma před testem (`migrate:fresh`) a po něm
 * `RefreshDatabaseState::$migrated = false`, aby si první další test
 * s `RefreshDatabase` postavil schéma znovu. Bez toho by na MySQL dostal
 * databázi po tomhle testu — bez zahozených tabulek a s jeho řádky.
 *
 * Do jedné třídy s `RefreshDatabase` nepatří: ta by test obalila transakcí
 * dřív, než by se sem vůbec došlo. Testy se změnou schématu proto mají
 * vlastní třídu.
 */
trait SchemaMimoTransakce
{
    protected function setUpSchemaMimoTransakce(): void
    {
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->fail(static::class.' používá RefreshDatabase i SchemaMimoTransakce — změna schématu patří do vlastní třídy.');
        }

        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    protected function tearDownSchemaMimoTransakce(): void
    {
        RefreshDatabaseState::$migrated = false;
    }
}
