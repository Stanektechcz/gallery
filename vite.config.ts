import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";
import path from "path";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.tsx"],
            refresh: true,
        }),
        react(),
        tailwindcss(),
        VitePWA({
            registerType: "autoUpdate",
            includeAssets: [
                "favicon.ico",
                "apple-touch-icon.png",
                "mask-icon.svg",
            ],
            manifest: {
                name: "Stanektech Gallery",
                short_name: "Gallery",
                description: "Soukromá foto/video galerie Stanektech",
                theme_color: "#1a1a2e",
                background_color: "#1a1a2e",
                display: "standalone",
                orientation: "any",
                scope: "/",
                start_url: "/",
                icons: [
                    {
                        src: "/icons/pwa-192x192.png",
                        sizes: "192x192",
                        type: "image/png",
                    },
                    {
                        src: "/icons/pwa-512x512.png",
                        sizes: "512x512",
                        type: "image/png",
                    },
                    {
                        src: "/icons/pwa-512x512.png",
                        sizes: "512x512",
                        type: "image/png",
                        purpose: "maskable",
                    },
                ],
                categories: ["photo", "utilities"],
                share_target: {
                    action: "/share-target",
                    method: "POST",
                    enctype: "multipart/form-data",
                    params: {
                        title: "title",
                        text: "text",
                        url: "url",
                        files: [
                            { name: "media", accept: ["image/*", "video/*"] },
                        ],
                    },
                },
                shortcuts: [
                    {
                        name: "Nahrát fotky",
                        url: "/upload",
                        icons: [
                            {
                                src: "/icons/shortcut-upload.png",
                                sizes: "96x96",
                            },
                        ],
                    },
                    {
                        name: "Timeline",
                        url: "/timeline",
                        icons: [
                            {
                                src: "/icons/shortcut-timeline.png",
                                sizes: "96x96",
                            },
                        ],
                    },
                ],
            },
            workbox: {
                globPatterns: ["**/*.{js,css,html,ico,png,svg,woff2}"],
                runtimeCaching: [
                    {
                        urlPattern: /^\/api\/v1\/timeline/,
                        handler: "NetworkFirst",
                        options: {
                            cacheName: "timeline-cache",
                            expiration: { maxEntries: 50, maxAgeSeconds: 300 },
                        },
                    },
                    {
                        urlPattern: /^\/storage\/variants\//,
                        handler: "CacheFirst",
                        options: {
                            cacheName: "variants-cache",
                            expiration: {
                                maxEntries: 500,
                                maxAgeSeconds: 86400 * 30,
                            },
                        },
                    },
                ],
            },
            devOptions: { enabled: true },
        }),
    ],
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "resources/js"),
        },
    },
});
