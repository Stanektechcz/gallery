/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

// Inertia global types
declare module "@inertiajs/react" {
    interface PageProps {
        auth: {
            user: App.User | null;
        };
        flash?: {
            success?: string;
            error?: string;
            warning?: string;
        };
        ziggy?: Record<string, unknown>;
    }
}

declare namespace App {
    interface User {
        id: number;
        uuid: string;
        name: string;
        email: string;
        role: "owner" | "admin" | "partner" | "viewer";
        avatar_url?: string;
        is_active: boolean;
        preferences?: Record<string, unknown>;
        created_at: string;
    }

    interface GallerySpace {
        id: number;
        uuid: string;
        name: string;
        slug: string;
        description?: string;
        owner_id: number;
        is_default: boolean;
        created_at: string;
    }

    interface Album {
        id: number;
        uuid: string;
        gallery_space_id: number;
        parent_id: number | null;
        title: string;
        slug: string;
        depth: number;
        materialized_path: string;
        full_display_path: string;
        drive_folder_id?: string;
        cover_media_id?: number;
        description?: string;
        event_date_start?: string;
        event_date_end?: string;
        color?: string;
        icon?: string;
        sort_mode: string;
        sort_direction: string;
        visibility: string;
        sync_status: string;
        children?: Album[];
        cover?: MediaItem;
        media_count?: number;
        descendant_count?: number;
        created_at: string;
        updated_at: string;
    }

    interface MediaItem {
        id: number;
        uuid: string;
        gallery_space_id: number;
        owner_user_id: number;
        primary_album_id: number;
        original_filename: string;
        display_title?: string;
        extension: string;
        mime_type: string;
        media_type: "photo" | "video";
        size_bytes: number;
        width?: number;
        height?: number;
        duration_ms?: number;
        taken_at?: string;
        latitude?: number;
        longitude?: number;
        camera_make?: string;
        camera_model?: string;
        rating?: number;
        description?: string;
        status: string;
        is_favorite: boolean;
        is_archived: boolean;
        trashed_at?: string;
        variants?: MediaVariant[];
        tags?: Tag[];
        people?: Person[];
        places?: Place[];
        created_at: string;
        updated_at: string;
    }

    interface MediaVariant {
        id: number;
        media_item_id: number;
        type: string;
        path: string;
        url: string;
        width?: number;
        height?: number;
        size_bytes?: number;
        format?: string;
        blur_hash?: string;
        dominant_color?: string;
    }

    interface Tag {
        id: number;
        name: string;
        slug: string;
        parent_id?: number;
        depth: number;
        color?: string;
        children?: Tag[];
    }

    interface Person {
        id: number;
        name: string;
        nickname?: string;
        cover_media_id?: number;
    }

    interface Place {
        id: number;
        name: string;
        city?: string;
        country?: string;
        country_code?: string;
        latitude?: number;
        longitude?: number;
    }

    interface StorageConnection {
        id: number;
        provider: string;
        account_email?: string;
        connection_status: string;
        root_folder_name?: string;
        token_expires_at?: string;
        last_successful_request_at?: string;
        last_error_at?: string;
        last_error_message?: string;
        connected_at?: string;
    }

    interface PaginatedResult<T> {
        data: T[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
            from: number;
            to: number;
        };
        links: {
            first?: string;
            last?: string;
            prev?: string;
            next?: string;
        };
    }
}
