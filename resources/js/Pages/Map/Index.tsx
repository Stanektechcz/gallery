import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ExternalLink, Image, MapPin, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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
