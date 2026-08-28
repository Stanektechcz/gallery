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

/**
 * Číslo i s tvarem: `pocet(4, 'osoba', 'osoby', 'osob')` → „4 osoby".
 *
 * Číslo se píše česky, ne tak, jak ho vypíše JavaScript. Šablonový řetězec dělá
 * z 3,8 „3.8" a z tisíce „1000" — desetinná tečka je anglická a tisíce se u nás
 * oddělují mezerou. U celých čísel do tisíce je to jedno, jenže právě proto si toho
 * nikdo nevšimne, dokud se někde neobjeví zlomek nebo velké číslo.
 */
export function pocet(cislo: number, jeden: string, dva: string, pet: string): string {
    return `${cislo.toLocaleString('cs-CZ', { maximumFractionDigits: 1 })} ${tvar(cislo, jeden, dva, pet)}`;
}

/** Nejčastější případ v téhle aplikaci, aby se pořád nepsaly tytéž tři tvary. */
export const dny = (cislo: number) => pocet(cislo, 'den', 'dny', 'dní');
export const polozky = (cislo: number) => pocet(cislo, 'položka', 'položky', 'položek');
export const fotografie = (cislo: number) => pocet(cislo, 'fotografie', 'fotografie', 'fotografií');
export const media = (cislo: number) => pocet(cislo, 'médium', 'média', 'médií');
export const fotky = (cislo: number) => pocet(cislo, 'fotka', 'fotky', 'fotek');
export const minuty = (cislo: number) => pocet(cislo, 'minuta', 'minuty', 'minut');
export const jidla = (cislo: number) => pocet(cislo, 'jídlo', 'jídla', 'jídel');
export const ukoly = (cislo: number) => pocet(cislo, 'úkol', 'úkoly', 'úkolů');
export const pripominky = (cislo: number) => pocet(cislo, 'připomínka', 'připomínky', 'připomínek');
export const serie = (cislo: number) => pocet(cislo, 'série', 'série', 'sérií');
export const recepty = (cislo: number) => pocet(cislo, 'recept', 'recepty', 'receptů');
export const transakce = (cislo: number) => pocet(cislo, 'transakce', 'transakce', 'transakcí');
export const zaznamy = (cislo: number) => pocet(cislo, 'záznam', 'záznamy', 'záznamů');
export const nakupy = (cislo: number) => pocet(cislo, 'nákup', 'nákupy', 'nákupů');
export const ucty = (cislo: number) => pocet(cislo, 'účet', 'účty', 'účtů');
export const podklady = (cislo: number) => pocet(cislo, 'podklad', 'podklady', 'podkladů');
export const limity = (cislo: number) => pocet(cislo, 'limit', 'limity', 'limitů');

/**
 * Tvary, kde se s číslem mění i to, co k němu patří.
 *
 * „5 nových transakcí" má u jedničky tvar „1 nová transakce" — mění se přídavné jméno
 * spolu s podstatným. A u přeskočených duplicit se mění i příčestí: jedna je přeskočena,
 * dvě jsou přeskočeny, pět jich je přeskočeno. Proto je celé spojení tady a ne po kouscích
 * na místě použití, kde by se skloňovala jen půlka.
 */
export const noveTransakce = (cislo: number) => pocet(cislo, 'nová transakce', 'nové transakce', 'nových transakcí');
export const noveUkoly = (cislo: number) => pocet(cislo, 'nový úkol', 'nové úkoly', 'nových úkolů');
export const preskoceneDuplicity = (cislo: number) => pocet(cislo, 'duplicita přeskočena', 'duplicity přeskočeny', 'duplicit přeskočeno');
export const radkyKeKontrole = (cislo: number) => pocet(cislo, 'řádek vyžaduje', 'řádky vyžadují', 'řádků vyžaduje') + ' kontrolu';
