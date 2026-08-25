import { Children, ReactNode } from 'react';

/**
 * Skupina rovnocenných karet: na telefonu pás, na širokém displeji mřížka.
 *
 * Karty, které jsou si rovné — nabídky na nástěnce, analytické panely ve financích —
 * se na úzkém displeji poskládají pod sebe a stránka naroste o stovky bodů. Pět karet
 * na nástěnce stálo tisíc bodů, tedy tři obrazovky jenom jich.
 *
 * Protože mezi nimi není pořadí ani postup, dá se místo scrollování dolů listovat do
 * strany. Karta zabírá kus pod sto procent šířky schválně: z té další kouká okraj a
 * teprve to dá poznat, že pás pokračuje. Bez toho vypadá strip jako jedna karta a
 * zbytek nikdo nenajde.
 *
 * Záporný okraj a stejně velké odsazení vrací pás na kraj obrazovky, aby karty
 * odjížděly za hranu stránky a ne do prázdného pruhu vedle textu.
 */
export default function CardStrip({
    columns = 2,
    from = 'md',
    className = '',
    children,
}: {
    /** Kolik sloupců na širokém displeji. */
    columns?: 2 | 3;
    /** Od které šířky se z pásu stane mřížka. */
    from?: 'md' | 'lg';
    className?: string;
    children: ReactNode;
}) {
    const karty = Children.toArray(children).filter(Boolean);

    if (karty.length === 0) return null;

    // Jedna karta pás nepotřebuje — není kam listovat a záporné okraje by ji jen
    // zbytečně vytáhly přes okraj stránky.
    if (karty.length === 1) return <div className={className}>{karty}</div>;

    // Třídy se píšou celé. Tailwind hledá jejich jména v textu souboru, takže
    // `${from}:grid-cols-${columns}` by ve výsledném CSS nevzniklo.
    const mrizka = from === 'lg'
        ? (columns === 3 ? 'lg:grid lg:grid-cols-3' : 'lg:grid lg:grid-cols-2')
        : (columns === 3 ? 'md:grid md:grid-cols-3' : 'md:grid md:grid-cols-2');

    const zpet = from === 'lg'
        ? 'lg:mx-0 lg:overflow-visible lg:px-0 lg:pb-0 lg:[&>*]:w-auto'
        : 'md:mx-0 md:overflow-visible md:px-0 md:pb-0 md:[&>*]:w-auto';

    return (
        <div className={`-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 [&>*]:w-[85%] [&>*]:shrink-0 [&>*]:snap-start ${mrizka} ${zpet} ${className}`}>
            {karty}
        </div>
    );
}
