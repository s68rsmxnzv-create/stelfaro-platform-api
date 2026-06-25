<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    transactionId: {
        type: String,
        default: '',
    },
    event: {
        type: Object,
        default: null,
    },
    dashboardUrl: {
        type: String,
        default: '/',
    },
});

const isProcessed = computed(() => props.event?.status === 'processed' && props.event?.subscription);
const title = computed(() => {
    if (isProcessed.value) return 'Suscripción activada';
    if (props.event) return 'Pago en revisión';

    return 'Confirmación pendiente';
});
const message = computed(() => {
    if (isProcessed.value) return 'Tu pago fue confirmado y la suscripción ya quedó activa.';
    if (props.event) return 'Recibimos el pago, pero todavía estamos completando la activación automática.';

    return 'Aún no encontramos la notificación de Wompi. Normalmente llega en pocos segundos.';
});
const planName = computed(() => {
    const key = props.event?.subscription?.plan?.key;

    if (key === 'starter') return 'Emprendedor';
    if (key === 'pro') return 'Profesional';

    return props.event?.subscription?.plan?.name ?? 'Plan';
});
const amount = computed(() => {
    const value = Number(props.event?.amount ?? 0);

    return new Intl.NumberFormat('es-SV', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
});
const validUntil = computed(() => formatDate(props.event?.subscription?.currentPeriodEndsAt));
const fiscalFields = [
    'Tipo de documento fiscal',
    'NIT',
    'NRC',
    'Nombre o razón social',
    'Giro o actividad económica',
    'Dirección fiscal',
    'Departamento y municipio',
    'Correo para envío del CCF',
];

function formatDate(value) {
    if (!value) return 'Pendiente';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Pendiente';

    return new Intl.DateTimeFormat('es-SV', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}
</script>

<template>
    <div class="sf-app-background min-h-screen px-5 py-10 text-slate-950 dark:text-text">
        <main class="mx-auto w-full max-w-3xl rounded-lg border border-slate-200 bg-white p-8 shadow-xl shadow-slate-950/10 dark:border-line dark:bg-surface-raised dark:shadow-black/30">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-bold uppercase tracking-wide text-sky-700 dark:text-primary">Wompi</p>
                    <h1 class="mt-2 text-3xl font-black tracking-normal">{{ title }}</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600 dark:text-muted">{{ message }}</p>
                </div>
                <span
                    class="rounded-full px-4 py-2 text-sm font-bold"
                    :class="isProcessed ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'"
                >
                    {{ isProcessed ? 'Activa' : 'En revisión' }}
                </span>
            </div>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <section class="rounded-md border border-slate-200 p-4 dark:border-line">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted">Plan</p>
                    <p class="mt-2 text-xl font-black">{{ isProcessed ? planName : 'Pendiente' }}</p>
                </section>
                <section class="rounded-md border border-slate-200 p-4 dark:border-line">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted">Monto</p>
                    <p class="mt-2 text-xl font-black">{{ event ? amount : 'Pendiente' }}</p>
                </section>
                <section class="rounded-md border border-slate-200 p-4 dark:border-line">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted">Vigente hasta</p>
                    <p class="mt-2 text-xl font-black">{{ validUntil }}</p>
                </section>
                <section class="rounded-md border border-slate-200 p-4 dark:border-line">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted">Empresa</p>
                    <p class="mt-2 text-xl font-black">{{ event?.tenant?.name ?? 'Pendiente' }}</p>
                </section>
            </div>

            <dl class="mt-8 space-y-3 rounded-md bg-slate-50 p-4 text-sm dark:bg-surface-muted">
                <div class="flex flex-col gap-1 sm:flex-row sm:justify-between">
                    <dt class="font-semibold text-slate-500 dark:text-muted">Transacción</dt>
                    <dd class="break-all font-bold">{{ transactionId || event?.transactionId || 'Pendiente' }}</dd>
                </div>
                <div v-if="event?.customerEmail" class="flex flex-col gap-1 sm:flex-row sm:justify-between">
                    <dt class="font-semibold text-slate-500 dark:text-muted">Correo</dt>
                    <dd class="break-all font-bold">{{ event.customerEmail }}</dd>
                </div>
                <div v-if="event?.commerceIdentifier" class="flex flex-col gap-1 sm:flex-row sm:justify-between">
                    <dt class="font-semibold text-slate-500 dark:text-muted">Producto</dt>
                    <dd class="font-bold">{{ event.commerceIdentifier }}</dd>
                </div>
            </dl>

            <section
                v-if="isProcessed"
                class="mt-8 rounded-lg border border-slate-200 bg-white p-5 dark:border-line dark:bg-surface"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-wide text-sky-700 dark:text-primary">Factura fiscal</p>
                        <h2 class="mt-1 text-xl font-black">Generar crédito fiscal</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600 dark:text-muted">
                            Próximamente podrás generar el CCF de esta compra desde aquí. Cuando el comprobante sea emitido correctamente,
                            este botón dejará de generar nuevos documentos y solo permitirá descargar el comprobante o solicitar reenvío.
                        </p>
                    </div>
                    <button
                        type="button"
                        disabled
                        class="inline-flex shrink-0 justify-center rounded-lg bg-slate-200 px-5 py-3 text-sm font-bold text-slate-500 disabled:cursor-not-allowed dark:bg-surface-muted dark:text-muted"
                    >
                        Generar factura
                    </button>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="field in fiscalFields"
                        :key="field"
                        class="rounded-md border border-dashed border-slate-200 px-4 py-3 dark:border-line"
                    >
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted">{{ field }}</p>
                        <p class="mt-1 text-sm font-semibold text-slate-400 dark:text-soft">Pendiente</p>
                    </div>
                </div>

                <p class="mt-5 rounded-md bg-warning-soft px-4 py-3 text-sm font-semibold text-warning">
                    Regla pendiente: una transacción pagada solo podrá emitir un CCF una vez. Después de emitido, se habilitará descarga y reenvío.
                </p>
            </section>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <Link
                    :href="dashboardUrl"
                    class="inline-flex justify-center rounded-lg bg-sky-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-sky-500"
                >
                    Volver a Stelfaro
                </Link>
                <Link
                    v-if="!isProcessed"
                    :href="`/payments/wompi/confirmation?idTransaccion=${encodeURIComponent(transactionId)}`"
                    class="inline-flex justify-center rounded-lg border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 dark:border-line dark:text-muted dark:hover:bg-surface-muted"
                >
                    Revisar de nuevo
                </Link>
            </div>
        </main>
    </div>
</template>
