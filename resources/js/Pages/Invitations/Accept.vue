<script setup>
import PlatformShell from '@/Layouts/PlatformShell.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    token: {
        type: String,
        required: true,
    },
    invitation: {
        type: Object,
        default: null,
    },
    user: {
        type: Object,
        required: true,
    },
});

const loading = ref(false);
const accepted = ref(false);
const error = ref(null);

const canAccept = computed(() => props.invitation?.status === 'pending' && !accepted.value);
const roleLabel = computed(() => ({
    company_admin: 'Admin empresa',
    billing_admin: 'Admin facturacion',
    billing_user: 'Cajero',
    viewer: 'Contador / lectura',
}[props.invitation?.role] ?? props.invitation?.role ?? 'Rol pendiente'));

const statusLabel = computed(() => ({
    pending: 'Pendiente',
    accepted: 'Aceptada',
    expired: 'Expirada',
    revoked: 'Revocada',
}[props.invitation?.status] ?? props.invitation?.status ?? 'No encontrada'));

async function acceptInvitation() {
    if (!canAccept.value) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(`/api/v1/platform/invitations/${props.token}/accept`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
            },
            credentials: 'include',
        });
        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            const message = payload?.message
                ?? Object.values(payload?.errors ?? {}).flat()[0]
                ?? 'No fue posible aceptar la invitacion.';
            throw new Error(message);
        }

        accepted.value = true;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'No fue posible aceptar la invitacion.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <PlatformShell active-app="portal">
        <Head title="Aceptar invitacion" />

        <section class="mx-auto flex min-h-[calc(100vh-73px)] max-w-4xl items-center px-5 py-10">
            <div class="w-full rounded-md border border-slate-200 bg-white p-6 shadow-sm dark:border-line dark:bg-surface dark:text-text dark:shadow-black/20">
                <p class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-soft">Invitacion</p>
                <h1 class="mt-2 text-2xl font-bold text-slate-950 dark:text-text">Acceso a empresa</h1>

                <div v-if="!invitation" class="mt-6 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-danger-soft dark:bg-danger-soft dark:text-danger">
                    Esta invitacion no existe o el enlace ya no es valido.
                </div>

                <template v-else>
                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div class="rounded-md border border-slate-200 p-4 dark:border-line dark:bg-surface-muted">
                            <p class="text-xs font-bold uppercase text-slate-500 dark:text-soft">Empresa</p>
                            <p class="mt-2 text-lg font-bold text-slate-950 dark:text-text">{{ invitation.tenant?.name ?? 'Empresa sin nombre' }}</p>
                        </div>
                        <div class="rounded-md border border-slate-200 p-4 dark:border-line dark:bg-surface-muted">
                            <p class="text-xs font-bold uppercase text-slate-500 dark:text-soft">Rol</p>
                            <p class="mt-2 text-lg font-bold text-slate-950 dark:text-text">{{ roleLabel }}</p>
                        </div>
                        <div class="rounded-md border border-slate-200 p-4 dark:border-line dark:bg-surface-muted">
                            <p class="text-xs font-bold uppercase text-slate-500 dark:text-soft">Invitado</p>
                            <p class="mt-2 text-sm font-semibold text-slate-950 dark:text-text">{{ invitation.email }}</p>
                        </div>
                        <div class="rounded-md border border-slate-200 p-4 dark:border-line dark:bg-surface-muted">
                            <p class="text-xs font-bold uppercase text-slate-500 dark:text-soft">Estado</p>
                            <p class="mt-2 text-sm font-semibold text-slate-950 dark:text-text">{{ accepted ? 'Aceptada' : statusLabel }}</p>
                        </div>
                    </div>

                    <p v-if="user.email !== invitation.email" class="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-800 dark:border-warning-soft dark:bg-warning-soft dark:text-warning">
                        Esta invitacion es para {{ invitation.email }} y tu sesion actual es {{ user.email }}.
                    </p>

                    <p v-if="error" class="mt-5 rounded-md bg-rose-700 px-4 py-3 text-sm text-white">{{ error }}</p>
                    <p v-if="accepted" class="mt-5 rounded-md bg-emerald-700 px-4 py-3 text-sm text-white">
                        Invitacion aceptada. Ya tienes acceso a la empresa.
                    </p>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <Link href="/" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200 dark:bg-surface-muted dark:text-text dark:hover:bg-surface-raised">
                            Ir a mis apps
                        </Link>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="loading || !canAccept || user.email !== invitation.email"
                            @click="acceptInvitation"
                        >
                            {{ loading ? 'Aceptando...' : 'Aceptar invitacion' }}
                        </button>
                    </div>
                </template>
            </div>
        </section>
    </PlatformShell>
</template>
