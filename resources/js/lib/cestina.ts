/**
 * Číslo s podstatným jménem ve tvaru, který k němu patří.
 *
 * Čeština má tři tvary — 1 osoba, 2 až 4 osoby, 5 a víc osob — a napsat do šablony
 * natvrdo jeden z nich znamená, že dva ze tří případů budou špatně. „4 osob" nebo
 * „2 fotografií" si přečte každý jako chybu, protože to chyba je.
 *
 * Pravidlo bylo v aplikaci na čtyřech místech zvlášť (třikrát `dny` v jednotlivých
 * stránkách, jednou schované v lastSeen.ts) a osmatřicet dalších míst si tvar lepilo
 * napevno. Tady je jednou.
 */

/**
 * @param jeden  tvar k jedničce — „osoba", „den", „místo"
 * @param dva    tvar ke dvěma až čtyřem — „osoby", „dny", „místa"
 * @param pet    tvar k pěti a výš a k nule — „osob", „dní", „míst"
 */
export function tvar(pocet: number, jeden: string, dva: string, pet: string): string {
    // Desetinné číslo bere v češtině tvar druhého pádu jednotného čísla, což je shodou
    // okolností týž tvar jako pro dva až čtyři: „1,5 porce", „2,5 porce".
    if (! Number.isInteger(pocet)) return dva;

    const n = Math.abs(pocet);

    if (n === 1) return jeden;
    if (n >= 2 && n <= 4) return dva;

    return pet;
}

/** Číslo i s tvarem: `pocet(4, 'osoba', 'osoby', 'osob')` → „4 osoby". */
export function pocet(cislo: number, jeden: string, dva: string, pet: string): string {
    return `${cislo} ${tvar(cislo, jeden, dva, pet)}`;
}

/** Nejčastější případ v téhle aplikaci, aby se pořád nepsaly tytéž tři tvary. */
export const dny = (cislo: number) => pocet(cislo, 'den', 'dny', 'dní');
export const polozky = (cislo: number) => pocet(cislo, 'položka', 'položky', 'položek');
export const fotografie = (cislo: number) => pocet(cislo, 'fotografie', 'fotografie', 'fotografií');
export const media = (cislo: number) => pocet(cislo, 'médium', 'média', 'médií');
