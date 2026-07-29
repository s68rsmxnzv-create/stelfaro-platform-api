import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Stelfaro';
const themeStorageKey = 'stelfaro:theme';

function initializeTheme() {
    const storedTheme = window.localStorage.getItem(themeStorageKey);
    const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
    const darkMode = storedTheme ? storedTheme === 'dark' : prefersDark;

    document.documentElement.classList.toggle('dark', darkMode);
    document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
}

initializeTheme();

let deferredInstallPrompt = null;

function pwaDisplayMode() {
    if (window.matchMedia('(display-mode: standalone)').matches) return 'standalone';
    if (window.navigator.standalone === true) return 'standalone';
    return 'browser';
}

function publishPwaState() {
    window.dispatchEvent(new CustomEvent('stelfaro:pwa-state', {
        detail: {
            canInstall: Boolean(deferredInstallPrompt),
            displayMode: pwaDisplayMode(),
        },
    }));
}

window.stelfaroPwa = {
    state: () => ({
        canInstall: Boolean(deferredInstallPrompt),
        displayMode: pwaDisplayMode(),
    }),
    install: async () => {
        if (!deferredInstallPrompt) return { outcome: 'unavailable' };
        deferredInstallPrompt.prompt();
        const choice = await deferredInstallPrompt.userChoice;
        deferredInstallPrompt = null;
        publishPwaState();
        return choice;
    },
};

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    publishPwaState();
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    publishPwaState();
});

window.matchMedia('(display-mode: standalone)').addEventListener('change', publishPwaState);

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js?v=6', {
            scope: '/',
            updateViaCache: 'none',
        }).then((registration) => {
            registration.update();
            if (registration.waiting) registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            registration.addEventListener('updatefound', () => {
                const worker = registration.installing;
                worker?.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        worker.postMessage({ type: 'SKIP_WAITING' });
                    }
                });
            });
        }).catch(() => {
            // La aplicación sigue funcionando normalmente si el navegador no admite la instalación.
        });
    });
}

let reloadingForServiceWorker = false;
navigator.serviceWorker?.addEventListener('controllerchange', () => {
    if (reloadingForServiceWorker) return;
    reloadingForServiceWorker = true;
    window.location.reload();
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#2563eb',
    },
});
