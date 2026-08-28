/**
 * Formátování peněz a dat pro modul Rozpočet.
 *
 * Původní částka je vždycky zdroj pravdy. Korunový přepočet u eurové útraty je
 * informativní a musí tak i vypadat — proto se nikdy nevrací jako obyčejné číslo,
 * ale s vlastní značkou, aby ho nešlo splést se skutečnou částkou.
 */

/** `1 250,50 Kč`, `52,40 €`. */
export const penize = (castka: number, mena: string): string =>
    new Intl.NumberFormat('cs-CZ', {
        style: 'currency',
        currency: mena,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(castka);

/** Bez desetinných míst — pro velké částky v přehledu, kde haléře jen zabírají místo. */
export const penizeKratce = (castka: number, mena: string): string =>
    new Intl.NumberFormat('cs-CZ', {
        style: 'currency',
        currency: mena,
        maximumFractionDigits: Math.abs(castka) >= 1000 ? 0 : 2,
    }).format(castka);

/**
 * Zbývající peníze — zaokrouhlené dolů, nikdy nahoru.
 *
 * Ze 1 187,50 udělá běžné zaokrouhlení „1 188 €" a slíbí tím o půl eura víc, než
 * kolik zbývá. U čísla, podle kterého se rozhoduje, jestli na něco ještě je, je to
 * špatný směr — radši ukázat o korunu míň než o korunu víc.
 */
export const penizeZbyva = (castka: number, mena: string): string => {
    if (Math.abs(castka) < 1000) return penize(castka, mena);

    const dolu = castka >= 0 ? Math.floor(castka) : Math.ceil(castka);

    return new Intl.NumberFormat('cs-CZ', {
        style: 'currency', currency: mena, maximumFractionDigits: 0,
    }).format(dolu);
};

/**
 * Kurz — vždycky na dvě desetinná místa.
 *
 * „24,5 Kč" je matematicky totéž co „24,50", ale u peněz to vypadá jako uříznuté
 * číslo a vedle „24,62" se to nedá porovnat pohledem, protože se rozjede desetinná
 * čárka. Proto se nula dopisuje, i když nic nepřidává.
 */
export const kurz = (hodnota: number): string =>
    hodnota.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Procenta v české podobě.
 *
 * `{cislo} %` vypíše „45.3 %" s anglickou tečkou — JavaScript čísla takhle
 * převádí na text a v česky psané aplikaci to trčí. Celá čísla zůstávají celá,
 * desetinné dostanou čárku a jedno místo; víc než jedno je u procent přesnost,
 * kterou nikdo nepotřebuje.
 */
export const procenta = (hodnota: number): string =>
    `${hodnota.toLocaleString('cs-CZ', { maximumFractionDigits: 1 })} %`;

/** `28. 8. 2026` */
export const datum = (iso: string): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' });

/** `28. srpna` — bez roku, pro seznam v rámci období. */
export const datumKratce = (iso: string): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long' });

/** `pátek` */
export const denVTydnu = (iso: string): string =>
    new Date(`${iso}T12:00:00`).toLocaleDateString('cs-CZ', { weekday: 'long' });

/**
 * Záhlaví dne v seznamu: „Dnes", „Včera", jinak datum i den v týdnu.
 *
 * Datum dnešního dne je informace, kterou člověk zná — „Dnes" mu řekne víc a hned.
 */
export function zahlaviDne(iso: string, dnes = new Date()): string {
    const d = new Date(`${iso}T12:00:00`);
    const rozdil = Math.round((d.getTime() - new Date(dnes.toDateString()).getTime()) / 86400000);

    if (rozdil === 0) return 'Dnes';
    if (rozdil === -1) return 'Včera';

    return `${datumKratce(iso)} · ${denVTydnu(iso)}`;
}

/**
 * Přečte částku z toho, co člověk napsal.
 *
 * Přijímá čárku i tečku. Na české klávesnici je na numerické části čárka, na
 * telefonu bývá tečka — trvat na jednom by znamenalo, že polovina zápisů selže na
 * znaku, který uživatel ani nevybíral.
 *
 * Vrací null místo NaN, aby volající musel prázdno ošetřit a nespočítal s ním.
 */
export function castka(text: string): number | null {
    const ocisteny = text.replace(/\s/g, '').replace(',', '.');

    if (ocisteny === '' || ! /^-?\d*\.?\d*$/.test(ocisteny)) return null;

    const cislo = Number(ocisteny);

    return Number.isFinite(cislo) ? cislo : null;
}

/** Dnešní datum ve tvaru pro `<input type="date">`. */
export const dnesniDatum = (): string => new Date().toISOString().slice(0, 10);

/**
 * Barva a ikona typu záznamu.
 *
 * Barva sama nestačí — spec to říká výslovně a je to i otázka přístupnosti. Proto
 * ke každému typu patří i jméno, které se vždycky vypíše.
 */
export const TYPY_ZAZNAMU = {
    expense: { nazev: 'Výdaj', akuzativ: 'výdaj', barva: 'var(--fin-vydaj)', znamenko: '−' },
    income: { nazev: 'Příjem', akuzativ: 'příjem', barva: 'var(--fin-prijem)', znamenko: '+' },
    transfer: { nazev: 'Převod', akuzativ: 'převod', barva: 'var(--fin-prevod)', znamenko: '' },
    exchange: { nazev: 'Směna', akuzativ: 'směnu', barva: 'var(--fin-smena)', znamenko: '' },
    withdrawal: { nazev: 'Výběr hotovosti', akuzativ: 'výběr', barva: 'var(--fin-prevod)', znamenko: '' },
    deposit: { nazev: 'Vklad hotovosti', akuzativ: 'vklad', barva: 'var(--fin-prevod)', znamenko: '' },
} as const;

export type TypZaznamu = keyof typeof TYPY_ZAZNAMU;
