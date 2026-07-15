import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';

type GallerySpace = { id: number; name: string };

export default function usePrimaryGallerySpace() {
    const [space, setSpace] = useState<GallerySpace | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const reload = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const response = await axios.get('/api/v1/calendar/events');
            const next = (response.data?.spaces ?? [])[0] ?? null;
            setSpace(next);
            if (!next) setError('Nejprve vytvořte nebo přijměte pozvánku do společného prostoru.');
        } catch (reason: any) {
            setError(reason?.response?.data?.message ?? 'Společný prostor se nepodařilo načíst.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { void reload(); }, [reload]);

    return { space, spaceId: space?.id, loading, error, reload };
}
