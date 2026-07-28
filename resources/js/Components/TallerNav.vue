<script setup>
import { BillingAppNav } from '@stelfaro/billing';

defineProps({
    authToken: {
        type: String,
        default: null,
    },
    coreBaseUrl: {
        type: String,
        default: '/core-api/v1',
    },
    module: {
        type: String,
        default: 'dashboard',
    },
    documentSlug: {
        type: String,
        default: 'fe',
    },
    eventSlug: {
        type: String,
        default: 'invalidacion',
    },
});
</script>

<template>
    <a
        href="/taller"
        class="rounded px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
        :class="module === 'dashboard' ? 'bg-white text-[#0d1629] hover:bg-white hover:text-[#0d1629]' : ''"
    >
        Inicio
    </a>
    <a
        href="/taller/recepcion"
        class="rounded px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
    >
        Recepción
    </a>
    <a
        href="/taller/ordenes"
        class="rounded px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
    >
        Órdenes
    </a>
    <BillingAppNav
        :auth-token="authToken"
        :core-base-url="coreBaseUrl"
        :document-slug="documentSlug"
        :event-slug="eventSlug"
        :module="module"
        app-base-url="/taller"
    />
</template>
