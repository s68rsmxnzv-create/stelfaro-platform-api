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
