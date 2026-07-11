/** Add a Czech map when Mapy.com is configured, otherwise a Latin-script map. */
export function addLocalizedBaseLayer(L: any, map: any): any {
    const mapyKey = import.meta.env.VITE_MAPY_API_KEY as string | undefined;
    if (mapyKey) {
        return L.tileLayer(
            `https://api.mapy.com/v1/maptiles/basic/256/{z}/{x}/{y}?apikey=${encodeURIComponent(mapyKey)}&lang=cs`,
            { attribution: '© Seznam.cz, a.s. · © OpenStreetMap', maxZoom: 20 },
        ).addTo(map);
    }

    return L.tileLayer(
        'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
        { subdomains: 'abcd', attribution: '© OpenStreetMap contributors · © CARTO', maxZoom: 20 },
    ).addTo(map);
}

export function localizedCountry(country?: string, countryCode?: string): string {
    if (countryCode && typeof Intl !== 'undefined' && 'DisplayNames' in Intl) {
        try {
            const translated = new Intl.DisplayNames(['cs'], { type: 'region' }).of(countryCode.toUpperCase());
            if (translated) return translated;
        } catch { /* use server fallback */ }
    }
    return country ?? '';
}
