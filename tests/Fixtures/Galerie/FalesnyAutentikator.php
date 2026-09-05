<?php

namespace Tests\Fixtures\Galerie;

use CBOR\ByteStringObject;
use CBOR\Encoder;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

/**
 * Autentikátor na papíře — Touch ID, které existuje jen v testu.
 *
 * Odpovědi se **skutečně podepisují** vlastním klíčem ES256 a skládají se
 * přesně tak, jak je posílá prohlížeč: CBOR attestation object, `authData`
 * s příznaky a počítadlem, DER podpis. Kdyby si test odpovědi jen vymyslel,
 * potvrzoval by, že se do databáze uloží řetězec — ne že server pozná podvrh.
 *
 * Vědomé zjednodušení: formát `none` (bez atestace výrobce), protože přesně
 * ten posílají platformní autentikátory v telefonech, o které tu jde.
 */
class FalesnyAutentikator
{
    /** Příznaky v `authData`, jak je definuje WebAuthn. */
    private const UZIVATEL_PRITOMEN = 0x01;   // UP — někdo se dotkl čtečky

    private const UZIVATEL_OVEREN = 0x04;     // UV — otisk seděl

    private const KLIC_PRILOZEN = 0x40;       // AT — v odpovědi je nový klíč

    private \OpenSSLAsymmetricKey $klic;

    private string $credId;

    private string $aaguid;

    private int $pocitadlo = 0;

    public function __construct(
        private readonly string $rpId = 'localhost',
        private readonly string $origin = 'http://localhost',
    ) {
        $klic = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            // Vlastní konfigurace — jinak PHP na Windows klíč nevyrobí vůbec.
            'config' => __DIR__.'/openssl.cnf',
        ]);

        $klic !== false || throw new \RuntimeException(
            'Testovací klíč se nepodařilo vyrobit: '.openssl_error_string(),
        );

        $this->klic = $klic;
        $this->credId = random_bytes(32);
        // Platformní autentikátory se neidentifikují — samé nuly jsou správná odpověď.
        $this->aaguid = str_repeat("\0", 16);
    }

    /** Identifikátor klíče tak, jak ho vidí klient i databáze. */
    public function idKlice(): string
    {
        return $this->b64u($this->credId);
    }

    public function pocitadlo(): int
    {
        return $this->pocitadlo;
    }

    /** Tělo pro `POST /api/webauthn/register`. */
    public function registrace(string $challenge, ?string $label = null): array
    {
        $this->pocitadlo++;

        $authData = $this->authData(
            self::UZIVATEL_PRITOMEN | self::UZIVATEL_OVEREN | self::KLIC_PRILOZEN,
            $this->attestedCredentialData(),
        );

        $attestationObject = (new Encoder)->encode(
            MapObject::create()
                ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
                ->add(TextStringObject::create('attStmt'), MapObject::create())
                ->add(TextStringObject::create('authData'), ByteStringObject::create($authData)),
        );

        $telo = [
            'id' => $this->idKlice(),
            'rawId' => $this->idKlice(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->clientData('webauthn.create', $challenge),
                'attestationObject' => $this->b64u($attestationObject),
            ],
        ];

        // Klient posílá popisek zařízení mimo pověření, ve stejném těle.
        return $label === null ? $telo : $telo + ['label' => $label];
    }

    /**
     * Tělo pro `POST /api/webauthn/login`.
     *
     * `$pocitadlo` se dá vnutit, aby šlo vyzkoušet i zkopírovaný klíč, který
     * přehrává starší číslo — jinak by se ta kontrola nedala otestovat.
     */
    public function prihlaseni(string $challenge, ?int $pocitadlo = null, ?string $userHandle = null): array
    {
        $this->pocitadlo = $pocitadlo ?? $this->pocitadlo + 1;

        $authData = $this->authData(self::UZIVATEL_PRITOMEN | self::UZIVATEL_OVEREN);
        $clientData = $this->clientData('webauthn.get', $challenge);

        // Podepisuje se `authData` a otisk klientských dat — nic víc, nic míň.
        openssl_sign(
            $authData.hash('sha256', $this->zB64u($clientData), true),
            $podpis,
            $this->klic,
            OPENSSL_ALGO_SHA256,
        );

        $odpoved = [
            'clientDataJSON' => $clientData,
            'authenticatorData' => $this->b64u($authData),
            'signature' => $this->b64u($podpis),
        ];

        if ($userHandle !== null) {
            $odpoved['userHandle'] = $this->b64u($userHandle);
        }

        return [
            'id' => $this->idKlice(),
            'rawId' => $this->idKlice(),
            'type' => 'public-key',
            'response' => $odpoved,
        ];
    }

    // ——— skládání bajtů ———

    private function authData(int $priznaky, string $ocas = ''): string
    {
        return hash('sha256', $this->rpId, true)
            .chr($priznaky)
            .pack('N', $this->pocitadlo)
            .$ocas;
    }

    private function attestedCredentialData(): string
    {
        return $this->aaguid
            .pack('n', strlen($this->credId))
            .$this->credId
            .$this->coseKlic();
    }

    /** Veřejný klíč v CBOR mapě podle COSE (ES256 nad křivkou P-256). */
    private function coseKlic(): string
    {
        $detaily = openssl_pkey_get_details($this->klic)['ec'];

        return (new Encoder)->encode(
            MapObject::create()
                ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))    // kty: EC2
                ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))   // alg: ES256
                ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))   // crv: P-256
                ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->na32($detaily['x'])))
                ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->na32($detaily['y']))),
        );
    }

    private function clientData(string $typ, string $challenge): string
    {
        return $this->b64u(json_encode([
            'type' => $typ,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ]));
    }

    /** Souřadnice bodu musí mít přesně 32 bajtů — OpenSSL vede úvodní nuly. */
    private function na32(string $souradnice): string
    {
        return str_pad($souradnice, 32, "\0", STR_PAD_LEFT);
    }

    private function b64u(string $syrove): string
    {
        return rtrim(strtr(base64_encode($syrove), '+/', '-_'), '=');
    }

    private function zB64u(string $hodnota): string
    {
        return (string) base64_decode(strtr($hodnota, '-_', '+/'), true);
    }
}
