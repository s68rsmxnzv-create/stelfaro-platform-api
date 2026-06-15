<script setup>
import PlatformShell from '../../../Layouts/PlatformShell.vue';

defineProps({
    app: {
        type: Object,
        required: true,
    },
});

const metrics = [
    { label: 'Órdenes abiertas', value: '18' },
    { label: 'Equipos listos', value: '7' },
    { label: 'DTE emitidos hoy', value: '12' },
];
</script>

<template>
    <PlatformShell active-app="taller">
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-5 py-7">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">App operativa</p>
                <h1 class="mt-2 text-3xl font-bold">{{ app.name }}</h1>
                <p class="mt-2 max-w-3xl text-slate-600">{{ app.description }}</p>
            </div>
        </section>

        <section class="mx-auto grid max-w-7xl gap-5 px-5 py-6 lg:grid-cols-[0.8fr_1.2fr]">
            <div class="rounded-md border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Bandeja de trabajo</h2>
                <div class="mt-5 grid gap-3">
                    <button class="rounded-md border border-slate-200 px-4 py-3 text-left font-medium hover:bg-slate-50">
                        Nueva recepción de equipo
                    </button>
                    <button class="rounded-md border border-slate-200 px-4 py-3 text-left font-medium hover:bg-slate-50">
                        Diagnóstico técnico
                    </button>
                    <button class="rounded-md border border-slate-200 px-4 py-3 text-left font-medium hover:bg-slate-50">
                        Entregar y facturar
                    </button>
                </div>
            </div>

            <div class="grid gap-5">
                <div class="grid gap-4 md:grid-cols-3">
                    <div v-for="metric in metrics" :key="metric.label" class="rounded-md border border-slate-200 bg-white p-5">
                        <p class="text-sm font-medium text-slate-500">{{ metric.label }}</p>
                        <p class="mt-3 text-3xl font-bold">{{ metric.value }}</p>
                    </div>
                </div>

                <div class="rounded-md border border-slate-200 bg-white">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-lg font-semibold">Flujo inicial</h2>
                    </div>
                    <div class="grid gap-0 divide-y divide-slate-100">
                        <div class="px-5 py-4">
                            <p class="font-semibold">1. Recibir equipo</p>
                            <p class="mt-1 text-sm text-slate-600">Cliente, accesorios, estado físico y comprobante de recepción.</p>
                        </div>
                        <div class="px-5 py-4">
                            <p class="font-semibold">2. Cotizar y aprobar</p>
                            <p class="mt-1 text-sm text-slate-600">Repuestos, mano de obra, comunicación con cliente y aprobación.</p>
                        </div>
                        <div class="px-5 py-4">
                            <p class="font-semibold">3. Facturar y notificar</p>
                            <p class="mt-1 text-sm text-slate-600">Emisión DTE desde el core, correo al receptor e impresión cuando conectemos el agente.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </PlatformShell>
</template>
