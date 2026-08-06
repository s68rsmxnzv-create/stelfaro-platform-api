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
    <div class="guest-landing sf-safe-screen min-h-screen w-full max-w-full overflow-x-clip bg-app text-text">
        <header class="public-header fixed inset-x-0 top-0 z-50 border-b border-white/10 text-white">
            <div class="mx-auto flex h-[calc(4rem+env(safe-area-inset-top))] max-w-7xl items-center justify-between gap-3 pb-0 pl-[max(1rem,env(safe-area-inset-left))] pr-[max(1rem,env(safe-area-inset-right))] pt-[env(safe-area-inset-top)] sm:h-[calc(5rem+env(safe-area-inset-top))] sm:px-5 sm:pt-[env(safe-area-inset-top)] lg:h-[calc(6rem+env(safe-area-inset-top))] lg:px-8">
                <Link href="/" class="group flex min-w-0 items-center gap-2.5" aria-label="Volver al inicio de StelFaro">
                    <img src="/pwa/stelfaro-mark-on-dark.svg" alt="" class="h-10 w-9 shrink-0 object-contain sm:h-12 sm:w-11 lg:h-16 lg:w-14" />
                    <span>
                        <strong class="block truncate text-base leading-none tracking-tight sm:text-lg lg:text-xl">StelFaro</strong>
                        <span class="mt-1.5 hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-300 sm:block">
                            Tu negocio, más simple
                        </span>
                    </span>
                </Link>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-lg border border-white/10 bg-white/5 text-white transition hover:bg-white/15 sm:h-11 sm:w-11"
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
                    <Link href="/" class="inline-flex h-10 items-center rounded-lg border border-white/10 bg-white/5 px-3 text-sm font-bold text-slate-200 transition hover:bg-white/15 hover:text-white sm:h-11 sm:px-4">
                        <span aria-hidden="true">←</span>
                        <span class="ml-2 hidden sm:inline">Volver al inicio</span>
                    </Link>
                </div>
            </div>
            <div class="header-accent absolute inset-x-0 bottom-[-1px] h-px" aria-hidden="true"></div>
        </header>

        <main class="relative isolate overflow-hidden pt-[calc(4rem+env(safe-area-inset-top))] sm:pt-[calc(5rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]">
            <div class="hero-glow pointer-events-none absolute inset-0 -z-10" aria-hidden="true"></div>

            <div class="mx-auto grid min-h-[calc(100vh-4rem-env(safe-area-inset-top))] max-w-7xl items-center gap-10 px-4 py-10 sm:min-h-[calc(100vh-5rem-env(safe-area-inset-top))] sm:px-5 sm:py-14 lg:min-h-[calc(100vh-6rem-env(safe-area-inset-top))] lg:grid-cols-[0.92fr_1.08fr] lg:px-8 lg:py-16">
                <section class="hidden max-w-xl lg:block">
                    <p class="mb-5 text-sm font-semibold text-primary">El mismo espacio para toda tu operación.</p>
                    <h1 class="text-5xl font-black leading-[1.05] tracking-[-0.04em] xl:text-6xl">
                        Tu operación te espera.
                    </h1>
                    <p class="mt-6 text-lg leading-8 text-muted">
                        Una sola cuenta para acceder a la operación completa de tu negocio, estés donde estés.
                    </p>

                    <div class="mt-10 divide-y divide-line border-y border-line">
                        <div class="flex items-center gap-4 py-5">
                            <span class="font-mono text-xs font-black text-primary">01</span>
                            <div>
                                <strong class="block">Un solo acceso</strong>
                                <span class="text-sm text-muted">Entramos directamente al espacio que te corresponde.</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 py-5">
                            <span class="font-mono text-xs font-black text-success">02</span>
                            <div>
                                <strong class="block">Sesión protegida</strong>
                                <span class="text-sm text-muted">Tus permisos y empresas permanecen separados y seguros.</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="flex justify-center lg:justify-end">
                    <div class="login-panel relative w-full max-w-md overflow-hidden rounded-2xl border border-line bg-surface p-6 shadow-xl shadow-slate-950/10 dark:shadow-black/30 sm:p-8">
                        <slot />
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>

<style scoped>
.public-header {
    background:
        radial-gradient(circle at 18% 0%, rgb(37 99 235 / 24%), transparent 30%),
        linear-gradient(105deg, #07162f 0%, #0a1b3d 55%, #0d2854 100%);
}

.header-accent {
    background: linear-gradient(90deg, transparent 8%, #2563eb 38%, #38bdf8 50%, #2563eb 62%, transparent 92%);
    opacity: 0.65;
}

.hero-glow {
    background:
        radial-gradient(circle at 78% 28%, rgb(14 165 233 / 13%), transparent 28rem),
        radial-gradient(circle at 12% 78%, rgb(37 99 235 / 8%), transparent 24rem);
}

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

@supports (background: color-mix(in srgb, black 90%, transparent)) {
    .public-header {
        background:
            radial-gradient(circle at 18% 0%, rgb(37 99 235 / 22%), transparent 30%),
            color-mix(in srgb, #071a38 93%, transparent);
        backdrop-filter: blur(18px) saturate(125%);
    }
}
</style>
