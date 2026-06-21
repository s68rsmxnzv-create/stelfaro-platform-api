<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { BillingAppPage } from '@stelfaro/billing';
import { computed, ref } from 'vue';

const props = defineProps({
    app: {
        type: Object,
        required: true,
    },
    coreBaseUrl: {
        type: String,
        default: '/core-api/v1',
    },
    module: {
        type: String,
        default: 'billing',
    },
    documentSlug: {
        type: String,
        default: 'fe',
    },
    eventSlug: {
        type: String,
        default: 'invalidacion',
    },
    coreSession: {
        type: Object,
        default: null,
    },
    coreSessionError: {
        type: String,
        default: null,
    },
    canAccessPlatformAdmin: {
        type: Boolean,
        default: false,
    },
    platformAdminUrl: {
        type: String,
        default: 'https://admin.stelfaro.com',
    },
    showOperationalFlow: {
        type: Boolean,
        default: false,
    },
    operationalPage: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const error = ref(props.coreSessionError || '');
const authToken = ref(props.coreSession?.token || null);
const isTaller = computed(() => props.app.id === 'taller');
const appBaseUrl = computed(() => (isTaller.value ? 'https://taller.stelfaro.com' : 'https://facturacion.stelfaro.com'));
const currentPath = computed(() => new URL(page.url, window.location.origin).pathname);
const extraNavItems = computed(() => {
    if (!isTaller.value) return [];

    return [
        { label: 'Recepción', href: 'https://taller.stelfaro.com/recepcion', active: currentPath.value.startsWith('/recepcion') },
        { label: 'Diagnóstico', href: 'https://taller.stelfaro.com/diagnostico', active: currentPath.value.startsWith('/diagnostico') },
        { label: 'Órdenes', href: 'https://taller.stelfaro.com/ordenes', active: currentPath.value.startsWith('/ordenes') },
    ];
});
const logout = () => {
    router.post('/logout');
};
const navigate = ({ event, href }) => {
    if (!href) return;

    const target = new URL(href, window.location.origin);
    if (target.origin !== window.location.origin) return;

    event?.preventDefault();
    router.visit(`${target.pathname}${target.search}${target.hash}`, {
        preserveScroll: true,
        preserveState: true,
        replace: false,
    });
};
</script>

<template>
    <div class="sf-app-background min-h-screen dark:text-text">
        <section v-if="error" class="mx-auto max-w-7xl px-5 py-8">
            <div class="rounded-md border border-red-200 bg-red-50 p-5 text-red-700">
                {{ error }}
            </div>
        </section>

        <BillingAppPage
            v-else
            :app="app"
            :auth-token="authToken"
            :core-base-url="coreBaseUrl"
            :dashboard-url="appBaseUrl"
            :document-slug="documentSlug"
            :event-slug="eventSlug"
            :extra-nav-items="extraNavItems"
            :can-access-platform-admin="canAccessPlatformAdmin"
            :module="module"
            :platform-admin-url="platformAdminUrl"
            :user="user"
            :app-base-url="appBaseUrl"
            :operational-page="operationalPage"
            @navigate="navigate"
            @logout="logout"
        />
    </div>
</template>
