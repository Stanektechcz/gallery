<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Odemknutí otiskem prstu nebo obličejem (WebAuthn, platformní autentikátor).
 *
 * Klient má celý průběh hotový — tyhle čtyři endpointy jen dodají challenge ze
 * serveru a ověří podpis. Bez nich je otisk zámek proti náhodnému kolemjdoucímu,
 * ne autentizace: challenge vyrobená v telefonu nedokazuje nic, protože si ji
 * telefon může vymyslet.
 *
 * Psáno proti `web-auth/webauthn-lib` **5.x**. README prototypu předepisuje 4.7,
 * jenže ta chce `symfony/uid ^6|^7` a tenhle Laravel má v8. Mezi hlavními verzemi
 * se změnilo trojí: ověřovací třídy dostávají `CeremonyStepManager`, načítání
 * pověření dělá serializer místo `PublicKeyCredentialLoader`, a z registrace
 * vypadne `CredentialRecord` místo `PublicKeyCredentialSource`.
 */
class WebauthnController extends Controller
{
    /** Krok 1 — parametry pro `navigator.credentials.create()`. */
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();

        $options = PublicKeyCredentialCreationOptions::create(
            rp: new PublicKeyCredentialRpEntity(config('galerie.rp_name'), config('galerie.rp_id')),
            user: new PublicKeyCredentialUserEntity($user->email, (string) $user->id, $user->name),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),    // ES256
                PublicKeyCredentialParameters::create('public-key', -257),  // RS256
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            // Zařízení, které klíč už má, se nenabídne podruhé.
            excludeCredentials: array_map(
                fn (string $id) => PublicKeyCredentialDescriptor::create('public-key', $this->zB64($id)),
                WebauthnCredential::idsForUser($user->id),
            ),
            timeout: 60_000,
        );

        /*
         * Challenge patří serveru, ne odpovědi.
         *
         * Kdyby se posílala tam a zpět, mohl by si ji klient vymyslet — a celé
         * ověření by dokazovalo jen to, že zařízení umí podepsat vlastní náhodné
         * číslo. Klíčem je účet, ne sezení: mobilní aplikace se hlásí tokenem
         * a žádnou cookie nemá.
         */
        Cache::put(
            $this->klicRegistrace($user->id),
            $this->serializer()->serialize($options, 'json'),
            now()->addSeconds($this->platnost()),
        );

        return $this->jako($options);
    }

    /** Krok 2 — uložení klíče, který vznikl v zařízení. */
    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        $ulozene = Cache::pull($this->klicRegistrace($user->id));

        if (! $ulozene) {
            throw ValidationException::withMessages(['challenge' => 'Ověření vypršelo — zkuste to znovu.']);
        }

        /** @var PublicKeyCredentialCreationOptions $volby */
        $volby = $this->serializer()->deserialize($ulozene, PublicKeyCredentialCreationOptions::class, 'json');

        $odpoved = $this->nactiPoverni($request)->response;

        if (! $odpoved instanceof AuthenticatorAttestationResponse) {
            throw ValidationException::withMessages(['response' => 'Očekávána odpověď z registrace klíče.']);
        }

        try {
            $zaznam = AuthenticatorAttestationResponseValidator::create(
                $this->obrad()->creationCeremony(),
            )->check($odpoved, $volby, config('galerie.rp_id'));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['response' => 'Klíč se nepodařilo ověřit — zkuste to znovu.']);
        }

        WebauthnCredential::updateOrCreate(
            ['credential_id' => $this->doB64($zaznam->publicKeyCredentialId)],
            [
                'user_id' => $user->id,
                'public_key' => $this->doB64($zaznam->credentialPublicKey),
                'sign_count' => $zaznam->counter,
                'transports' => $zaznam->transports ?: ['internal'],
                'aaguid' => (string) $zaznam->aaguid,
                // Popisek jde do sloupce na 255 znaků. User agent bývá delší
                // a MySQL na rozdíl od SQLite v testech takový zápis odmítne.
                'label' => mb_substr($request->string('label')->value() ?: (string) $request->userAgent(), 0, 200),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['registered' => true]);
    }

    /** Krok 3 — parametry pro `navigator.credentials.get()`. */
    public function loginOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->first();

        /*
         * Neexistující e-mail se z odpovědi nepozná.
         *
         * Kdyby endpoint u neznámé adresy odpověděl jinak, dal by se jím zjistit,
         * kdo v aplikaci je — a u aplikace pro dva lidi je to citlivější než jinde.
         * Prázdný `allowCredentials` vypadá stejně jako účet bez klíče.
         */
        $ids = $user ? WebauthnCredential::idsForUser($user->id) : [];

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: config('galerie.rp_id'),
            allowCredentials: array_map(
                fn (string $id) => PublicKeyCredentialDescriptor::create('public-key', $this->zB64($id), ['internal']),
                $ids,
            ),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 60_000,
        );

        /*
         * Tady ještě nikdo přihlášený není, takže challenge drží sezení — jediné
         * úložiště, které si klient nese sám (cookie) a nemůže do něj sáhnout.
         * Vlastní razítko platnosti je proto, že sezení žije mnohem déle než
         * dvě minuty, po které smí challenge platit.
         */
        $request->session()->put('galerie.webauthn', [
            'user' => $user?->id,
            'volby' => $this->serializer()->serialize($options, 'json'),
            'do' => now()->addSeconds($this->platnost())->getTimestamp(),
        ]);

        return $this->jako($options);
    }

    /** Krok 4 — ověření podpisu a vydání tokenu. */
    public function login(Request $request): JsonResponse
    {
        $ulozene = $request->session()->pull('galerie.webauthn');

        if (! is_array($ulozene) || ($ulozene['do'] ?? 0) < now()->getTimestamp()) {
            throw ValidationException::withMessages(['challenge' => 'Ověření vypršelo — zkuste to znovu.']);
        }

        /** @var PublicKeyCredentialRequestOptions $volby */
        $volby = $this->serializer()->deserialize($ulozene['volby'], PublicKeyCredentialRequestOptions::class, 'json');

        $user = $ulozene['user'] ? User::find($ulozene['user']) : null;
        $poverení = $this->nactiPoverni($request);
        $odpoved = $poverení->response;

        if (! $user || ! $odpoved instanceof AuthenticatorAssertionResponse) {
            throw ValidationException::withMessages(['response' => 'Otisk se nepodařilo ověřit — zadejte kód.']);
        }

        $zaznam = WebauthnCredential::where('user_id', $user->id)
            ->where('credential_id', $this->doB64($poverení->rawId))
            ->first();

        if ($zaznam === null) {
            throw ValidationException::withMessages(['response' => 'Tenhle klíč tu registrovaný není — zadejte kód.']);
        }

        /*
         * Uvnitř `check()` proběhne i kontrola počítadla podpisů: nižší nebo
         * stejné číslo než minule znamená, že klíč někdo zkopíroval a přehrává
         * jeho starší otisk. Knihovna to řeší sama (`ThrowExceptionIfInvalid`)
         * včetně výjimky pro autentikátory, které počítadlo nevedou a hlásí
         * trvale nulu — vlastní kontrola za tímhle řádkem by byla mrtvý kód.
         * Že to platí, hlídá test `test_klesajici_pocitadlo_neprojde`.
         */
        try {
            $novy = AuthenticatorAssertionResponseValidator::create(
                $this->obrad()->requestCeremony(),
            )->check(
                $this->zaznamNaPoverni($zaznam, $poverení->rawId),
                $odpoved,
                $volby,
                config('galerie.rp_id'),
                (string) $user->id,
            );
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['response' => 'Otisk se nepodařilo ověřit — zadejte kód.']);
        }

        $zaznam->update(['sign_count' => $novy->counter, 'last_used_at' => now()]);

        // Jedno zařízení = jeden token, stejně jako u přihlášení heslem.
        $jmeno = $zaznam->label ?: 'telefon';
        $user->tokens()->where('name', $jmeno)->delete();

        return response()->json([
            'token' => $user->createToken($jmeno)->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    // ——— pomocné ———

    /**
     * Sada kontrol, kterými odpověď zařízení projde.
     *
     * Bez seznamu originů knihovna trvá na HTTPS. Vývojový server běží na
     * `http://localhost:8765`, takže by se otisk lokálně nedal ani vyzkoušet;
     * s prázdným seznamem zůstává přísná kontrola v platnosti.
     */
    private function obrad(): CeremonyStepManagerFactory
    {
        $tovarna = new CeremonyStepManagerFactory;
        $originy = array_values(array_filter((array) config('galerie.rp_origins')));

        if ($originy !== []) {
            $tovarna->setAllowedOrigins($originy);
        }

        return $tovarna;
    }

    private function serializer(): SerializerInterface
    {
        $podpora = AttestationStatementSupportManager::create();
        $podpora->add(NoneAttestationStatementSupport::create());

        return (new WebauthnSerializerFactory($podpora))->create();
    }

    /** Volby ven ve tvaru, kterému rozumí `navigator.credentials` v prohlížeči. */
    private function jako(object $options): JsonResponse
    {
        return response()->json(json_decode($this->serializer()->serialize($options, 'json'), true));
    }

    /**
     * Klient posílá vedle pověření i `label` (popisek zařízení). Do knihovny jde
     * jen to, co k pověření patří — ať se cizí klíč nemá kde svézt.
     */
    private function nactiPoverni(Request $request): PublicKeyCredential
    {
        try {
            return $this->serializer()->deserialize(
                json_encode($request->only(['id', 'rawId', 'type', 'response', 'clientExtensionResults'])),
                PublicKeyCredential::class,
                'json',
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['response' => 'Odpověď zařízení se nepodařilo přečíst.']);
        }
    }

    /** Uložený klíč zpátky do tvaru, se kterým knihovna umí ověřovat. */
    private function zaznamNaPoverni(WebauthnCredential $zaznam, string $rawId): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: $rawId,
            type: 'public-key',
            transports: $zaznam->transports ?? ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString($zaznam->aaguid ?: '00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: $this->zB64($zaznam->public_key),
            userHandle: (string) $zaznam->user_id,
            counter: $zaznam->sign_count,
        );
    }

    private function klicRegistrace(int $userId): string
    {
        return 'galerie:webauthn:reg:'.$userId;
    }

    private function platnost(): int
    {
        return (int) config('galerie.webauthn_challenge_ttl', 120);
    }

    private function doB64(string $syrove): string
    {
        return rtrim(strtr(base64_encode($syrove), '+/', '-_'), '=');
    }

    private function zB64(string $hodnota): string
    {
        return (string) base64_decode(strtr($hodnota, '-_', '+/'), true);
    }
}
