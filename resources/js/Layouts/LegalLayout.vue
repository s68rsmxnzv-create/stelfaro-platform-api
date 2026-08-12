<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Home } from 'lucide-vue-next';

defineProps({
    title: { type: String, required: true },
});

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
    <div class="legal-page sf-safe-screen min-h-screen w-full max-w-full overflow-x-clip bg-app text-text">
        <Head>
            <title>{{ title }} · StelFaro</title>
        </Head>

        <header class="fixed inset-x-0 top-0 z-50 border-b border-line bg-app">
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

        <main class="mx-auto max-w-3xl px-5 py-14 pt-[calc(4rem+2.5rem)] sm:px-8 sm:pt-[calc(5rem+3rem)] lg:pt-[calc(6rem+3rem)]">
            <p class="text-sm font-semibold text-primary">Legal</p>
            <h1 class="mt-3 text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">{{ title }}</h1>

            <div class="legal-prose mt-10 space-y-6 leading-7 text-muted">
                <slot />
            </div>
        </main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto flex max-w-3xl flex-col gap-3 px-5 py-8 text-sm text-muted sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <span><strong class="text-text">StelFaro</strong> · Tecnología salvadoreña para trabajar mejor.</span>
                <div class="flex gap-5">
                    <Link href="/terminos-y-condiciones" class="hover:text-primary">Términos y condiciones</Link>
                    <Link href="/politica-de-privacidad" class="hover:text-primary">Política de privacidad</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
