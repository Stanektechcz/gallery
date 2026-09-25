<?php

namespace Tests\Feature\Mazani;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Služba mazání odmítá výjimkou s kódem odpovědi (`abort()`), ověření zámku
 * hotovou odpovědí (`HttpResponseException`). Test chce jen kód.
 */
trait OcekavaChybu
{
    protected function ocekavejChybu(int $kod, callable $akce): void
    {
        try {
            $akce();
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($kod, $e->getStatusCode(), $e->getMessage());

            return;
        } catch (HttpResponseException $e) {
            $this->assertSame($kod, $e->getResponse()->getStatusCode());

            return;
        }

        $this->fail('Čekal jsem odmítnutí s kódem '.$kod.', akce ale prošla.');
    }
}
