<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const themeStorageKey = 'stelfaro:theme';
const isDark = ref(false);

function toggleTheme() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    document.documentElement.dataset.theme = isDark.value ? 'dark' : 'light';
    window.localStorage.setItem(themeStorageKey, isDark.value ? 'dark' : 'light');
}

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});
</script>

<template>
    <div class="sf-safe-screen bg-app text-text">
        <header class="border-b border-white/10 bg-[var(--sf-color-navbar)] text-white">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 lg:px-8">
                <Link href="/" class="flex items-center gap-3" aria-label="Volver al inicio de Stelfaro">
                    <img src="/pwa/stelfaro-mark.svg" alt="" class="h-10 w-10 rounded-lg sm:h-11 sm:w-11 sm:rounded-xl" />
                    <span>
                        <strong class="block text-lg leading-none tracking-tight">Stelfaro</strong>
                        <span class="mt-1 hidden text-[10px] font-semibold uppercase tracking-[0.18em] text-sky-300 sm:block">
                            Tu negocio, más simple
                        </span>
                    </span>
                </Link>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="grid h-11 w-11 place-items-center rounded-xl border border-white/10 bg-white/10 text-white transition hover:bg-white/20"
                        :aria-label="isDark ? 'Activar modo claro' : 'Activar modo oscuro'"
                        :title="isDark ? 'Modo claro' : 'Modo oscuro'"
                        @click="toggleTheme"
                    >
                        <svg v-if="isDark" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" />
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" />
                        </svg>
                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" />
                        </svg>
                    </button>
                    <Link href="/" class="inline-flex h-11 items-center rounded-xl px-3 text-sm font-bold text-slate-200 transition hover:bg-white/10 hover:text-white sm:px-4">
                        <span aria-hidden="true">←</span>
                        <span class="ml-2 hidden sm:inline">Volver al inicio</span>
                    </Link>
                </div>
            </div>
        </header>

        <main class="relative isolate overflow-hidden">
            <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_15%_20%,rgba(14,165,233,0.10),transparent_30%),radial-gradient(circle_at_85%_80%,rgba(16,185,129,0.08),transparent_28%)]"></div>

            <div class="mx-auto grid min-h-[calc(100vh-4rem)] max-w-7xl items-center gap-12 px-5 py-10 sm:min-h-[calc(100vh-5rem)] sm:py-16 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
                <section class="hidden max-w-xl lg:block">
                    <span class="inline-flex rounded-full bg-primary-soft px-3 py-1.5 text-xs font-black uppercase tracking-wider text-primary">
                        Acceso seguro
                    </span>
                    <h1 class="mt-6 text-5xl font-black leading-[1.05] tracking-[-0.04em]">
                        Tu operación te espera.
                    </h1>
                    <p class="mt-6 text-lg leading-8 text-muted">
                        Una cuenta para acceder a facturación, taller y las herramientas de tu negocio desde cualquier dispositivo.
                    </p>

                    <div class="mt-10 grid gap-4">
                        <div class="flex items-center gap-4 rounded-2xl border border-line bg-surface/80 p-4 shadow-surface">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-primary-soft font-black text-primary">01</span>
                            <div>
                                <strong class="block">Un solo acceso</strong>
                                <span class="text-sm text-muted">Entramos directamente al espacio que te corresponde.</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 rounded-2xl border border-line bg-surface/80 p-4 shadow-surface">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-success-soft font-black text-success">✓</span>
                            <div>
                                <strong class="block">Sesión protegida</strong>
                                <span class="text-sm text-muted">Tus permisos y empresas permanecen separados y seguros.</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="flex justify-center lg:justify-end">
                    <div class="w-full max-w-md rounded-3xl border border-line bg-surface p-6 shadow-xl shadow-slate-950/10 dark:shadow-black/30 sm:p-8">
                        <slot />
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>
