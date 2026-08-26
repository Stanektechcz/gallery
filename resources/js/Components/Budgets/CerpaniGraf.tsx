import { useMemo, useRef, useState } from 'react';

/**
 * Průběh čerpání rozpočtu proti ideálnímu tempu.
 *
 * Sloupce po měsících řeknou, že srpen vyšel dráž než červenec. Neřeknou to, na co se
 * člověk v cizině ptá průběžně: jsem teď napřed, nebo pozadu? To je vidět teprve na
 * křivce — jak rychle ubývá plán proti tomu, jak rychle ubývat má.
 *
 * Kreslí se zbývající částka, ne utracená. Klesající čára, která má trefit nulu přesně
 * na konci období, je otázka „vyjde to" převedená do obrázku: kdo je nad ideální čarou,
 * má rezervu, kdo pod ní, utrácí rychleji, než plán unese.
 *
 * Tři čáry, tři různé věci, a rozlišené jinak než jen barvou:
 *   — plná modrá je skutečnost, tedy zapsané výdaje,
 *   — čárkovaná modrá je odhad do konce (táž veličina, jen dopočtená),
 *   — tenká šedá je ideální tempo, což není řada dat, ale měřítko.
 * Barva drží veličinu, čárkování drží „tohle se ještě nestalo". Kdyby odhad dostal
 * vlastní barvu, tvářil by se jako druhá veličina, kterou není.
 */

type Bod = { day: number; date: string; left: number | null; pace: number; forecast?: number };

export type Cerpani = {
    currency: string;
    planned_total: number;
    days_total: number;
    today_index: number | null;
    starts_on: string;
    ends_on: string;
    vs_pace: number | null;
    points: Bod[];
};

/**
 * Kreslicí plocha grafu — jen značky, žádný text.
 *
 * Popisky os jsou v HTML kolem grafu, ne uvnitř něj. ViewBox totiž zmenšuje všechno,
 * co je v něm, včetně písma: na telefonu vycházel obrázek v měřítku 0,49, takže
 * desetibodový popisek se vykreslil ve velikosti šesti bodů a nedal se přečíst.
 * Kontrola souřadnic to nemohla odhalit — uvnitř viewBoxu bylo všechno v pořádku.
 *
 * Plocha je bez spodního okraje na datumy právě proto, že datumy jsou venku.
 */
const VYSKA = 190;
const OKRAJ = { nahore: 14, dole: 8, vlevo: 2, vpravo: 2 };

/** Poměr stran. Na širokém displeji plochý průběh, na telefonu vyšší, ať je co číst. */
const SIRKA_SIROKO = 760;
const SIRKA_UZKO = 420;

const castka = (hodnota: number, mena: string) =>
    new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: mena, maximumFractionDigits: 0 }).format(hodnota);

/** „1. 5." — s tečkou, kterou české datum má. */
const denKratce = (iso: string) =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });

export default function CerpaniGraf({ data }: { data: Cerpani }) {
    const [kurzor, setKurzor] = useState<number | null>(null);
    const plocha = useRef<SVGSVGElement>(null);

    // Šířka viewBoxu se volí jednou při prvním vykreslení. Rozhoduje o poměru stran,
    // ne o rozvržení — po otočení telefonu se graf přizpůsobí sám, jen zůstane
    // plošší, což je nejhorší, co se může stát.
    const [SIRKA] = useState(() =>
        typeof window !== 'undefined' && window.matchMedia('(min-width: 640px)').matches ? SIRKA_SIROKO : SIRKA_UZKO);

    const { cesty, meritko, maximum } = useMemo(() => {
        // Osa y sahá od nuly po plán, ale musí unést i přečerpání — bez toho by čára
        // pod nulou zmizela z obrázku právě ve chvíli, kdy je nejdůležitější.
        const hodnoty = data.points.flatMap(b => [b.left, b.forecast].filter((h): h is number => h !== null && h !== undefined));
        const nejnizsi = Math.min(0, ...hodnoty);
        const nejvyssi = Math.max(data.planned_total, ...hodnoty);

        const x = (den: number) => OKRAJ.vlevo + (den / data.days_total) * (SIRKA - OKRAJ.vlevo - OKRAJ.vpravo);
        const y = (h: number) => OKRAJ.nahore + (1 - (h - nejnizsi) / (nejvyssi - nejnizsi || 1)) * (VYSKA - OKRAJ.nahore - OKRAJ.dole);

        const cesta = (vyber: (b: Bod) => number | null | undefined) => {
            const kusy: string[] = [];
            let zacinam = true;

            for (const bod of data.points) {
                const h = vyber(bod);

                if (h === null || h === undefined) { zacinam = true; continue; }

                kusy.push(`${zacinam ? 'M' : 'L'}${x(bod.day).toFixed(1)} ${y(h).toFixed(1)}`);
                zacinam = false;
            }

            return kusy.join(' ');
        };

        const skutecnost = cesta(b => b.left);
        const posledni = data.today_index !== null ? data.points[data.today_index] : null;

        return {
            cesty: {
                skutecnost,
                // Výplň pod skutečností. Uzavírá se k ose, ne k okraji obrázku.
                vypln: skutecnost && posledni
                    ? `${skutecnost} L${x(posledni.day).toFixed(1)} ${y(Math.max(0, nejnizsi)).toFixed(1)} L${x(0).toFixed(1)} ${y(Math.max(0, nejnizsi)).toFixed(1)} Z`
                    : '',
                odhad: cesta(b => b.forecast),
                tempo: cesta(b => b.pace),
            },
            meritko: { x, y, nejnizsi, nejvyssi },
            maximum: nejvyssi,
        };
    }, [data, SIRKA]);

    const dnesni = data.today_index !== null ? data.points[data.today_index] : null;
    const ukazovany = kurzor !== null ? data.points[kurzor] : null;

    const najdiBod = (event: React.MouseEvent<SVGSVGElement> | React.TouchEvent<SVGSVGElement>) => {
        const svg = plocha.current;

        if (! svg) return;

        const ram = svg.getBoundingClientRect();
        const klient = 'touches' in event ? event.touches[0]?.clientX : event.clientX;

        if (klient === undefined) return;

        const pomer = (klient - ram.left) / ram.width;
        const den = Math.round(pomer * SIRKA - OKRAJ.vlevo) / (SIRKA - OKRAJ.vlevo - OKRAJ.vpravo) * data.days_total;

        setKurzor(Math.max(0, Math.min(data.days_total, Math.round(den))));
    };

    return (
        <figure className="m-0">
            {/* Popisky řad jsou nad grafem, ne jen v barvě — tři různé věci se nemají
                rozlišovat jenom odstínem. Klíč nese i tvar čáry, protože právě ten
                nese rozdíl mezi „stalo se" a „dopočteno". */}
            <figcaption className="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-[var(--color-text-secondary)]">
                <span className="flex items-center gap-1.5">
                    <svg width="16" height="8" aria-hidden="true"><line x1="0" y1="4" x2="16" y2="4" stroke="var(--color-graf-rada)" strokeWidth="2" strokeLinecap="round"/></svg>
                    skutečnost
                </span>
                <span className="flex items-center gap-1.5">
                    <svg width="16" height="8" aria-hidden="true"><line x1="0" y1="4" x2="16" y2="4" stroke="var(--color-graf-rada)" strokeWidth="2" strokeDasharray="4 3" strokeLinecap="round"/></svg>
                    odhad do konce
                </span>
                <span className="flex items-center gap-1.5">
                    <svg width="16" height="8" aria-hidden="true"><line x1="0" y1="4" x2="16" y2="4" stroke="var(--color-text-secondary)" strokeWidth="1.5" strokeLinecap="round"/></svg>
                    ideální tempo
                </span>
            </figcaption>

            <div className="relative">
                <svg
                    ref={plocha}
                    viewBox={`0 0 ${SIRKA} ${VYSKA}`}
                    className="h-auto w-full touch-pan-y"
                    role="img"
                    // Věta končí datem, jehož tečka slouží zároveň jako konec věty —
                    // proto se za ním žádná nepřidává.
                    aria-label={`Průběh čerpání rozpočtu za období ${denKratce(data.starts_on)} – ${denKratce(data.ends_on)} ${
                        data.vs_pace !== null
                            ? data.vs_pace >= 0
                                ? `Proti ideálnímu tempu je rezerva ${castka(data.vs_pace, data.currency)}.`
                                : `Proti ideálnímu tempu je utraceno o ${castka(Math.abs(data.vs_pace), data.currency)} víc.`
                            : ''
                    }`}
                    onMouseMove={najdiBod}
                    onMouseLeave={() => setKurzor(null)}
                    onTouchStart={najdiBod}
                    onTouchMove={najdiBod}
                    onTouchEnd={() => setKurzor(null)}
                >
                    {/* Vodicí čáry: plné vlasové, ustupující. Popisek jen u krajních hodnot —
                        číslo u každé čáry přebije data, kvůli kterým se člověk dívá. */}
                    {[0, 0.5, 1].map(podil => {
                        const hodnota = meritko.nejnizsi + (maximum - meritko.nejnizsi) * podil;

                        return (
                            <line key={podil} x1={OKRAJ.vlevo} x2={SIRKA - OKRAJ.vpravo}
                                y1={meritko.y(hodnota)} y2={meritko.y(hodnota)}
                                stroke="var(--color-border)" strokeWidth="1"/>
                        );
                    })}

                    {/* Nula zvlášť, výrazněji: je to hranice, ne dělítko. */}
                    {meritko.nejnizsi < 0 && (
                        <line x1={OKRAJ.vlevo} x2={SIRKA - OKRAJ.vpravo} y1={meritko.y(0)} y2={meritko.y(0)}
                            stroke="var(--color-text-secondary)" strokeWidth="1"/>
                    )}

                    {/* non-scaling-stroke: tah zůstane dvoubodový bez ohledu na to, jak
                        moc se obrázek zmenší. Bez toho je na telefonu poloviční. */}
                    <path d={cesty.tempo} fill="none" stroke="var(--color-text-secondary)" strokeWidth="1.5" strokeLinecap="round" opacity="0.55" vectorEffect="non-scaling-stroke"/>
                    <path d={cesty.vypln} fill="var(--color-graf-rada)" opacity="0.1" stroke="none"/>
                    <path d={cesty.odhad} fill="none" stroke="var(--color-graf-rada)" strokeWidth="2" strokeDasharray="5 4" strokeLinecap="round" strokeLinejoin="round" opacity="0.75" vectorEffect="non-scaling-stroke"/>
                    <path d={cesty.skutecnost} fill="none" stroke="var(--color-graf-rada)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke"/>

                    {/* Dnešek jako koncová značka skutečnosti. Prstenec v barvě plochy,
                        aby zůstala čitelná i tam, kde se kříží s ideálním tempem. */}
                    {dnesni && dnesni.left !== null && (
                        <circle cx={meritko.x(dnesni.day)} cy={meritko.y(dnesni.left)} r="4.5"
                            fill="var(--color-graf-rada)" stroke="var(--color-bg-card)" strokeWidth="2"/>
                    )}

                    {ukazovany && (
                        <line x1={meritko.x(ukazovany.day)} x2={meritko.x(ukazovany.day)}
                            y1={OKRAJ.nahore} y2={VYSKA - OKRAJ.dole}
                            stroke="var(--color-text-secondary)" strokeWidth="1" opacity="0.6"/>
                    )}

                </svg>

                {/* Maximum osy nad grafem v HTML — uvnitř SVG by ho viewBox zmenšil. */}
                <span className="pointer-events-none absolute left-0 top-0 text-[11px] tabular-nums text-[var(--color-text-secondary)]">
                    {castka(maximum, data.currency)}
                </span>

                {/* Bublina se drží nad grafem a nepřekáží ukazateli — proto pointer-events-none. */}
                {ukazovany && (
                    <div className="pointer-events-none absolute left-0 top-0 w-full">
                        <div className="mx-auto w-fit rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-card)] px-2.5 py-1.5 text-[11px] shadow-lg">
                            <p className="font-medium text-[var(--color-text-primary)]">{denKratce(ukazovany.date)}</p>
                            {ukazovany.left !== null
                                ? <p className="tabular-nums text-[var(--color-text-secondary)]">zbývalo {castka(ukazovany.left, data.currency)}</p>
                                : ukazovany.forecast !== undefined
                                    ? <p className="tabular-nums text-[var(--color-text-secondary)]">odhadem {castka(ukazovany.forecast, data.currency)}</p>
                                    : null}
                            <p className="tabular-nums text-[var(--color-text-secondary)]">ideál {castka(ukazovany.pace, data.currency)}</p>
                        </div>
                    </div>
                )}
            </div>

            {/* Osa času pod grafem, taky v HTML. Dnešek uprostřed jen tehdy, když je
                uvnitř období — u dokončeného rozpočtu by ukazoval na pravý okraj. */}
            <div className="mt-1 flex items-baseline justify-between text-[11px] text-[var(--color-text-secondary)]">
                <span>{denKratce(data.starts_on)}</span>
                {dnesni && (
                    <span className="text-[var(--color-text-primary)]">
                        dnes {denKratce(dnesni.date)}
                    </span>
                )}
                <span>{denKratce(data.ends_on)}</span>
            </div>
        </figure>
    );
}
