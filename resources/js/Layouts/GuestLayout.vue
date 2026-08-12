<script setup>
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Home } from 'lucide-vue-next';

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
    <div class="guest-landing sf-safe-screen min-h-screen w-full max-w-full overflow-x-clip bg-app text-text">
        <header class="public-header fixed inset-x-0 top-0 z-50 border-b border-line bg-app">
            <div class="mx-auto flex h-[calc(4rem+env(safe-area-inset-top))] max-w-7xl items-center justify-between gap-3 pb-0 pl-[max(1rem,env(safe-area-inset-left))] pr-[max(1rem,env(safe-area-inset-right))] pt-[env(safe-area-inset-top)] sm:h-[calc(5rem+env(safe-area-inset-top))] sm:px-5 sm:pt-[env(safe-area-inset-top)] lg:h-[calc(6rem+env(safe-area-inset-top))] lg:px-8">
                <Link href="/" class="group flex min-w-0 items-center gap-2.5" aria-label="Volver al inicio de StelFaro">
                    <img src="/pwa/stelfaro-mark-on-light.svg" alt="" class="h-10 w-9 shrink-0 object-contain sm:h-12 sm:w-11 lg:h-16 lg:w-14" />
                    <span>
                        <strong class="block truncate text-base leading-none tracking-tight text-text sm:text-lg lg:text-xl">StelFaro</strong>
                        <span class="mt-1.5 hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-primary lg:block">
                            Tu negocio, más simple
                        </span>
                    </span>
                </Link>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-lg border border-line text-muted transition hover:bg-surface-muted sm:h-11 sm:w-11"
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
                    <Link
                        href="/"
                        class="grid h-10 w-10 place-items-center rounded-lg border border-line text-muted transition hover:bg-surface-muted sm:h-11 sm:w-11"
                        aria-label="Volver al inicio"
                        title="Volver al inicio"
                    >
                        <Home class="h-5 w-5" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </header>

        <main class="guest-texture relative isolate h-screen overflow-hidden pt-[calc(4rem+env(safe-area-inset-top))] sm:pt-[calc(5rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]">
            <div class="mx-auto grid h-full max-w-7xl items-center gap-8 px-4 py-6 sm:px-5 sm:py-8 lg:grid-cols-[1.35fr_0.9fr] lg:px-8">
                <section class="login-photo relative isolate hidden aspect-[1532/801] w-full overflow-hidden rounded-2xl lg:block">
                    <img src="/images/login-image-v2.webp" alt="" class="absolute inset-0 h-full w-full object-cover" aria-hidden="true" />
                    <div class="relative z-10 flex h-full max-w-md flex-col justify-center py-6 pl-[27%] pr-8">
                        <h1 class="text-3xl font-extrabold leading-[1.1] tracking-[-0.02em] text-white xl:text-4xl">
                            Tu operación te espera.
                        </h1>
                        <p class="mt-4 leading-7 text-slate-300">
                            Una sola cuenta para acceder a la operación completa de tu negocio, estés donde estés.
                        </p>
                    </div>
                </section>

                <section class="flex max-h-full justify-center overflow-y-auto lg:justify-end">
                    <div class="login-panel relative w-full max-w-md overflow-hidden rounded-2xl border border-line bg-surface p-6 shadow-xl shadow-slate-950/10 dark:shadow-black/30 sm:p-8">
                        <slot />
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>

<style scoped>
.login-panel::before {
    position: absolute;
    inset: 0 0 auto;
    height: 3px;
    content: '';
    background: linear-gradient(90deg, #2563eb, #38bdf8 55%, transparent);
}

.guest-landing main {
    padding-right: env(safe-area-inset-right);
    padding-left: env(safe-area-inset-left);
}

.guest-texture {
    background-image:
        repeating-linear-gradient(22.5deg, transparent, transparent 2px, rgb(75 85 99 / 6%) 2px, rgb(75 85 99 / 6%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(67.5deg, transparent, transparent 2px, rgb(107 114 128 / 5%) 2px, rgb(107 114 128 / 5%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(112.5deg, transparent, transparent 2px, rgb(55 65 81 / 4%) 2px, rgb(55 65 81 / 4%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(157.5deg, transparent, transparent 2px, rgb(31 41 55 / 3%) 2px, rgb(31 41 55 / 3%) 3px, transparent 3px, transparent 8px);
}

</style>

<style>
/* Dark-mode override: kept unscoped because Vue's :global(.dark) .foo
   combinator form does not compile reliably in this build pipeline. */
.dark .guest-texture {
    background-image:
        repeating-linear-gradient(22.5deg, transparent, transparent 2px, rgb(16 185 129 / 18%) 2px, rgb(16 185 129 / 18%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(67.5deg, transparent, transparent 2px, rgb(245 101 101 / 10%) 2px, rgb(245 101 101 / 10%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(112.5deg, transparent, transparent 2px, rgb(234 179 8 / 8%) 2px, rgb(234 179 8 / 8%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(157.5deg, transparent, transparent 2px, rgb(249 115 22 / 6%) 2px, rgb(249 115 22 / 6%) 3px, transparent 3px, transparent 8px);
}
</style>
