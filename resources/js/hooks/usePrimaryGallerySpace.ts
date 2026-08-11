import { router, usePage } from '@inertiajs/react';
import { useCallback } from 'react';

type GallerySpace = { id: number; name: string };

/**
 * The space this person works in.
 *
 * Read from the shared page props rather than fetched. It used to come from the calendar
 * endpoint, which meant a customer whose plan does not include the calendar was told
 * "prostor není dostupný" on the automations page — a screen that has nothing to do with
 * the calendar. One row that every page already has cannot fail the way a request can.
 *
 * `reload` is kept so callers that offered a retry keep working; it re-fetches the prop.
 */
export default function usePrimaryGallerySpace() {
    const space = (usePage().props as { space?: GallerySpace | null }).space ?? null;

    const reload = useCallback(() => {
        router.reload({ only: ['space'] });
    }, []);

    return {
        space,
        spaceId: space?.id,
        loading: false,
        error: space ? '' : 'Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.',
        reload,
    };
}
