
interface MapPoint {
    id: number;
    uuid: string;
    latitude: number;
    longitude: number;
    taken_at: string | null;
    media_type: string;
    original_filename: string;
    primary_album?: { id: number; uuid: string; title: string } | null;
    variants: Array<{ type: string; url: string }>;
}
