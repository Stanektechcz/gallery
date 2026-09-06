import { Head } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, Download, ImagePlus, MessageCircle, Upload } from 'lucide-react';
import { useState } from 'react';

type Vzkaz = { id: string; jmeno: string; text: string; kdy: string; muj?: boolean };

/**
 * Stránka, kterou uvidí ten, komu dvojice poslala odkaz.
 *
 * Vzkazy: `guest_comments` a cesta pro zápis existovaly, ale odsud na ně nevedl
 * žádný ovládací prvek — babička, které dvojice pošle fotky, neměla jak nechat
 * vzkaz. A když ho konečně napsala, nikde ho neuviděla: stránka je nevracela,
 * takže to vypadalo, jako by se nic nestalo.
 */
export default function SharedShow({ link, media, comments = [] }: { link: any; media: any[]; comments?: Vzkaz[] }) {
    const [files, setFiles] = useState<File[]>([]);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);

    const [vzkazy, setVzkazy] = useState<Vzkaz[]>(comments);
    const [jmeno, setJmeno] = useState('');
    const [text, setText] = useState('');
    const [posilam, setPosilam] = useState(false);
    const [chyba, setChyba] = useState<string | null>(null);

    const send = async () => {
        if (!files.length) return;
        setBusy(true);
        const body = new FormData();
        files.forEach(f => body.append('files[]', f));
        body.append('contributor_name', name);
        await axios.post(`/s/${link.token}/upload`, body);
        setDone(true);
        setFiles([]);
        setBusy(false);
    };

    const posli = async () => {
        if (!jmeno.trim() || !text.trim() || posilam) return;
        setPosilam(true);
        setChyba(null);
        try {
            const body = new FormData();
            body.append('jmeno', jmeno.trim());
            body.append('text', text.trim());
            await axios.post(`/s/${link.token}/vzkaz`, body);
            // Vzkaz zůstane na stránce. Host se tím dozví, že dorazil —
            // a při dalším otevření ho najde mezi ostatními.
            setVzkazy([
                ...vzkazy,
                { id: `novy-${vzkazy.length}`, jmeno: jmeno.trim(), text: text.trim(), kdy: 'právě teď', muj: true },
            ]);
            setText('');
        } catch (e: any) {
            setChyba(e?.response?.data?.zprava ?? 'Vzkaz se nepodařilo odeslat. Zkuste to prosím znovu.');
        }
        setPosilam(false);
    };

    return (
        <main className="min-h-screen bg-[#0d0f14] px-3 py-6 text-[var(--color-text-primary)] sm:px-6">
            <Head title={link.name || 'Sdílená galerie'} />
            <div className="w-full">
                <header className="mb-6">
                    <p className="text-xs uppercase tracking-widest text-violet-400">Sdílená galerie</p>
                    <h1 className="mt-1 text-2xl font-bold">{link.name || 'Vzpomínky pro vás'}</h1>
                </header>

                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {media.map(item => {
                        const v = item.variants?.find((x: any) => x.type === 'thumbnail') ?? item.variants?.find((x: any) => x.type === 'original');
                        return (
                            <div key={item.uuid} className="group relative aspect-square overflow-hidden rounded-2xl bg-[var(--color-surface-muted)]">
                                {v && <img src={v.url} alt="" className="h-full w-full object-cover" />}
                                {link.allow_download && (
                                    <a href={`/s/${link.token}/media/${item.uuid}/download`} className="absolute bottom-2 right-2 flex h-9 w-9 items-center justify-center rounded-full bg-black/60">
                                        <Download size={15} />
                                    </a>
                                )}
                            </div>
                        );
                    })}
                </div>

                {link.allow_comments && (
                    <section className="mx-auto mt-8 max-w-xl rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-5">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <MessageCircle size={18} />
                            Vzkaz k fotkám
                        </h2>
                        <p className="mt-1 text-xs text-[var(--color-text-primary)]/50">Napište pár slov — uvidí to ten, kdo vám odkaz poslal.</p>

                        {vzkazy.length > 0 && (
                            <ul className="mt-4 flex flex-col gap-3">
                                {vzkazy.map(v => (
                                    <li key={v.id} className="rounded-2xl bg-black/20 p-3 text-sm">
                                        <div className="flex items-baseline gap-2">
                                            <span className="font-medium">{v.jmeno}</span>
                                            <span className="text-xs text-[var(--color-text-primary)]/40">{v.kdy}</span>
                                            {v.muj && <span className="ml-auto text-xs text-green-400">odesláno</span>}
                                        </div>
                                        <p className="mt-1 whitespace-pre-line text-[var(--color-text-primary)]/80">{v.text}</p>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <input
                            value={jmeno}
                            onChange={e => setJmeno(e.target.value)}
                            placeholder="Vaše jméno"
                            maxLength={80}
                            className="mt-4 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm"
                        />
                        <textarea
                            value={text}
                            onChange={e => setText(e.target.value)}
                            placeholder="Co byste jim chtěli vzkázat?"
                            maxLength={2000}
                            rows={3}
                            className="mt-3 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-3 text-sm"
                        />
                        {chyba && <div className="mt-3 rounded-xl bg-red-500/10 p-3 text-sm text-red-300">{chyba}</div>}
                        <button
                            onClick={posli}
                            disabled={posilam || !jmeno.trim() || !text.trim()}
                            className="mt-3 min-h-12 w-full rounded-xl bg-violet-600 font-medium disabled:opacity-40"
                        >
                            {posilam ? 'Odesílám…' : 'Odeslat vzkaz'}
                        </button>
                    </section>
                )}

                {link.allow_guest_upload && (
                    <section className="mx-auto mt-8 max-w-xl rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-5">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <ImagePlus size={18} />
                            Přidat vlastní fotky
                        </h2>
                        <p className="mt-1 text-xs text-[var(--color-text-primary)]/50">Soubory se nejprve odešlou vlastníkovi ke schválení.</p>
                        {done ? (
                            <div className="mt-4 flex items-center gap-2 rounded-xl bg-green-500/10 p-3 text-sm text-green-400">
                                <CheckCircle2 size={17} />
                                Odesláno ke schválení.
                            </div>
                        ) : (
                            <>
                                <input
                                    value={name}
                                    onChange={e => setName(e.target.value)}
                                    placeholder="Vaše jméno (volitelné)"
                                    className="mt-4 min-h-11 w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] px-3 text-sm"
                                />
                                <label className="mt-3 flex min-h-28 cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-[var(--color-border)] text-sm text-[var(--color-text-primary)]/60">
                                    <Upload className="mb-2" />
                                    {files.length ? `${files.length} souborů vybráno` : 'Vybrat fotky a videa'}
                                    <input type="file" multiple accept="image/*,video/*" className="hidden" onChange={e => setFiles(Array.from(e.target.files ?? []))} />
                                </label>
                                <button onClick={send} disabled={busy || !files.length} className="mt-3 min-h-12 w-full rounded-xl bg-violet-600 font-medium disabled:opacity-40">
                                    {busy ? 'Odesílám…' : 'Odeslat ke schválení'}
                                </button>
                            </>
                        )}
                    </section>
                )}
            </div>
        </main>
    );
}
