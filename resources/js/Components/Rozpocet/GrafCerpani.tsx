import { penize } from '@/lib/penize';
import { useId, useState } from 'react';

export type BodCerpani = { date: string; spent: number | null; plan: number; today: boolean };

export type Cerpani = { currency: string; limit: number; days: number; points: BodCerpani[] };

/**
 * Průběh čerpání proti rovnoměrnému tempu.
 *
 * Dvě křivky vedle sebe odpovídají na jedinou otázku, kvůli které se do rozpočtu
 * člověk dívá: **vyjdeme s tím?** Skutečnost nad plánem znamená, že ne — a čím dřív
 * se rozejdou, tím hůř. Ze sloupečků útrat po dnech se tohle vyčíst nedá; tam je vidět
 * jen to, že v úterý bylo draho.
 *
 * Plán je rovná čára od nuly k celé částce. Není to předpověď, ale měřítko: takhle by
 * se peníze utrácely, kdyby se rozdělily na dny rovným dílem.
 *
 * Skutečnost končí dneškem. Táhnout ji dál by znamenalo tvrdit, že se v budoucnu
 * neutratí nic — což je ta nejhorší možná lež v rozpočtu, protože vypadá optimisticky.
 *
 * Kreslí se přes `viewBox` a `preserveAspectRatio="none"`, takže se roztáhne do každé
 * šířky bez přepočítávání v JavaScriptu. Šířka tahů se tím zkosí, proto jsou popisky
 * a body mimo škálovanou plochu.
 */
export default function GrafCerpani({ data, vyska = 132 }: { data: Cerpani; vyska?: number }) {
    const [vybrany, setVybrany] = useState<number | null>(null);
    const id = useId();

    const body = data.points;

    if (body.length < 2 || data.limit <= 0) {
        return (
            <p className="rounded-xl border border-dashed border-[var(--color-border)] p-4 text-center text-xs text-[var(--color-text-secondary)]">
                Průběh se ukáže, až bude z čeho kreslit — potřebuje aspoň dva dny a zadaný rozpočet.
            </p>
        );
    }

    // Osa Y sahá k vyššímu ze dvou: k rozpočtu, nebo k tomu, co se utratilo. Kdyby
    // končila u rozpočtu, přesah by se uřízl a graf by tvrdil, že se akorát došlo na
    // hranici — přesně v okamžiku, kdy je nejdůležitější vidět, o kolik se přestřelilo.
    const strop = Math.max(data.limit, ...body.map(b => b.spent ?? 0)) * 1.04;
    const x = (i: number) => (i / (body.length - 1)) * 100;
    const y = (v: number) => 100 - (v / strop) * 100;

    const cara = (hodnota: (b: BodCerpani) => number | null) => {
        const kusy: string[] = [];
        let zapsano = false;

        body.forEach((b, i) => {
            const v = hodnota(b);

            if (v === null) return;

            kusy.push(`${zapsano ? 'L' : 'M'}${x(i).toFixed(2)} ${y(v).toFixed(2)}`);
            zapsano = true;
        });

        return kusy.join(' ');
    };

    const posledniSkutecny = body.reduce((p, b, i) => (b.spent !== null ? i : p), 0);
    const dnesniIndex = body.findIndex(b => b.today);
    const konec = body[posledniSkutecny];
    const prekroceno = (konec?.spent ?? 0) > konec.plan;

    // Období, které ještě nezačalo, se nedá pochválit za to, že drží tempo. Nedrží
    // nic — jen se v něm zatím nežije.
    const nezacalo = dnesniIndex < 0 && (konec?.spent ?? 0) === 0;

    const aktivni = vybrany !== null ? body[vybrany] : null;

    return (
        <figure className="m-0">
            <figcaption className="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <span className="text-xs font-medium text-[var(--color-text-primary)]">Průběh čerpání</span>
                <span className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] text-[var(--color-text-secondary)]">
                    <span className="flex items-center gap-1">
                        <span className="h-0.5 w-3 rounded-full"
                            style={{ background: prekroceno ? 'var(--fin-vydaj)' : 'var(--fin-prijem)' }}/>
                        skutečnost
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="h-0.5 w-3 rounded-full bg-[var(--color-text-secondary)] opacity-60"/>
                        rovnoměrné tempo
                    </span>
                </span>
            </figcaption>

            <div className="relative">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
                    aria-label={`Průběh čerpání: utraceno ${penize(konec?.spent ?? 0, data.currency)} z ${penize(data.limit, data.currency)}`}
                    style={{ height: vyska }} className="w-full">
                    <defs>
                        <linearGradient id={`${id}-vypln`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={prekroceno ? 'var(--fin-vydaj)' : 'var(--fin-prijem)'} stopOpacity="0.22"/>
                            <stop offset="100%" stopColor={prekroceno ? 'var(--fin-vydaj)' : 'var(--fin-prijem)'} stopOpacity="0"/>
                        </linearGradient>
                    </defs>

                    {/* Hranice rozpočtu. Kudy se nemá jít. */}
                    <line x1="0" y1={y(data.limit)} x2="100" y2={y(data.limit)}
                        stroke="var(--color-border)" strokeWidth="0.6" strokeDasharray="2 2" vectorEffect="non-scaling-stroke"/>

                    <path d={`${cara(b => b.spent)} L${x(posledniSkutecny).toFixed(2)} 100 L0 100 Z`}
                        fill={`url(#${id}-vypln)`}/>

                    <path d={cara(b => b.plan)} fill="none" stroke="var(--color-text-secondary)"
                        strokeWidth="1.5" strokeOpacity="0.55" strokeDasharray="3 3"
                        vectorEffect="non-scaling-stroke"/>

                    <path d={cara(b => b.spent)} fill="none"
                        stroke={prekroceno ? 'var(--fin-vydaj)' : 'var(--fin-prijem)'}
                        strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
                        vectorEffect="non-scaling-stroke"/>

                    {dnesniIndex >= 0 && (
                        <line x1={x(dnesniIndex)} y1="0" x2={x(dnesniIndex)} y2="100"
                            stroke="var(--color-text-secondary)" strokeWidth="0.8" strokeOpacity="0.35"
                            vectorEffect="non-scaling-stroke"/>
                    )}
                </svg>

                {/* Značka konce skutečnosti stojí mimo škálovanou plochu, aby zůstala kulatá.
                    V `preserveAspectRatio="none"` by se z kolečka stal ovál. */}
                <span className="pointer-events-none absolute h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-[var(--color-bg-primary)]"
                    style={{
                        left: `${x(posledniSkutecny)}%`,
                        top: `${(y(konec?.spent ?? 0) / 100) * vyska}px`,
                        background: prekroceno ? 'var(--fin-vydaj)' : 'var(--fin-prijem)',
                    }}/>

                {/* Dotyková vrstva: neviditelné plochy nad grafem, ať se dá vybrat den
                    prstem. Čára sama je moc tenká na to, aby se do ní dalo trefit. */}
                <div className="absolute inset-0 flex">
                    {body.map((b, i) => (
                        <button key={b.date} type="button"
                            onClick={() => setVybrany(vybrany === i ? null : i)}
                            aria-label={`${b.date}: ${b.spent === null ? 'zatím nic' : penize(b.spent, data.currency)}`}
                            className="h-full flex-1 focus:outline-none focus-visible:bg-[var(--color-surface-muted)]"/>
                    ))}
                </div>
            </div>

            <p className="mt-1.5 min-h-[2.5rem] text-[11px] leading-relaxed text-[var(--color-text-secondary)]">
                {aktivni ? (
                    <>
                        <strong className="text-[var(--color-text-primary)]">{datumKratce(aktivni.date)}</strong>
                        {' · utraceno '}
                        <strong className="tabular-nums text-[var(--color-text-primary)]">
                            {aktivni.spent === null ? '—' : penize(aktivni.spent, data.currency)}
                        </strong>
                        {aktivni.spent !== null && (
                            <>
                                {', rovnoměrným tempem by bylo '}
                                <span className="tabular-nums">{penize(aktivni.plan, data.currency)}</span>
                            </>
                        )}
                    </>
                ) : nezacalo ? (
                    <>
                        Období ještě nezačalo. Rovnoměrné tempo znamená{' '}
                        <strong className="tabular-nums text-[var(--color-text-primary)]">
                            {penize(data.limit / data.days, data.currency)}
                        </strong>{' '}
                        na den — proti tomu se bude čerpání měřit.
                    </>
                ) : prekroceno ? (
                    <>
                        Utrácí se rychleji než rovnoměrným tempem. K dnešku je pryč{' '}
                        <strong className="tabular-nums text-[var(--color-text-primary)]">
                            {penize(konec?.spent ?? 0, data.currency)}
                        </strong>{' '}
                        místo <span className="tabular-nums">{penize(konec.plan, data.currency)}</span>.
                    </>
                ) : (
                    <>
                        Čerpání drží pod rovnoměrným tempem. K dnešku je pryč{' '}
                        <strong className="tabular-nums text-[var(--color-text-primary)]">
                            {penize(konec?.spent ?? 0, data.currency)}
                        </strong>{' '}
                        z <span className="tabular-nums">{penize(data.limit, data.currency)}</span>.
                    </>
                )}
            </p>
        </figure>
    );
}

/** Datum bez roku — v grafu jednoho období je rok pokaždé stejný. */
function datumKratce(iso: string): string {
    const d = new Date(`${iso}T12:00:00`);

    return `${d.getDate()}. ${d.getMonth() + 1}.`;
}
