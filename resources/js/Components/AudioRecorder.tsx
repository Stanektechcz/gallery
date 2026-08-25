import { minuty } from '@/lib/cestina';
import { describeMicrophoneError, recordingUnavailableReason } from '@/lib/microphone';
import { LoaderCircle, Mic, Square, Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * 'speech' asks the browser for the processing built for calls; 'raw' turns it off.
 *
 * That chain is tuned to keep a talking voice and discard everything else, which is what
 * a voice note wants and exactly what a burp or a fart must not get — the suppressor
 * hears the recording itself as noise and gates it away.
 */
type AudioProfile = 'speech' | 'raw';

const constraints = (profile: AudioProfile): MediaTrackConstraints => ({
    // One channel: a microphone has one capsule, so stereo only doubles the size.
    channelCount: 1,
    sampleRate: 48000,
    echoCancellation: profile === 'speech',
    noiseSuppression: profile === 'speech',
    autoGainControl: profile === 'speech',
});

/**
 * Records audio with MediaRecorder and hands the finished blob to the caller.
 * Shared by the voice-note, burp and fart modules, which store to different endpoints.
 */
export default function AudioRecorder({
    onRecorded, busy, label = 'Nahrát', withTitle = true, maxSeconds = 300, profile = 'speech',
}: {
    onRecorded: (blob: Blob, durationMs: number, title: string) => void | Promise<void>;
    busy: boolean;
    label?: string;
    withTitle?: boolean;
    maxSeconds?: number;
    profile?: AudioProfile;
}) {
    const [unavailable, setUnavailable] = useState<string | null>(null);
    const [recording, setRecording] = useState(false);
    const [seconds, setSeconds] = useState(0);
    const [blob, setBlob] = useState<Blob | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [title, setTitle] = useState('');
    const [error, setError] = useState('');

    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);
    const timer = useRef<number | null>(null);
    const startedAt = useRef(0);

    useEffect(() => { setUnavailable(recordingUnavailableReason()); }, []);

    // Object URLs have to be released or the blob stays in memory.
    useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);
    useEffect(() => () => { if (timer.current) window.clearInterval(timer.current); }, []);

    const stopTracks = () => recorder.current?.stream.getTracks().forEach(track => track.stop());

    const start = async () => {
        setError('');
        let stream: MediaStream | null = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: constraints(profile) });
            // Opus is worth asking for by name: the bare container can fall back to a
            // codec several times larger at the same quality. Safari has neither and
            // uses mp4, so every candidate is offered and the browser takes the first
            // it knows.
            const mime = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg']
                .find(candidate => MediaRecorder.isTypeSupported(candidate));
            const instance = new MediaRecorder(stream, {
                ...(mime ? { mimeType: mime } : {}),
                // The default is around 32 kbit/s, which is where the muffled, watery
                // sound comes from. Opus at 128 is transparent for a single voice.
                audioBitsPerSecond: 128000,
            });
            chunks.current = [];
            instance.ondataavailable = event => { if (event.data.size) chunks.current.push(event.data); };
            instance.onstop = () => {
                const recorded = new Blob(chunks.current, { type: instance.mimeType || 'audio/webm' });
                setBlob(recorded);
                setPreviewUrl(current => { if (current) URL.revokeObjectURL(current); return URL.createObjectURL(recorded); });
                stopTracks();
            };
            recorder.current = instance;
            startedAt.current = Date.now();
            instance.start();
            setRecording(true);
            setSeconds(0);
            timer.current = window.setInterval(() => {
                const elapsed = Math.floor((Date.now() - startedAt.current) / 1000);
                setSeconds(elapsed);
                if (elapsed >= maxSeconds) stop();
            }, 250);
        } catch (reason) {
            // The stream survives a failure to build the recorder, and an orphaned one
            // leaves the browser's recording indicator lit with nothing recording.
            stream?.getTracks().forEach(track => track.stop());
            setRecording(false);
            setError(describeMicrophoneError(reason));
        }
    };

    const stop = () => {
        if (timer.current) { window.clearInterval(timer.current); timer.current = null; }
        if (recorder.current?.state === 'recording') recorder.current.stop();
        setRecording(false);
    };

    const discard = () => {
        setBlob(null);
        setPreviewUrl(current => { if (current) URL.revokeObjectURL(current); return null; });
        setTitle('');
        setSeconds(0);
    };

    const submit = async () => {
        if (!blob) return;
        await onRecorded(blob, seconds * 1000, title);
        discard();
    };

    if (unavailable) {
        return (
            <p className="mt-5 rounded-xl border border-amber-400/25 bg-amber-500/10 p-3 text-xs text-amber-100">
                {unavailable}
            </p>
        );
    }

    return (
        <section className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-bg-card)] p-4">
            {error && <p role="alert" className="mb-3 rounded-lg border border-red-400/25 bg-red-500/10 p-2 text-xs text-red-100">{error}</p>}

            {!blob && (
                <div className="flex flex-wrap items-center gap-3">
                    {!recording ? (
                        <button type="button" onClick={start} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-contrast)]">
                            <Mic size={16} /> {label}
                        </button>
                    ) : (
                        <>
                            <button type="button" onClick={stop} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-red-500 px-4 text-sm font-medium text-white">
                                <Square size={15} /> Zastavit
                            </button>
                            <span className="flex items-center gap-2 text-sm text-red-200">
                                <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-red-400" />
                                {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
                            </span>
                        </>
                    )}
                    {!recording && <span className="text-xs text-[var(--color-text-secondary)]">Maximálně {minuty(Math.round(maxSeconds / 60))}</span>}
                </div>
            )}

            {blob && previewUrl && (
                <div>
                    <p className="text-xs text-[var(--color-text-secondary)]">Poslechněte si nahrávku před uložením</p>
                    <audio controls src={previewUrl} className="mt-2 w-full" />
                    {withTitle && (
                        <input
                            value={title}
                            onChange={event => setTitle(event.target.value)}
                            maxLength={180}
                            placeholder="Název, nepovinné"
                            className="mt-3 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 py-2.5 text-sm text-[var(--color-text-primary)] focus:border-[var(--color-accent)] focus:outline-none"
                        />
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" onClick={submit} disabled={busy} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-emerald-500 px-4 text-sm font-medium text-white disabled:opacity-40">
                            {busy ? <LoaderCircle size={15} className="animate-spin" /> : <Upload size={15} />} Uložit
                        </button>
                        <button type="button" onClick={discard} disabled={busy} className="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-[var(--color-border)] px-4 text-sm text-[var(--color-text-primary)] disabled:opacity-40">
                            <Trash2 size={14} /> Zahodit
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
