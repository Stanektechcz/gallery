/**
 * Turning a camera failure into something the person can act on.
 *
 * Same reasoning as the microphone helper: most of these never reach a permission
 * prompt, so answering every one of them with "check your permissions" sends people to
 * a browser setting that was never the problem. A laptop with the lid shut, a phone
 * whose camera another app is holding, and a page opened over plain http all fail
 * differently and all need different advice.
 */
export function describeCameraError(error: unknown): string {
    const name = (error as { name?: string } | null)?.name ?? '';

    switch (name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
            // Told where the setting actually is for wherever they are standing. In the
            // installed app there is no address bar at all, so the browser advice sent
            // people looking for a button that does not exist on their screen.
            return isInstalledApp()
                ? 'Přístup k fotoaparátu je zamítnutý. Otevřete nastavení aplikace (podržte její ikonu → Informace o aplikaci → Oprávnění → Fotoaparát), nebo použijte fotoaparát telefonu tlačítkem níže — ten funguje vždy.'
                : 'Přístup k fotoaparátu je zamítnutý. Povolte ho v adresním řádku prohlížeče (ikona vlevo od adresy) a zkuste to znovu.';

        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return 'Prohlížeč nenašel žádný fotoaparát. Připojte ho, nebo zkuste stránku otevřít v telefonu.';

        case 'NotReadableError':
        case 'TrackStartError':
            return 'Fotoaparát právě používá jiná aplikace. Zavřete ji (hovor, schůzku, kameru) a zkuste to znovu.';

        case 'OverconstrainedError':
            return 'Tenhle fotoaparát požadované rozlišení nezvládá. Zkusíme to znovu s výchozím nastavením.';

        case 'SecurityError':
            return 'Focení je povolené jen na zabezpečeném spojení. Otevřete stránku přes https.';

        case 'AbortError':
            return 'Spuštění fotoaparátu se přerušilo. Zkuste to prosím znovu.';

        default:
            return `Fotoaparát se nepodařilo spustit${name ? ` (${name})` : ''}. Zkontrolujte, že není zakrytý a že ho nepoužívá jiná aplikace.`;
    }
}

/**
 * Whether we are running inside the installed app rather than a browser tab.
 *
 * It changes what advice is true. The app is a Trusted Web Activity — the site rendered
 * by Chrome inside our own window — so there is no address bar, no padlock icon, and no
 * site-settings menu where somebody could turn the camera back on. Telling them to use
 * one is telling them to do something impossible.
 */
export function isInstalledApp(): boolean {
    if (typeof window === 'undefined') return false;

    return window.matchMedia?.('(display-mode: standalone)').matches === true
        // iOS uses its own flag, and a TWA launched from the home screen reports itself
        // in the referrer rather than in display-mode on some Chrome versions.
        || (navigator as unknown as { standalone?: boolean }).standalone === true
        || document.referrer.startsWith('android-app://');
}

/**
 * What the browser will do if we ask for the camera right now.
 *
 * Worth knowing before asking, because the three answers need three different screens:
 * "prompt" means a tap will raise the system dialog, "granted" means go straight in, and
 * "denied" means no dialog will ever appear again and the only way through is settings —
 * or the device camera, which needs no permission from us at all.
 *
 * Returns null where the Permissions API does not know about the camera, which is still
 * the case in Firefox and older Safari; there the request itself is the only way to find out.
 */
export async function cameraPermissionState(): Promise<PermissionState | null> {
    try {
        const status = await navigator.permissions?.query({ name: 'camera' as PermissionName });

        return status?.state ?? null;
    } catch {
        return null;
    }
}

/** Why focení is impossible before the person even presses the button, if it is. */
export function cameraUnavailableReason(): string | null {
    if (typeof window === 'undefined') return null;

    // mediaDevices is simply absent outside a secure context, which otherwise looks
    // identical to an old browser and sends people chasing the wrong fix.
    if (! window.isSecureContext) {
        return 'Focení vyžaduje zabezpečené spojení. Otevřete stránku přes https.';
    }

    if (! navigator.mediaDevices?.getUserMedia) {
        return 'Tenhle prohlížeč focení ve stránce nepodporuje. Použijte tlačítko pro fotoaparát telefonu.';
    }

    return null;
}

/**
 * Whether the device has more than one camera to switch between.
 *
 * Asked rather than assumed: a phone has two, a desktop usually has one, and offering
 * a "flip" button that does nothing is worse than not offering it. Labels are empty
 * until permission has been granted, so this counts devices rather than reading them.
 */
export async function hasMultipleCameras(): Promise<boolean> {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();

        return devices.filter(device => device.kind === 'videoinput').length > 1;
    } catch {
        return false;
    }
}

/**
 * A photograph from a running video track.
 *
 * Drawn through a canvas rather than taken with ImageCapture: that API is still missing
 * from Safari and Firefox, and the canvas route works everywhere and gives the same
 * pixels the person was looking at.
 *
 * JPEG at 0.92 because these are photographs, not screenshots — PNG would trible the
 * size of a phone-sized frame for no visible gain.
 */
export function grabFrame(video: HTMLVideoElement, mirrored: boolean): Promise<Blob | null> {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const context = canvas.getContext('2d');
    if (! context || ! canvas.width || ! canvas.height) return Promise.resolve(null);

    // The preview of a selfie camera is flipped so it behaves like a mirror. Without
    // undoing it here the saved photograph comes out backwards from what was on screen,
    // with any writing in the shot reversed.
    if (mirrored) {
        context.translate(canvas.width, 0);
        context.scale(-1, 1);
    }

    context.drawImage(video, 0, 0, canvas.width, canvas.height);

    return new Promise(resolve => canvas.toBlob(blob => resolve(blob), 'image/jpeg', 0.92));
}

/** A name that sorts by the moment it was taken, because that is how the archive reads. */
export function captureFilename(now = new Date()): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `MAKI_${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}`
        + `_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}.jpg`;
}
