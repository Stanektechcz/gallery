import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { Grid3X3, Heart, Map, Maximize2, Play, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const GRID_SIZES = [120, 160, 200, 260];
const MONTHS_CS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];

interface MediaCard {
    id: number;
    uuid: string;
    media_type: 'photo' | 'video';
    taken_at: string | null;
    width: number | null;
    height: number | null;
    is_favorite: boolean;
    rating: number | null;
    primary_album?: { id: number; uuid: string; title: string; slug: string } | null;
    variants: Array<{ type: string; url: string; blur_hash?: string; dominant_color?: string; aspect_ratio?: number }>;
}

interface TimelineGroup { date: string; label: string; month: string; year: string; items: MediaCard[] }

function MediaCardComponent({ item, size, onFav, onTrash, onSlideshow }: {
    item: MediaCard; size: number;
    onFav: (uuid: string, cur: boolean) => void;
    onTrash: (uuid: string) => void;
    onSlideshow: (uuid: string) => void;
}) {
    const thumb = item.variants?.find(v => v.type === 'thumbnail') ?? item.variants?.find(v => v.type === 'original');
    const dom   = item.variants?.find(v => v.type === 'placeholder')?.dominant_color;

    return (
        <div
            className="relative group cursor-pointer rounded overflow-hidden bg-[var(--color-bg-card)] shrink-0"
            style={{ width: size, height: size }}
            onClick={() => router.visit(`/media/${item.uuid}`)}
        >
            {dom && <div className="absolute inset-0" style={{ backgroundColor: dom }} />}
            {thumb && <img src={thumb.url} alt="" loading="lazy" decoding="async" className="absolute inset-0 w-full h-full object-cover" />}
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition-colors" />

            {item.media_type === 'video' && (
                <div className="absolute top-1.5 right-1.5 bg-black/60 rounded-full p-0.5">
                    <Play size={9} className="text-white fill-white" />
                </div>
            )}
            {item.is_favorite && <Heart size={11} className="absolute top-1.5 left-1.5 text-red-400 fill-red-400" />}

            {/* Hover actions */}
            <div className="absolute bottom-0 left-0 right-0 p-1 flex justify-between opacity-0 group-hover:opacity-100 transition-opacity">
                <button onClick={e => { e.stopPropagation(); onFav(item.uuid, item.is_favorite); }}
                    className="w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-black/80">
                    <Heart size={10} className={item.is_favorite ? 'text-red-400 fill-red-400' : 'text-white'} />
                </button>
                <div className="flex gap-1">
                    <button onClick={e => { e.stopPropagation(); onSlideshow(item.uuid); }}
                        className="w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-black/80">
                        <Maximize2 size={10} className="text-white" />
                    </button>
                    <button onClick={e => { e.stopPropagation(); onTrash(item.uuid); }}
                        className="w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-red-500/80">
                        <Trash2 size={10} className="text-white" />
                    </button>
                </div>
            </div>
        </div>
    );
}

function groupByDate(items: MediaCard[]): TimelineGroup[] {
    const groups: Record<string, MediaCard[]> = {};
    for (const item of items) {
        const key = item.taken_at ? item.taken_at.substring(0, 10) : '__nodate__';
        if (!groups[key]) groups[key] = [];
        groups[key].push(item);
    }
    return Object.entries(groups).map(([key, its]) => {
        const d = its[0].taken_at ? new Date(its[0].taken_at) : null;
        return {
            date:  key,
            label: d ? d.toLocaleDateString('cs-CZ', { weekday: 'long', day: 'numeric', month: 'long' }) : 'Bez data',
            month: d ? `${MONTHS_CS[d.getMonth()]} ${d.getFullYear()}` : '',
            year:  d ? String(d.getFullYear()) : '',
            items: its,
        };
    });
}
