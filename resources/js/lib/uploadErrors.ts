/**
 * Proč se soubor nenahrál, řečeno tak, aby s tím šlo něco udělat.
 *
 * Do panelu se vypisovala hláška ze serveru tak, jak přišla. „The GET method is not
 * supported for route api/v1/uploads" je pravdivé a člověku, kterému se nenahrály fotky
 * z dovolené, neřekne vůbec nic — a hlavně neřekne, že stačí se znovu přihlásit.
 *
 * Ta konkrétní hláška vzniká, když vyprší přihlášení: server odpoví přesměrováním,
 * prohlížeč ho podle pravidel HTTP zopakuje jako GET, a ten na nahrávací adrese
 * pochopitelně nic nedělá.
 */
export function explainUploadError(raw: string): string {
    const text = raw.toLowerCase();

    if (text.includes('method is not supported') || text.includes('405') || text.includes('unauthenticated') || text.includes('csrf') || text.includes('419')) {
        return 'Vypršelo přihlášení. Přihlaste se znovu a dejte „Zkusit znovu" — soubor se nahraje od místa, kde skončil.';
    }

    if (text.includes('413') || text.includes('too large') || text.includes('payload')) {
        return 'Soubor server odmítl jako příliš velký. Zkuste ho nahrát samostatně, nebo nám dejte vědět.';
    }

    if (text.includes('402') || text.includes('tarif')) {
        return raw; // Zpráva o vyčerpaném místě je srozumitelná sama o sobě.
    }

    if (text.includes('network') || text.includes('timeout') || text.includes('econnaborted')) {
        return 'Spojení se přerušilo. Jakmile bude síť zpátky, dejte „Zkusit znovu".';
    }

    if (text.includes('403')) {
        return 'K nahrávání do tohoto prostoru nemáte oprávnění.';
    }

    if (text.includes('500') || text.includes('server error')) {
        return 'Chyba na straně serveru. Zkuste to znovu za chvíli; pokud potrvá, napište nám.';
    }

    // Neznámou hlášku raději ukážeme celou, než abychom ji nahradili mlhavým „něco se
    // nepovedlo" — z původního textu se aspoň dá vyjít při hledání příčiny.
    return raw;
}
