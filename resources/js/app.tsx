import { createInertiaApp } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import PwaLifecycle from './Components/PwaLifecycle';
import { PwaInstallProvider } from './Contexts/PwaInstallContext';
import '../css/app.css';

// Configure Axios for Sanctum stateful auth (cookie-based for web)
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const appName = (window as any).__APP_NAME__ || 'Stanektech Gallery';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 1000 * 60 * 2, // 2 minutes
            retry: 1,
        },
    },
});

createInertiaApp({
    title: (title) => title ? `${title} – ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <QueryClientProvider client={queryClient}>
                <PwaInstallProvider>
                    <PwaLifecycle />
                    <App {...props} />
                </PwaInstallProvider>
            </QueryClientProvider>
        );
    },
    progress: {
        color: '#6c63ff',
        showSpinner: true,
    },
});
