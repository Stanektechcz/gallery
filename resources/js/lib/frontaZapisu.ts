/**
 * Fronta zápisů, které čekají na spojení.
 *
 * Maki stojí v obchodě v Německu, kde bývá signál mizerný, a zapíše výdaj. Když
 * odeslání selže, nesmí se ta práce ztratit — jinak se zápis odloží „na potom" a
 * potom už si nikdo nevzpomene, kolik to bylo.
 *
 * **Duplicita se řeší klíčem, ne odhadem.** Klient si vyrobí `client_key` jednou při
 * rozepsání a posílá ho s každým pokusem; server podle něj pozná, že ten zápis už má.
 * Poznávat duplicitu podle částky a času nejde — dva stejné nákupy za den jsou
 * legitimní a modul je jinde výslovně povoluje.
 *
 * Fronta žije v `localStorage`, aby přežila zavření aplikace. `sessionStorage` by
 * stačil jen do zavření karty, což je přesně ten okamžik, kdy telefon v kapse
 * aplikaci uspí.
 */

import axios from 'axios';

const KLIC = 'rozpocet-fronta';

export type CekajiciZapis = {
    /** Klíč, podle kterého server pozná opakované odeslání téhož. */
    client_key: string;
    /** Tělo požadavku tak, jak se má odeslat. */
    telo: Record<string, unknown>;
    /** Krátký popis pro obrazovku — „Potraviny 12,50 €". */
    popis: string;
    kdy: number;
    /** Kolikrát se odeslání nepovedlo. Po několika pokusech se přestane zkoušet samo. */
    pokusy: number;
};

/** Po tolika neúspěších se zápis přestane zkoušet sám a čeká na ruční odeslání. */
const NEJVIC_POKUSU = 5;

let posluchaci: Array<(fronta: CekajiciZapis[]) => void> = [];

export function nactiFrontu(): CekajiciZapis[] {
    try {
        const ulozene = window.localStorage.getItem(KLIC);

        return ulozene ? JSON.parse(ulozene) : [];
    } catch {
        // Rozbitý obsah je horší než žádný — kdyby se sem dostalo něco, co nejde
        // přečíst, fronta by se zasekla napořád a nešlo by ji vyprázdnit.
        return [];
    }
}

function ulozFrontu(fronta: CekajiciZapis[]): void {
    try {
        window.localStorage.setItem(KLIC, JSON.stringify(fronta));
    } catch {
        // Plné úložiště. Zápis se tím ztratí, ale aplikace nespadne — a volající
        // dostal chybu už z neúspěšného odeslání.
    }

    posluchaci.forEach(p => p(fronta));
}

export function sledujFrontu(posluchac: (fronta: CekajiciZapis[]) => void): () => void {
    posluchaci.push(posluchac);
    posluchac(nactiFrontu());

    return () => { posluchaci = posluchaci.filter(p => p !== posluchac); };
}

/** Zařadí zápis do fronty. */
export function zaradit(zapis: Omit<CekajiciZapis, 'kdy' | 'pokusy'>): void {
    ulozFrontu([...nactiFrontu(), { ...zapis, kdy: Date.now(), pokusy: 0 }]);
}

export function odebrat(klic: string): void {
    ulozFrontu(nactiFrontu().filter(z => z.client_key !== klic));
}

/**
 * Zkusí odeslat všechno, co čeká.
 *
 * Postupně, ne najednou. Deset souběžných požadavků na špatném připojení skončí
 * tak, že polovina vyprší a fronta se místo vyprázdnění zamotá.
 *
 * @return počet úspěšně odeslaných
 */
export async function odeslatFrontu(): Promise<number> {
    const fronta = nactiFrontu();

    if (fronta.length === 0) return 0;

    let hotovo = 0;

    for (const zapis of fronta) {
        try {
            // `potvrzeno: true` je tu schválně. Varování se ukázala už při rozepsání
            // a člověk je odklikl; ptát se na ně znovu při odesílání z fronty by
            // znamenalo otázku bez kontextu, na kterou se nedá odpovědět.
            await axios.post('/api/v1/rozpocet/transakce', { ...zapis.telo, potvrzeno: true });
            odebrat(zapis.client_key);
            hotovo++;
        } catch (problem: any) {
            const stav = problem?.response?.status;

            // Chyba v datech se opakováním nespraví. Zápis se zahodí, aby nebrzdil
            // ostatní — s hlášením, že se to nepovedlo.
            if (stav === 422) {
                odebrat(zapis.client_key);
                continue;
            }

            const dalsi = nactiFrontu().map(z =>
                z.client_key === zapis.client_key ? { ...z, pokusy: z.pokusy + 1 } : z);

            ulozFrontu(dalsi);

            // Pořád není spojení — nemá cenu zkoušet zbytek hned teď.
            if (! stav) break;
        }
    }

    return hotovo;
}

/** Zápisy, které se už nezkoušejí odeslat samy. */
export const zaseknute = (fronta: CekajiciZapis[]): CekajiciZapis[] =>
    fronta.filter(z => z.pokusy >= NEJVIC_POKUSU);

/** Vyrobí klíč pro nový zápis. */
export const novyKlic = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `k-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
