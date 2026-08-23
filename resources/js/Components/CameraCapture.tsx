import { cameraPermissionState, cameraUnavailableReason, captureFilename, describeCameraError, grabFrame, hasMultipleCameras, isInstalledApp } from '@/lib/camera';
import { uploadManager } from '@/lib/uploadManager';
import { Camera, Check, RefreshCw, RotateCcw, SwitchCamera, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Taking a photograph without leaving the gallery.
 *
 * The point is the shortest path from "look at that" to a picture that is already in
 * the archive: no camera app, no gallery picker, no share sheet, no wondering whether
 * it uploaded. What is shot here goes straight into the same upload queue as everything
 * else, so it resumes after a dropped connection and skips duplicates like any other file.
 *
 * A review step sits between the shutter and the queue on purpose. A camera that saves
 * instantly fills somebody's gallery with thumbs and ceilings, and deleting those later
 * is far more work than glancing at one now.
 */

interface Props {
    albumId: number | null;
    onClose: () => void;
    /** Told what was taken, so a page can refresh itself without polling. */
    onCaptured?: (count: number) => void;
}

type Facing = 'user' | 'environment';

export default function CameraCapture({ albumId, onClose, onCaptured }: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const fallbackRef = useRef<HTMLInputElement>(null);

    const [facing, setFacing] = useState<Facing>('environment');
    /** Asked once — it cannot change while the camera is open. */
    const [installed] = useState(isInstalledApp);
    const [canSwitch, setCanSwitch] = useState(false);
    const [error, setError] = useState('');
    const [starting, setStarting] = useState(true);
    /** Held back for a look before it is queued. */
    const [shot, setShot] = useState<{ blob: Blob; url: string } | null>(null);
    const [saved, setSaved] = useState(0);

    const stop = useCallback(() => {
        streamRef.current?.getTracks().forEach(track => track.stop());
        streamRef.current = null;
    }, []);

    const start = useCallback(async (want: Facing) => {
        const blocked = cameraUnavailableReason();
        if (blocked) { setError(blocked); setStarting(false); return; }

        // Asked before trying. A camera already refused for this site will never raise a
        // dialog again, so calling getUserMedia only produces a "Spouštím fotoaparát…"
        // that dies a second later — and inside the installed app there is no address bar
        // to go and undo it in. Better to say so at once and offer the way that works.
        if (await cameraPermissionState() === 'denied') {
            setError(describeCameraError({ name: 'NotAllowedError' }));
            setStarting(false);

            return;
        }

        setStarting(true);
        setError('');
        stop();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                // Asked for, not demanded: `ideal` lets a webcam that cannot do 1920 give
                // what it has, where an exact constraint would fail outright.
                video: { facingMode: { ideal: want }, width: { ideal: 1920 }, height: { ideal: 1080 } },
                audio: false,
            });

            streamRef.current = stream;

            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play().catch(() => undefined);
            }

            setCanSwitch(await hasMultipleCameras());
        } catch (problem) {
            setError(describeCameraError(problem));
        } finally {
            setStarting(false);
        }
    }, [stop]);

    useEffect(() => {
        void start(facing);

        return stop;
    }, [facing, start, stop]);

    // A held preview is an object URL; letting it go when it is replaced or the camera
    // closes keeps a long session from leaking a frame at a time.
    useEffect(() => () => { if (shot) URL.revokeObjectURL(shot.url); }, [shot]);

    /**
     * Escape closes it, the way every other full-screen surface here behaves.
     *
     * Without this the only way out was the small X in the corner — and somebody who has
     * just been refused camera permission is looking at a black screen, reaching for the
     * key that has always worked.
     *
     * A held shot is discarded first rather than the whole thing closing: one press to
     * undo the photograph, a second to leave.
     */
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape') return;

            if (shot) { discard(); return; }

            stop();
            onClose();
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    const take = async () => {
        if (! videoRef.current) return;

        const blob = await grabFrame(videoRef.current, facing === 'user');
        if (! blob) { setError('Snímek se nepodařilo pořídit. Zkuste to prosím znovu.'); return; }

        setShot({ blob, url: URL.createObjectURL(blob) });
    };

    const keep = () => {
        if (! shot) return;

        // Stamped with now, because a frame drawn from a canvas carries no EXIF at all
        // and would otherwise land in the archive with no date of its own.
        const file = new File([shot.blob], captureFilename(), { type: 'image/jpeg', lastModified: Date.now() });

        uploadManager.enqueue([file], albumId);
        URL.revokeObjectURL(shot.url);
        setShot(null);
        setSaved(count => count + 1);
        onCaptured?.(1);
    };

    const discard = () => {
        if (shot) URL.revokeObjectURL(shot.url);
        setShot(null);
    };

    /** The phone's own camera app, for when the in-page one cannot run. */
    const useDeviceCamera = () => fallbackRef.current?.click();

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-black/95">
            <header className="flex items-center justify-between gap-3 px-4 py-3">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-white">Vyfotit do galerie</p>
                    <p className="truncate text-xs text-white/60">
                        {saved > 0 ? `Uloženo ${saved} — nahrávání běží na pozadí` : albumId ? 'Uloží se do tohoto alba' : 'Uloží se do archivu fotek'}
                    </p>
                </div>
                <button type="button" onClick={() => { stop(); onClose(); }} title="Zavřít"
                    className="shrink-0 rounded-full bg-white/10 p-2 text-white hover:bg-white/20">
                    <X size={18}/>
                </button>
            </header>

            <div className="relative flex-1 overflow-hidden">
                {/* The selfie camera is mirrored so it behaves like a mirror; the saved
                    frame is un-mirrored again when it is grabbed. */}
                <video ref={videoRef} playsInline muted
                    className={`h-full w-full object-contain ${facing === 'user' ? 'scale-x-[-1]' : ''} ${shot ? 'invisible' : ''}`}/>

                {shot && <img src={shot.url} alt="Pořízený snímek" className="absolute inset-0 h-full w-full object-contain"/>}

                {starting && ! error && (
                    <p className="absolute inset-0 flex items-center justify-center text-sm text-white/70">Spouštím fotoaparát…</p>
                )}

                {error && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center">
                        <p className="max-w-md text-sm text-white/80">{error}</p>
                        <div className="flex flex-wrap justify-center gap-2">
                            <button type="button" onClick={() => void start(facing)}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-4 py-2 text-sm text-white hover:bg-white/20">
                                <RefreshCw size={14}/> Zkusit znovu
                            </button>
                            {/* Always offered when the in-page camera fails: on a phone this
                                works even when getUserMedia does not, and it is the whole
                                difference between taking the picture and giving up. */}
                            <button type="button" onClick={useDeviceCamera}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2 text-sm font-medium text-black hover:opacity-90">
                                <Camera size={14}/> Použít fotoaparát zařízení
                            </button>
                        </div>
                    </div>
                )}
            </div>

            <footer className="flex items-center justify-center gap-6 px-4 pb-8 pt-4">
                {shot ? (
                    <>
                        <button type="button" onClick={discard}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-4 py-3 text-sm text-white hover:bg-white/20">
                            <RotateCcw size={16}/> Znovu
                        </button>
                        <button type="button" onClick={keep}
                            className="inline-flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-medium text-black hover:opacity-90">
                            <Check size={16}/> Uložit do galerie
                        </button>
                    </>
                ) : (
                    <>
                        {/* In the installed app this sits beside the shutter from the
                            start, not only after a refusal. It needs no permission of
                            ours — the phone's own camera app takes the picture and hands
                            it back — so on a phone it is the button that always works. */}
                        {installed && ! error ? (
                            <button type="button" onClick={useDeviceCamera} title="Fotoaparát telefonu"
                                className="rounded-full bg-white/10 p-3 text-white hover:bg-white/20">
                                <Camera size={18}/>
                            </button>
                        ) : <span className="w-11"/>}
                        <button type="button" onClick={take} disabled={Boolean(error) || starting} title="Vyfotit"
                            className="h-16 w-16 rounded-full border-4 border-white/80 bg-white transition-transform active:scale-95 disabled:opacity-30"/>
                        {canSwitch && ! error ? (
                            <button type="button" onClick={() => setFacing(f => (f === 'user' ? 'environment' : 'user'))}
                                title="Přepnout fotoaparát"
                                className="rounded-full bg-white/10 p-3 text-white hover:bg-white/20">
                                <SwitchCamera size={18}/>
                            </button>
                        ) : <span className="w-11"/>}
                    </>
                )}
            </footer>

            {/* `capture` hands the shot straight to the phone's own camera rather than its
                gallery picker. Harmless on a desktop, where it is simply ignored. */}
            <input ref={fallbackRef} type="file" accept="image/*,video/*" capture="environment" className="sr-only"
                onChange={event => {
                    const files = Array.from(event.target.files ?? []);
                    if (files.length) {
                        uploadManager.enqueue(files, albumId);
                        setSaved(count => count + files.length);
                        onCaptured?.(files.length);
                    }
                    (event.target as HTMLInputElement).value = '';
                }}/>
        </div>
    );
}
