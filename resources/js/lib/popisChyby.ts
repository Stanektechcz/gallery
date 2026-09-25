import axios from 'axios';

/**
 * Proč se požadavek nepovedl — česky a s tím, co k tomu řekl server.
 *
 * Webové cesty (ne `api/*`) volané přes axios dřív při chybě vracely přesměrování.
 * Prohlížeč ho u XHR tiše následoval na 200 s HTML a volající ohlásil úspěch. Teď
 * dostanou JSON s kódem chyby a slib skončí zamítnutím — a to je potřeba ukázat, jinak
 * se neúspěch ztratí stejně jako předtím, jen jako nezachycená výjimka v konzoli.
 *
 * Validační hlášky (`errors`) a vlastní hlášky kontrolerů (`abort(422, '…')`,
 * „Trezor je uzamčený.") se ukazují tak, jak přišly. Výchozí hlášky Laravelu
 * u přihlášení, oprávnění a chybějícího záznamu jsou anglické a technické („No query
 * results for model…"), ty nahrazuje česká věta podle stavového kódu.
 *
 * `zaklad` je věta o tom, co se nepovedlo („Místo alba se nepodařilo uložit."); proč,
 * se připojí za ni.
 */

const PODLE_STAVU: Record<number, string> = {
    401: 'Vypršelo přihlášení. Přihlaste se znovu a zkuste to ještě jednou.',
    403: 'K tomu nemáte oprávnění.',
    404: 'Tahle položka už neexistuje — obnovte stránku.',
    413: 'Posílaná data jsou na jeden požadavek příliš velká.',
    // 419 dostane i host na sdíleném odkazu, který se nepřihlašuje — proto ne „přihlaste se".
    419: 'Stránka byla otevřená příliš dlouho. Obnovte ji a zkuste to znovu.',
    429: 'Příliš mnoho pokusů za sebou. Chvíli počkejte a zkuste to znovu.',
};

const BEZ_SPOJENI = 'Spojení se přerušilo, zkuste to znovu.';
const CHYBA_SERVERU = 'Chyba na straně serveru, zkuste to za chvíli.';

export function popisChyby(reason: unknown, zaklad: string): string {
    const detail = proc(reason);

    return detail ? `${zaklad} ${detail}` : zaklad;
}

function proc(reason: unknown): string {
    if (! axios.isAxiosError(reason)) return '';

    const odpoved = reason.response;
    if (! odpoved) return BEZ_SPOJENI;

    const data: unknown = odpoved.data;
    const telo = (data !== null && typeof data === 'object' ? data : {}) as { errors?: unknown; message?: unknown };

    const chyby = validacniChyby(telo.errors);
    if (chyby) return chyby;

    if (PODLE_STAVU[odpoved.status]) return PODLE_STAVU[odpoved.status];
    if (odpoved.status >= 500) return CHYBA_SERVERU;

    return typeof telo.message === 'string' ? telo.message : '';
}

/** `{ pole: ['hláška', …] }` z validace, spojené do jedné věty. */
function validacniChyby(errors: unknown): string {
    if (errors === null || typeof errors !== 'object') return '';

    return Object.values(errors)
        .flat()
        .filter((hlaska): hlaska is string => typeof hlaska === 'string' && hlaska !== '')
        .join(' ');
}
