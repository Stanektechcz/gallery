import axios from 'axios';

/**
 * Jedno stažení pro víc míst, která chtějí totéž.
 *
 * Vzniklo ze dvou nálezů na jedné stránce. Zvonek s oznámeními je v rozvržení dvakrát —
 * jednou v postranním panelu pro monitor, jednou v hlavičce pro telefon, a CSS vždycky
 * jeden schová. Jenže schovaný neznamená nečinný: obě kopie si oznámení stáhly a obě se
 * pak doptávaly každou minutu, na každé stránce a po celou dobu, co byla aplikace
 * otevřená. Druhý případ je balicí seznam, kde dva sousední panely potřebují tentýž
 * seznam lidí a každý si o něj řekne sám.
 *
 * Řeší se to tady, ne přestavbou těch míst: schovaná kopie zvonku musí zůstat vykreslená,
 * jinak by se při otočení telefonu neměla odkud vzít, a dva panely vedle sebe mají právo
 * na tatáž data, aniž by o sobě věděly.
 *
 * Spojují se dvě věci. Rozdělaný dotaz se sdílí — kdo si řekne o totéž, dostane tentýž
 * slib místo druhého spojení. A chvilku po doběhnutí se odpověď ještě půjčí, protože dvě
 * odpočítávání spuštěná pár milisekund po sobě se časem rozejdou a na sdílení rozdělaného
 * dotazu by pak nedosáhla.
 *
 * Půjčování má mez, kterou je potřeba respektovat: po zápisu se data načítají znovu právě
 * proto, že se změnila, a půjčená odpověď by byla ta stará. Proto se obnovuje s
 * `cerstve: true` všude, kde se načítá po nějaké akci — automatické cesty (první
 * vykreslení, odpočítávání) půjčovat můžou, doptání po zápisu ne.
 */

/** Jak dlouho se hotová odpověď ještě půjčí dalšímu, kdo si o totéž řekne. */
const PUJCKA_MS = 3000;

type Zaznam = { kdy: number; data: unknown };

const rozdelane = new Map<string, Promise<unknown>>();
const cerstvaData = new Map<string, Zaznam>();

/**
 * @param klic  Co se stahuje. Musí zahrnovat i parametry — jiný filtr je jiná odpověď.
 */
export async function sdilenyGet<T>(
    klic: string,
    adresa: string,
    nastaveni?: { params?: Record<string, unknown>; cerstve?: boolean },
): Promise<T> {
    if (nastaveni?.cerstve) {
        cerstvaData.delete(klic);
        rozdelane.delete(klic);
    } else {
        const ulozene = cerstvaData.get(klic);

        if (ulozene && Date.now() - ulozene.kdy < PUJCKA_MS) {
            return ulozene.data as T;
        }

        const bezi = rozdelane.get(klic);

        if (bezi) {
            return bezi as Promise<T>;
        }
    }

    const slib = axios
        .get(adresa, { params: nastaveni?.params })
        .then((odpoved) => {
            cerstvaData.set(klic, { kdy: Date.now(), data: odpoved.data });

            return odpoved.data;
        })
        .finally(() => {
            rozdelane.delete(klic);
        });

    rozdelane.set(klic, slib);

    return slib as Promise<T>;
}
