import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
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

function installCloseSessionRevocation(isAuthenticated) {
    if (!isAuthenticated || typeof window === 'undefined') return;

    let skipNextHide = false;
    let revocationSent = false;
    let skipTimer = null;

    const markExpectedNavigation = () => {
        skipNextHide = true;
        window.clearTimeout(skipTimer);
        skipTimer = window.setTimeout(() => {
            skipNextHide = false;
        }, 2500);
    };

    router.on('start', markExpectedNavigation);
    window.addEventListener('popstate', markExpectedNavigation);
    window.addEventListener('keydown', (event) => {
        const key = event.key?.toLowerCase();
        if (key === 'f5' || ((event.ctrlKey || event.metaKey) && key === 'r')) {
            markExpectedNavigation();
        }
    }, { capture: true });
    document.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a[href]');
        if (!link) return;

        markExpectedNavigation();
    }, { capture: true });
    document.addEventListener('submit', markExpectedNavigation, { capture: true });

    const revokeOnClose = (event) => {
        if (event?.persisted || skipNextHide || revocationSent) return;

        revocationSent = true;
        const endpoint = '/api/v1/logout';

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([], { type: 'application/x-www-form-urlencoded' }));
            return;
        }

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => undefined);
    };

    window.addEventListener('pagehide', revokeOnClose);
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        installCloseSessionRevocation(Boolean(props.initialPage?.props?.auth?.user));

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#2563eb',
    },
});
