import { cameraUnavailableReason, captureFilename, describeCameraError, grabFrame, hasMultipleCameras } from '@/lib/camera';
import { uploadManager } from '@/lib/uploadManager';
import { Camera, Check, RotateCcw, SwitchCamera, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Both cameras, one after the other, for the day's shared moment.
 *
 * What you were looking at and your face while you looked at it. Either half alone is
 * an ordinary photograph; together they are the only kind of picture nobody poses for,
 * which is the entire point of asking at a time neither person chose.
 *
 * The front camera is optional throughout. A laptop with one lens, or a phone whose
 * selfie camera is refused, must not lock somebody out of the day entirely.
 */

type Step = 'back' | 'front' | 'review';

interface Shot { blob: Blob; url: string }

interface Props {
    onCancel: () => void;
    /** Given the media uuids once both photographs are in the archive, plus the caption. */
    onReady: (media: { back?: string; front?: string }, caption: string) => Promise<void> | void;
}

export default function DualCapture({ onCancel, onReady }: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const fallbackRef = useRef<HTMLInputElement>(null);

    const [step, setStep] = useState<Step>('back');
    const [back, setBack] = useState<Shot | null>(null);
    const [front, setFront] = useState<Shot | null>(null);
    const [error, setError] = useState('');
    const [starting, setStarting] = useState(true);
    const [canSwitch, setCanSwitch] = useState(false);
    const [sending, setSending] = useState('');
    const [caption, setCaption] = useState('');

    const facing = step === 'front' ? 'user' : 'environment';

    const stop = useCallback(() => {
        streamRef.current?.getTracks().forEach(track => track.stop());
        streamRef.current = null;
    }, []);

    const start = useCallback(async (want: 'user' | 'environment') => {
        const blocked = cameraUnavailableReason();
        if (blocked) { setError(blocked); setStarting(false); return; }

        setStarting(true);
        setError('');
        stop();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
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
        if (step === 'review') { stop(); return; }

        void start(facing);
    }, [step, facing, start, stop]);

    useEffect(() => stop, [stop]);

    /**
     * Escape closes it, as it does everywhere else full-screen in this app.
     *
     * Not while the moment is being sent, though: the photographs are mid-upload and
     * leaving would abandon them halfway.
     */
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || sending) return;

            stop();
            onCancel();
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    // Both previews hold their photograph in memory. `retake` lets them go, but a moment
    // that was sent successfully closed with two still open — small each time, and this
    // screen is opened once a day for as long as the app is installed.
    const shots = useRef<Array<Shot | null>>([]);
    shots.current = [back, front];
    useEffect(() => () => {
        shots.current.forEach(shot => { if (shot) URL.revokeObjectURL(shot.url); });
    }, []);

    const take = async () => {
        if (! videoRef.current) return;

        const blob = await grabFrame(videoRef.current, facing === 'user');
        if (! blob) { setError('Snímek se nepodařilo pořídit. Zkuste to prosím znovu.'); return; }

        const shot = { blob, url: URL.createObjectURL(blob) };

        if (step === 'back') {
            setBack(shot);
            // Straight on to the other camera. Asking "and now the other one?" between
            // the two is enough of a gap for the moment to stop being the same moment.
            setStep(canSwitch ? 'front' : 'review');

            return;
        }

        setFront(shot);
        setStep('review');
    };

    const retake = () => {
        [back, front].forEach(shot => { if (shot) URL.revokeObjectURL(shot.url); });
        setBack(null);
        setFront(null);
        setStep('back');
    };

    /**
     * Puts both photographs through the ordinary upload queue and waits for their uuids.
     *
     * They are real gallery items -- variants, archive, mirror to the space's cloud --
     * rather than something stored beside the gallery that only this screen understands.
     */
    const send = async () => {
        if (! back && ! front) return;

        setSending('Nahrávám fotky…');
        setError('');

        const files: File[] = [];
        const roles: Array<'back' | 'front'> = [];

        if (back) { files.push(new File([back.blob], captureFilename(), { type: 'image/jpeg', lastModified: Date.now() })); roles.push('back'); }
        if (front) { files.push(new File([front.blob], captureFilename(), { type: 'image/jpeg', lastModified: Date.now() })); roles.push('front'); }

        const ids = uploadManager.enqueue(files, null);

        try {
            const uuids = await waitForUploads(ids);
            const media: { back?: string; front?: string } = {};

            roles.forEach((role, index) => {
                const uuid = uuids[index];
                if (uuid) media[role] = uuid;
            });

            if (! media.back && ! media.front) throw new Error('Fotky se nepodařilo nahrát.');

            setSending('Odesílám moment…');
            await onReady(media, caption.trim());
        } catch (problem) {
            setError((problem as Error).message || 'Moment se nepodařilo odeslat.');
            setSending('');
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-black/95">
            <header className="flex items-center justify-between gap-3 px-4 py-3">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-white">
                        {step === 'back' ? 'Vyfoťte, co právě vidíte' : step === 'front' ? 'A teď sebe' : 'Váš moment'}
                    </p>
                    <p className="truncate text-xs text-white/60">
                        {step === 'review' ? 'Zkontrolujte a odešlete' : 'Zadní i přední fotoaparát — nic se nepřipravuje'}
                    </p>
                </div>
                <button type="button" onClick={() => { stop(); onCancel(); }} title="Zavřít"
                    className="shrink-0 rounded-full bg-white/10 p-2 text-white hover:bg-white/20">
                    <X size={18}/>
                </button>
            </header>

            <div className="relative flex-1 overflow-hidden">
                {step !== 'review' && (
                    <video ref={videoRef} playsInline muted
                        className={`h-full w-full object-contain ${facing === 'user' ? 'scale-x-[-1]' : ''}`}/>
                )}

                {step === 'review' && (
                    <div className="flex h-full items-center justify-center p-4">
                        <div className="relative max-h-full">
                            {back && <img src={back.url} alt="Co jste viděli" className="max-h-[60vh] rounded-2xl object-contain"/>}
                            {/* The small inset is the format itself: the face is a corner of
                                the scene, not a picture of its own. */}
                            {front && (
                                <img src={front.url} alt="Vy"
                                    className="absolute left-3 top-3 h-28 w-20 rounded-xl border-2 border-white/80 object-cover shadow-lg sm:h-36 sm:w-28"/>
                            )}
                        </div>
                    </div>
                )}

                {starting && step !== 'review' && ! error && (
                    <p className="absolute inset-0 flex items-center justify-center text-sm text-white/70">Spouštím fotoaparát…</p>
                )}

                {error && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/80 px-6 text-center">
                        <p className="max-w-md text-sm text-white/80">{error}</p>
                        <div className="flex flex-wrap justify-center gap-2">
                            <button type="button" onClick={() => { setError(''); void start(facing); }}
                                className="rounded-xl bg-white/10 px-4 py-2 text-sm text-white hover:bg-white/20">Zkusit znovu</button>
                            <button type="button" onClick={() => fallbackRef.current?.click()}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2 text-sm font-medium text-black hover:opacity-90">
                                <Camera size={14}/> Fotoaparát zařízení
                            </button>
                        </div>
                    </div>
                )}
            </div>

            <footer className="px-4 pb-8 pt-4">
                {step === 'review' ? (
                    <div className="mx-auto max-w-lg space-y-3">
                        <input value={caption} onChange={event => setCaption(event.target.value)}
                            maxLength={500} placeholder="Přidat popisek (nepovinné)"
                            className="w-full rounded-xl border border-white/15 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/40 focus:border-white/40 focus:outline-none"/>
                        <div className="flex gap-2">
                            <button type="button" onClick={retake} disabled={Boolean(sending)}
                                className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-white/10 py-3 text-sm text-white hover:bg-white/20 disabled:opacity-50">
                                <RotateCcw size={16}/> Znovu
                            </button>
                            <button type="button" onClick={() => void send()} disabled={Boolean(sending)}
                                className="inline-flex flex-[2] items-center justify-center gap-2 rounded-xl bg-white py-3 text-sm font-medium text-black hover:opacity-90 disabled:opacity-60">
                                <Check size={16}/>{sending || 'Odeslat moment'}
                            </button>
                        </div>
                    </div>
                ) : (
                    <div className="flex items-center justify-center gap-6">
                        <span className="w-11"/>
                        <button type="button" onClick={take} disabled={Boolean(error) || starting} title="Vyfotit"
                            className="h-16 w-16 rounded-full border-4 border-white/80 bg-white transition-transform active:scale-95 disabled:opacity-30"/>
                        {canSwitch && ! error ? (
                            <button type="button" onClick={() => setStep(step === 'back' ? 'front' : 'back')}
                                title="Přepnout fotoaparát"
                                className="rounded-full bg-white/10 p-3 text-white hover:bg-white/20">
                                <SwitchCamera size={18}/>
                            </button>
                        ) : <span className="w-11"/>}
                    </div>
                )}
            </footer>

            <input ref={fallbackRef} type="file" accept="image/*" capture={step === 'front' ? 'user' : 'environment'} className="sr-only"
                onChange={event => {
                    const file = event.target.files?.[0];
                    if (file) {
                        const shot = { blob: file, url: URL.createObjectURL(file) };
                        if (step === 'back') { setBack(shot); setStep('front'); }
                        else { setFront(shot); setStep('review'); }
                        setError('');
                    }
                    (event.target as HTMLInputElement).value = '';
                }}/>
        </div>
    );
}

/**
 * Waits for queued uploads to reach the archive and returns their media uuids, in order.
 *
 * A duplicate counts as success: the photograph is already there, which is all the
 * moment needs, and refusing it would strand somebody who re-sent the same shot.
 */
function waitForUploads(ids: string[]): Promise<Array<string | undefined>> {
    return new Promise((resolve, reject) => {
        const found = new Map<string, string | undefined>();

        const finish = () => {
            uploadManager.removeEventListener('change', onChange);
            window.clearTimeout(timer);
            resolve(ids.map(id => found.get(id)));
        };

        const onChange = (event: Event) => {
            const uploads = (event as CustomEvent).detail.uploads as Array<{ id: string; status: string; mediaUuid?: string; error?: string }>;

            for (const id of ids) {
                const upload = uploads.find(candidate => candidate.id === id);
                if (! upload) continue;

                if (['done', 'duplicate'].includes(upload.status)) found.set(id, upload.mediaUuid);
                else if (['error', 'cancelled'].includes(upload.status)) found.set(id, undefined);
            }

            if (ids.every(id => found.has(id))) finish();
        };

        // A moment that never resolves is worse than one that reports a problem: the
        // person is standing there holding a phone waiting for a spinner.
        const timer = window.setTimeout(() => {
            uploadManager.removeEventListener('change', onChange);
            reject(new Error('Nahrávání trvá déle než obvykle. Fotky se dokončí na pozadí — zkuste moment odeslat za chvíli.'));
        }, 120_000);

        uploadManager.addEventListener('change', onChange);
    });
}
