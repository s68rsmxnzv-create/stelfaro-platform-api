<script setup>
import { computed } from 'vue';
import PlatformShell from '../../Layouts/PlatformShell.vue';

const props = defineProps({
    session: {
        type: Object,
        default: null,
    },
    availableApps: {
        type: Array,
        default: () => [],
    },
});

const apps = computed(() => props.session?.apps?.length ? props.session.apps : props.availableApps);
const defaultAppPath = computed(() => props.session?.default_app?.local_path || '/taller');
</script>

<template>
    <PlatformShell active-app="portal">
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-5 py-8">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Centro de plataforma</p>
                <div class="mt-3 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-[#0d1629]">Acceso único a las apps de Stelfaro</h1>
                        <p class="mt-2 max-w-3xl text-base text-slate-600">
                            Esta capa reunirá autenticación, tenants, permisos y entrada a cada vertical sin recargar al core fiscal.
                        </p>
                    </div>
                    <a
                        :href="defaultAppPath"
                        class="inline-flex items-center justify-center rounded-md bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                    >
                        Entrar a mi app
                    </a>
                </div>
            </div>
        </section>

        <section class="mx-auto grid max-w-7xl gap-5 px-5 py-6 lg:grid-cols-[1.3fr_0.7fr]">
            <div class="rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">Apps disponibles</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    <a
                        v-for="app in apps"
                        :key="app.id"
                        :href="app.local_path || '/'"
                        class="grid gap-3 px-5 py-4 transition hover:bg-slate-50 md:grid-cols-[1fr_auto]"
                    >
                        <span>
                            <span class="block font-semibold">{{ app.name }}</span>
                            <span class="mt-1 block text-sm text-slate-500">{{ app.host }}</span>
                        </span>
                        <span class="self-center rounded bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">
                            Abrir
                        </span>
                    </a>
                    <div v-if="apps.length === 0" class="px-5 py-8 text-sm text-slate-500">
                        No hay apps activas configuradas todavía.
                    </div>
                </div>
            </div>

            <aside class="rounded-md border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Sesión de plataforma</h2>
                <dl class="mt-4 space-y-4 text-sm">
                    <div>
                        <dt class="font-medium text-slate-500">Usuario</dt>
                        <dd class="mt-1 font-semibold">{{ session?.user?.name }}</dd>
                        <dd class="mt-1 text-slate-600">{{ session?.user?.email }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Tenant activo</dt>
                        <dd class="mt-1 font-semibold">{{ session?.tenant?.name }}</dd>
                        <dd class="mt-1 text-slate-600">{{ session?.tenant?.role }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">App principal</dt>
                        <dd class="mt-1 font-semibold">{{ session?.default_app?.name }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500">Estado</dt>
                        <dd class="mt-1 text-slate-700">Sesión autenticada y apps resueltas por tenant.</dd>
                    </div>
                </dl>
            </aside>
        </section>
    </PlatformShell>
</template>
