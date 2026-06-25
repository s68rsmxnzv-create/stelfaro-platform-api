<script setup>
import { router } from '@inertiajs/vue3';
import { onMounted } from 'vue';

const props = defineProps({
    title: {
        type: String,
        default: 'Pago recibido',
    },
    message: {
        type: String,
        default: 'Estamos confirmando la transacción con Wompi para activar tu suscripción.',
    },
    transactionId: {
        type: String,
        default: '',
    },
    declined: {
        type: Boolean,
        default: false,
    },
    confirmationUrl: {
        type: String,
        required: true,
    },
});

onMounted(() => {
    window.setTimeout(() => {
        router.visit(props.confirmationUrl, {
            preserveScroll: true,
        });
    }, props.declined ? 3500 : 2200);
});
</script>

<template>
    <div class="sf-app-background grid min-h-screen place-items-center px-5 py-12 text-slate-950 dark:text-text">
        <main class="w-full max-w-xl rounded-lg border border-slate-200 bg-white p-8 shadow-xl shadow-slate-950/10 dark:border-line dark:bg-surface-raised dark:shadow-black/30">
            <div class="flex items-start gap-4">
                <span
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-full text-xl font-black"
                    :class="declined ? 'bg-warning-soft text-warning' : 'bg-success-soft text-success'"
                >
                    {{ declined ? '!' : '✓' }}
                </span>
                <div>
                    <h1 class="text-3xl font-black tracking-normal">{{ title }}</h1>
                    <p class="mt-3 text-base leading-7 text-slate-600 dark:text-muted">{{ message }}</p>
                </div>
            </div>

            <div class="mt-8 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-surface-muted">
                <div class="h-full rounded-full bg-sky-600 motion-safe:animate-[wompi-progress_2.2s_ease-in-out_forwards]" />
            </div>

            <p v-if="transactionId" class="mt-5 text-sm font-semibold text-slate-500 dark:text-muted">
                Transacción: {{ transactionId }}
            </p>
        </main>
    </div>
</template>

<style scoped>
@keyframes wompi-progress {
    from {
        width: 12%;
    }

    to {
        width: 100%;
    }
}
</style>
