<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const themeStorageKey = 'stelfaro:theme';
const isDark = ref(false);
const configuredDemoVideoId = String(import.meta.env.VITE_STELFARO_DEMO_VIDEO_ID || '').trim();
const demoVideoId = /^[\w-]{11}$/.test(configuredDemoVideoId) ? configuredDemoVideoId : '';

const solutions = [
    {
        number: '01',
        name: 'Facturación',
        title: 'Emitir un DTE no debería detener tu día.',
        description: 'Factura, cobra y revisa la respuesta de Hacienda desde un flujo que tu equipo puede entender.',
        features: ['Factura y crédito fiscal', 'Notas y sujeto excluido', 'Clientes, catálogo y caja', 'Comprobantes organizados'],
        accent: 'primary',
    },
    {
        number: '02',
        name: 'Taller',
        title: 'Cada equipo conserva su historia completa.',
        description: 'Desde que lo recibes hasta que lo entregas: fotografías, diagnóstico, aprobación, reparación y cobro.',
        features: ['Recepción desde el móvil', 'Diagnóstico y presupuesto', 'Aprobación del cliente', 'Entrega y facturación'],
        accent: 'success',
    },
];

const principles = [
    ['Pensado para trabajar', 'Las acciones frecuentes están primero; la información secundaria aparece cuando hace falta.'],
    ['Móvil de verdad', 'No encogemos la pantalla de escritorio. Cada flujo se adapta a la forma en que se usa el teléfono.'],
    ['Acompañamiento humano', 'Configuramos contigo y escuchamos cómo opera tu negocio antes de pedirte que cambies procesos.'],
];

function applyTheme(darkMode) {
    isDark.value = darkMode;
    document.documentElement.classList.toggle('dark', darkMode);
    document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
    window.localStorage.setItem(themeStorageKey, darkMode ? 'dark' : 'light');
}

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});
</script>

<template>
    <Head>
        <title>Stelfaro — Tu negocio, más simple</title>
        <meta
            head-key="description"
            name="description"
            content="Facturación electrónica y gestión de talleres para negocios en El Salvador. Una aplicación clara, práctica y acompañada."
        />
    </Head>

    <div class="landing min-h-screen w-full max-w-full overflow-x-clip bg-app text-text">
        <header class="public-header fixed inset-x-0 top-0 z-50 border-b border-white/10 text-white">
            <div class="mx-auto flex h-[calc(4rem+env(safe-area-inset-top))] max-w-7xl items-center justify-between gap-3 pb-0 pl-[max(1rem,env(safe-area-inset-left))] pr-[max(1rem,env(safe-area-inset-right))] pt-[env(safe-area-inset-top)] sm:h-[calc(5rem+env(safe-area-inset-top))] sm:px-5 sm:pt-[env(safe-area-inset-top)] lg:h-[calc(6rem+env(safe-area-inset-top))] lg:px-8">
                <a href="/" class="group flex min-w-0 items-center gap-2.5" aria-label="StelFaro, inicio">
                    <img src="/pwa/stelfaro-mark-on-dark.svg" alt="" class="h-10 w-9 shrink-0 object-contain sm:h-12 sm:w-11 lg:h-16 lg:w-14" />
                    <span>
                        <strong class="block truncate text-base leading-none tracking-tight sm:text-lg lg:text-xl">StelFaro</strong>
                        <span class="mt-1.5 hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-sky-300 lg:block">
                            Tu negocio, más simple
                        </span>
                    </span>
                </a>

                <nav class="hidden items-center gap-1 rounded-xl border border-white/10 bg-slate-950/15 p-1 text-sm font-semibold text-slate-300 shadow-inner md:flex" aria-label="Navegación principal">
                    <a href="#soluciones" class="rounded-lg px-4 py-2.5 transition hover:bg-white/10 hover:text-white">Soluciones</a>
                    <a href="#forma-de-trabajo" class="rounded-lg px-4 py-2.5 transition hover:bg-white/10 hover:text-white">Cómo trabajamos</a>
                    <a href="#conoce-la-app" class="rounded-lg px-4 py-2.5 transition hover:bg-white/10 hover:text-white">Conoce la app</a>
                </nav>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-lg border border-white/10 bg-white/5 text-white transition hover:bg-white/15 sm:h-11 sm:w-11"
                        :aria-label="isDark ? 'Activar modo claro' : 'Activar modo oscuro'"
                        @click="applyTheme(!isDark)"
                    >
                        <svg v-if="isDark" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" />
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" />
                        </svg>
                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" />
                        </svg>
                    </button>
                    <Link href="/login" class="inline-flex h-10 items-center rounded-lg border border-white/15 bg-white/10 px-3 text-sm font-bold transition hover:border-white/30 hover:bg-white/15 sm:h-11 sm:px-4">
                        <span class="sm:hidden">Entrar</span>
                        <span class="hidden sm:inline">Iniciar sesión</span>
                    </Link>
                    <a
                        href="mailto:soporte@stelfaro.com?subject=Quiero conocer StelFaro"
                        class="hidden h-11 items-center rounded-lg bg-primary px-5 text-sm font-black text-primary-contrast shadow-lg shadow-sky-950/20 transition hover:bg-primary-hover xl:inline-flex"
                    >
                        Solicitar demo
                    </a>
                </div>
            </div>
            <div class="header-accent absolute inset-x-0 bottom-[-1px] h-px" aria-hidden="true"></div>
        </header>

        <main class="min-w-0">
            <section class="hero-home relative overflow-hidden border-b border-line bg-app pt-[calc(4rem+env(safe-area-inset-top))] sm:pt-[calc(5rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]">
                <div class="hero-glow pointer-events-none absolute inset-0" aria-hidden="true"></div>
                <div class="mx-auto grid min-w-0 max-w-7xl items-center gap-10 px-4 py-12 sm:gap-12 sm:px-5 sm:py-16 lg:min-h-[680px] lg:grid-cols-[0.92fr_1.08fr] lg:px-8 lg:py-20">
                    <div class="relative z-10 min-w-0">
                        <p class="mb-5 text-sm font-semibold text-primary">
                            Facturación y operación para negocios salvadoreños
                        </p>

                        <h1 class="max-w-2xl text-[2.5rem] font-black leading-[1.05] tracking-[-0.04em] min-[390px]:text-[2.75rem] sm:text-6xl">
                            Una forma más simple de llevar tu negocio.
                        </h1>

                        <p class="mt-5 max-w-xl text-base leading-7 text-muted sm:text-lg sm:leading-8">
                            Emite DTE, organiza clientes y controla la operación diaria desde una aplicación clara en computadora, tablet y móvil.
                        </p>

                        <div class="mt-7 grid gap-3 sm:flex sm:flex-row">
                            <a
                                href="mailto:soporte@stelfaro.com?subject=Quiero conocer Stelfaro"
                                class="inline-flex h-12 items-center justify-center rounded-lg bg-primary px-6 text-sm font-bold text-primary-contrast transition hover:bg-primary-hover"
                            >
                                Solicitar demostración
                            </a>
                            <a href="#soluciones" class="inline-flex h-12 items-center justify-center rounded-lg border border-line bg-surface px-6 text-sm font-bold text-text transition hover:bg-surface-muted">
                                Ver soluciones
                            </a>
                        </div>

                        <ul class="mt-7 grid gap-2 text-sm text-muted sm:grid-cols-2">
                            <li class="flex items-center gap-2"><span class="text-success">✓</span> Configuración acompañada</li>
                            <li class="flex items-center gap-2"><span class="text-success">✓</span> Instalable como aplicación</li>
                            <li class="flex items-center gap-2"><span class="text-success">✓</span> Acceso seguro por usuario</li>
                            <li class="flex items-center gap-2"><span class="text-success">✓</span> Diseñado para El Salvador</li>
                        </ul>
                    </div>

                    <div id="conoce-la-app" class="relative mx-auto w-full min-w-0 max-w-[610px] scroll-mt-28 lg:ml-auto">
                        <div class="demo-frame relative overflow-hidden rounded-2xl border border-white/10 bg-[#07162f] shadow-2xl shadow-slate-950/20">
                            <div class="flex h-12 items-center border-b border-white/10 px-4 sm:px-5">
                                <div class="flex gap-1.5" aria-hidden="true">
                                    <span class="h-2.5 w-2.5 rounded-full bg-[#ff6b6b]"></span>
                                    <span class="h-2.5 w-2.5 rounded-full bg-[#ffd166]"></span>
                                    <span class="h-2.5 w-2.5 rounded-full bg-[#4ade80]"></span>
                                </div>
                                <span class="ml-auto text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Conoce StelFaro</span>
                            </div>

                            <div class="aspect-video">
                                <iframe
                                    v-if="demoVideoId"
                                    class="h-full w-full"
                                    :src="`https://www.youtube-nocookie.com/embed/${demoVideoId}?rel=0`"
                                    title="Cómo funciona StelFaro"
                                    loading="lazy"
                                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    allowfullscreen
                                ></iframe>
                                <div v-else class="demo-placeholder relative flex h-full items-center justify-center overflow-hidden p-7 text-center text-white">
                                    <div class="demo-grid absolute inset-0 opacity-30" aria-hidden="true"></div>
                                    <div class="relative max-w-md">
                                        <span class="mx-auto grid h-16 w-16 place-items-center rounded-full border border-white/20 bg-white/10 shadow-xl backdrop-blur-sm" aria-hidden="true">
                                            <svg class="ml-1 h-7 w-7" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M8 5v14l11-7Z" />
                                            </svg>
                                        </span>
                                        <strong class="mt-5 block text-xl sm:text-2xl">Mira StelFaro en acción</strong>
                                        <span class="mt-2 block text-sm leading-6 text-slate-300">
                                            Un recorrido breve por facturación, taller y la experiencia desde el móvil.
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-line bg-surface">
                <div class="mx-auto grid max-w-7xl divide-y divide-line px-5 md:grid-cols-3 md:divide-x md:divide-y-0 lg:px-8">
                    <div v-for="([title, text], index) in principles" :key="title" class="py-8 md:px-7 md:first:pl-0 md:last:pr-0">
                        <span class="text-xs font-black text-primary">0{{ index + 1 }}</span>
                        <h2 class="mt-3 text-lg font-black">{{ title }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ text }}</p>
                    </div>
                </div>
            </section>

            <section id="soluciones" class="bg-app py-16 sm:py-24">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="grid gap-7 border-b border-line pb-10 lg:grid-cols-[0.7fr_1.3fr] lg:items-end">
                        <p class="text-sm font-semibold text-primary">Puedes comenzar con facturación o con la gestión de tu taller.</p>
                        <h2 class="max-w-3xl text-4xl font-black leading-tight tracking-[-0.035em] sm:text-5xl">
                            Tecnología que se adapta a la operación, no al revés.
                        </h2>
                    </div>

                    <div class="divide-y divide-line">
                        <article v-for="solution in solutions" :key="solution.name" class="grid gap-7 py-12 lg:grid-cols-[0.35fr_0.9fr_1fr] lg:gap-12 lg:py-16">
                            <div>
                                <span class="text-sm font-black" :class="solution.accent === 'success' ? 'text-success' : 'text-primary'">{{ solution.number }}</span>
                                <p class="mt-2 text-sm font-black uppercase tracking-[0.16em] text-soft">{{ solution.name }}</p>
                            </div>
                            <div>
                                <h3 class="text-3xl font-black leading-tight tracking-tight">{{ solution.title }}</h3>
                                <p class="mt-4 leading-7 text-muted">{{ solution.description }}</p>
                            </div>
                            <ul class="grid content-start gap-x-8 gap-y-4 sm:grid-cols-2">
                                <li v-for="feature in solution.features" :key="feature" class="flex items-center gap-3 border-b border-line pb-4 text-sm font-semibold">
                                    <span :class="solution.accent === 'success' ? 'text-success' : 'text-primary'">✓</span>
                                    {{ feature }}
                                </li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section id="forma-de-trabajo" class="border-y border-line bg-[var(--sf-color-navbar)] py-20 text-white sm:py-24">
                <div class="mx-auto grid max-w-7xl gap-14 px-5 lg:grid-cols-[0.85fr_1.15fr] lg:px-8">
                    <div>
                        <p class="border-l-2 border-sky-300 pl-4 text-sm font-semibold text-slate-300">No te dejamos solo con el sistema.</p>
                        <h2 class="mt-5 text-4xl font-black leading-tight tracking-tight sm:text-5xl">Primero entendemos cómo trabajas.</h2>
                        <p class="mt-5 max-w-xl leading-7 text-slate-300">
                            Stelfaro se configura alrededor de tu empresa. Nuestro trabajo no termina cuando entregamos una contraseña.
                        </p>
                    </div>

                    <ol class="border-t border-white/15">
                        <li v-for="(step, index) in ['Conocemos tu operación y tus prioridades', 'Configuramos empresa, sucursales y facturación', 'Preparamos a tu equipo con casos reales', 'Te acompañamos durante la puesta en marcha']" :key="step" class="grid grid-cols-[3rem_1fr] gap-4 border-b border-white/15 py-5">
                            <span class="font-mono text-sm text-sky-300">{{ String(index + 1).padStart(2, '0') }}</span>
                            <strong class="text-lg">{{ step }}</strong>
                        </li>
                    </ol>
                </div>
            </section>

            <section id="contacto" class="bg-app px-5 py-16 sm:py-24">
                <div class="mx-auto grid max-w-7xl overflow-hidden border border-line bg-surface/70 shadow-surface backdrop-blur-xl lg:grid-cols-[1.2fr_0.8fr]">
                    <div class="p-7 sm:p-12">
                        <p class="text-sm font-semibold text-primary">Hablemos de tu negocio.</p>
                        <h2 class="mt-5 max-w-2xl text-4xl font-black leading-tight tracking-tight sm:text-5xl">Cuéntanos qué quieres simplificar.</h2>
                        <p class="mt-5 max-w-2xl text-lg leading-8 text-muted">
                            Te mostramos el producto con situaciones parecidas a las de tu operación, sin una presentación genérica.
                        </p>
                    </div>
                    <div class="flex flex-col justify-center border-t border-line bg-surface-muted/60 p-7 lg:border-l lg:border-t-0 sm:p-10">
                        <a href="mailto:soporte@stelfaro.com?subject=Quiero conocer Stelfaro" class="inline-flex h-14 items-center justify-center rounded-lg bg-primary px-7 font-black text-primary-contrast transition hover:bg-primary-hover">
                            Solicitar una conversación
                        </a>
                        <a href="mailto:soporte@stelfaro.com" class="mt-5 text-center text-sm font-semibold text-muted hover:text-primary">soporte@stelfaro.com</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-8 text-sm text-muted sm:flex-row sm:items-center sm:justify-between lg:px-8">
                <div class="flex items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center" aria-hidden="true">
                        <img src="/pwa/stelfaro-mark-on-light.svg" alt="" class="h-8 w-7 object-contain dark:hidden" />
                        <img src="/pwa/stelfaro-mark-on-dark.svg" alt="" class="hidden h-8 w-7 object-contain dark:block" />
                    </span>
                    <span><strong class="text-text">StelFaro</strong> · Tecnología salvadoreña para trabajar mejor.</span>
                </div>
                <div class="flex gap-5">
                    <a href="#soluciones" class="hover:text-primary">Soluciones</a>
                    <Link href="/login" class="hover:text-primary">Iniciar sesión</Link>
                </div>
            </div>
        </footer>
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

@supports (background: color-mix(in srgb, black 90%, transparent)) {
    .public-header {
        background:
            radial-gradient(circle at 18% 0%, rgb(37 99 235 / 22%), transparent 30%),
            color-mix(in srgb, #071a38 93%, transparent);
        backdrop-filter: blur(18px) saturate(125%);
    }
}

.hero-home {
    isolation: isolate;
}

.hero-glow {
    z-index: -1;
    background:
        radial-gradient(circle at 78% 28%, rgb(14 165 233 / 13%), transparent 28rem),
        radial-gradient(circle at 12% 78%, rgb(37 99 235 / 8%), transparent 24rem);
}

.demo-placeholder {
    background:
        radial-gradient(circle at 70% 20%, rgb(37 99 235 / 36%), transparent 34%),
        linear-gradient(135deg, #081a38, #0a1b3d 55%, #103872);
}

.demo-grid {
    background-image:
        linear-gradient(rgb(255 255 255 / 8%) 1px, transparent 1px),
        linear-gradient(90deg, rgb(255 255 255 / 8%) 1px, transparent 1px);
    background-size: 40px 40px;
}

.landing main,
.landing footer {
    padding-right: env(safe-area-inset-right);
    padding-left: env(safe-area-inset-left);
}

</style>
