<script setup>
import PlatformShell from '../../../Layouts/PlatformShell.vue';

defineProps({
    app: {
        type: Object,
        required: true,
    },
});

const documents = [
    { type: 'Factura consumidor final', status: 'Lista' },
    { type: 'Crédito fiscal', status: 'Lista' },
    { type: 'Nota de crédito', status: 'Lista' },
    { type: 'Nota de débito', status: 'Lista' },
];
</script>

<template>
    <PlatformShell active-app="facturacion">
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-5 py-7">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">App operativa</p>
                <h1 class="mt-2 text-3xl font-bold">{{ app.name }}</h1>
                <p class="mt-2 max-w-3xl text-slate-600">{{ app.description }}</p>
            </div>
        </section>

        <section class="mx-auto grid max-w-7xl gap-5 px-5 py-6 lg:grid-cols-[1fr_1fr]">
            <div class="rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">Documentos fiscales</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    <div v-for="document in documents" :key="document.type" class="flex items-center justify-between px-5 py-4">
                        <span class="font-medium">{{ document.type }}</span>
                        <span class="rounded bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">
                            {{ document.status }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="rounded-md border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Piezas conectadas</h2>
                <div class="mt-5 grid gap-4">
                    <div class="rounded-md bg-slate-50 p-4">
                        <p class="font-semibold">Core fiscal</p>
                        <p class="mt-1 text-sm text-slate-600">Firma, envío MH, recepción y artefactos fiscales.</p>
                    </div>
                    <div class="rounded-md bg-slate-50 p-4">
                        <p class="font-semibold">Notificaciones</p>
                        <p class="mt-1 text-sm text-slate-600">Correo al receptor con PDF, JSON y plantilla corporativa.</p>
                    </div>
                    <div class="rounded-md bg-slate-50 p-4">
                        <p class="font-semibold">Impresión silenciosa</p>
                        <p class="mt-1 text-sm text-slate-600">Pendiente de integrar con el agente local existente.</p>
                    </div>
                </div>
            </div>
        </section>
    </PlatformShell>
</template>
