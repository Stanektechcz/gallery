/**
 * Turning a getUserMedia failure into something the person can act on.
 *
 * Most of these never reach a permission prompt at all — a machine with no microphone
 * throws NotFoundError immediately — so reporting every failure as "check your
 * permissions" sends people to a browser setting that was never the problem.
 */
export function describeMicrophoneError(error: unknown): string {
    const name = (error as { name?: string } | null)?.name ?? '';

    switch (name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
            return 'Přístup k mikrofonu je zamítnutý. Povolte ho v adresním řádku prohlížeče (ikona vlevo od adresy) a zkuste to znovu.';

        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return 'Prohlížeč nenašel žádný mikrofon. Připojte ho, nebo ho v nastavení zvuku systému nastavte jako vstupní zařízení.';

        case 'NotReadableError':
        case 'TrackStartError':
            return 'Mikrofon právě používá jiná aplikace. Zavřete ji (hovor, schůzku, nahrávání) a zkuste to znovu.';

        case 'OverconstrainedError':
            return 'Mikrofon nezvládá požadované nastavení záznamu. Zkuste vybrat jiné vstupní zařízení.';

        case 'SecurityError':
            return 'Nahrávání je povolené jen na zabezpečeném spojení. Otevřete stránku přes https.';

        case 'AbortError':
            return 'Spuštění mikrofonu se přerušilo. Zkuste to prosím znovu.';

        default:
            // Never silently blame permissions: show what actually happened.
            return `Mikrofon se nepodařilo spustit${name ? ` (${name})` : ''}. Zkontrolujte, že je připojený a že ho nepoužívá jiná aplikace.`;
    }
}

/** Why recording is impossible before the person even presses the button, if it is. */
export function recordingUnavailableReason(): string | null {
    if (typeof window === 'undefined') return null;

    // navigator.mediaDevices is simply absent outside a secure context, which otherwise
    // looks identical to an old browser and sends people chasing the wrong fix.
    if (!window.isSecureContext) {
        return 'Nahrávání zvuku vyžaduje zabezpečené spojení. Otevřete stránku přes https.';
    }

    if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
        return 'Tenhle prohlížeč nahrávání zvuku nepodporuje. Zkuste Chrome, Edge nebo Safari v aktuální verzi.';
    }

    return null;
}

/**
 * Names the upload after what the browser actually produced.
 *
 * The extension is cosmetic for validation, which sniffs the bytes, but it decides what
 * the file is called when someone downloads it later — and Safari records mp4 where the
 * others record webm, so a fixed ".webm" would mislabel every recording made on a Mac.
 */
export function recordingFilename(base: string, blob: Blob): string {
    const type = blob.type;
    const extension = type.includes('mp4') || type.includes('m4a') ? 'm4a'
        : type.includes('ogg') ? 'ogg'
        : type.includes('mpeg') ? 'mp3'
        : type.includes('wav') ? 'wav'
        : 'webm';

    return `${base}.${extension}`;
}
