<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const themeStorageKey = 'stelfaro:theme';
const isDark = ref(false);

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

    <div class="landing min-h-screen bg-app text-text">
        <header class="public-header fixed inset-x-0 top-0 z-50 border-b border-white/10 text-white">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-5 sm:h-20 lg:px-8">
                <a href="/" class="flex items-center gap-3" aria-label="Stelfaro, inicio">
                    <img src="/pwa/stelfaro.svg" alt="" class="h-10 w-10 rounded-full sm:h-11 sm:w-11" />
                    <span>
                        <strong class="block text-lg leading-none tracking-tight">Stelfaro</strong>
                        <span class="mt-1 hidden text-[10px] font-semibold uppercase tracking-[0.18em] text-sky-300 sm:block">
                            Tu negocio, más simple
                        </span>
                    </span>
                </a>

                <nav class="hidden items-center gap-8 text-sm font-medium text-slate-300 md:flex">
                    <a href="#soluciones" class="transition hover:text-white">Soluciones</a>
                    <a href="#forma-de-trabajo" class="transition hover:text-white">Cómo trabajamos</a>
                    <a href="#contacto" class="transition hover:text-white">Contacto</a>
                </nav>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="grid h-11 w-11 place-items-center rounded-lg border border-white/10 bg-white/5 text-white transition hover:bg-white/15"
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
                    <Link href="/login" class="inline-flex h-11 items-center rounded-lg border border-white/15 bg-white/10 px-4 text-sm font-bold transition hover:bg-white hover:text-slate-950">
                        <span class="sm:hidden">Entrar</span>
                        <span class="hidden sm:inline">Iniciar sesión</span>
                    </Link>
                </div>
            </div>
        </header>

        <main>
            <section class="hero-grid relative overflow-hidden border-b border-line pt-16 sm:pt-20">
                <div class="mx-auto grid min-h-[740px] max-w-7xl items-center gap-14 px-5 py-16 lg:grid-cols-[1.02fr_0.98fr] lg:px-8 lg:py-24">
                    <div class="relative z-10">
                        <p class="mb-8 border-l-2 border-primary pl-4 text-sm font-semibold text-muted">
                            Diseñado y acompañado desde El Salvador.
                        </p>

                        <h1 class="max-w-4xl text-[3.35rem] font-black leading-[0.98] tracking-[-0.055em] sm:text-7xl lg:text-[5.4rem]">
                            Tu negocio merece
                            <span class="text-primary">menos vueltas.</span>
                        </h1>

                        <p class="mt-7 max-w-xl text-lg leading-8 text-muted sm:text-xl">
                            Facturación electrónica y operación diaria en una aplicación que se siente clara desde el primer uso.
                        </p>

                        <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                            <a
                                href="mailto:soporte@stelfaro.com?subject=Quiero conocer Stelfaro"
                                class="inline-flex h-14 items-center justify-center rounded-lg bg-primary px-7 text-base font-black text-primary-contrast shadow-lg shadow-sky-900/10 transition hover:-translate-y-0.5 hover:bg-primary-hover"
                            >
                                Conversemos
                                <span class="ml-3" aria-hidden="true">→</span>
                            </a>
                            <a href="#soluciones" class="inline-flex h-14 items-center justify-center rounded-lg border border-line-strong bg-surface/60 px-7 text-base font-bold text-text backdrop-blur transition hover:bg-surface">
                                Explorar soluciones
                            </a>
                        </div>

                        <p class="mt-7 max-w-lg text-sm leading-6 text-soft">
                            Te ayudamos a configurar la empresa, preparar la facturación y acompañar a tu equipo al comenzar.
                        </p>
                    </div>

                    <div class="relative mx-auto w-full max-w-[570px] lg:ml-auto">
                        <div class="product-window relative overflow-hidden border border-line bg-surface/75 shadow-2xl shadow-slate-950/20 backdrop-blur-xl">
                            <div class="flex h-14 items-center border-b border-line px-5">
                                <span class="h-2.5 w-2.5 rounded-full bg-danger"></span>
                                <span class="ml-2 h-2.5 w-2.5 rounded-full bg-warning"></span>
                                <span class="ml-2 h-2.5 w-2.5 rounded-full bg-success"></span>
                                <span class="ml-auto text-[10px] font-bold uppercase tracking-[0.18em] text-soft">Miércoles · 10:24 a. m.</span>
                            </div>

                            <div class="p-5 sm:p-7">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[0.16em] text-primary">Casa matriz</p>
                                        <h2 class="mt-2 text-2xl font-black">Buenos días, Ana.</h2>
                                    </div>
                                    <span class="inline-flex items-center gap-2 text-xs font-bold text-success">
                                        <span class="h-2 w-2 rounded-full bg-success"></span> Operando
                                    </span>
                                </div>

                                <div class="mt-7 grid grid-cols-2 border-y border-line">
                                    <div class="border-r border-line py-5 pr-5">
                                        <span class="text-sm text-muted">Ventas de hoy</span>
                                        <strong class="mt-2 block text-3xl tracking-tight">$460.00</strong>
                                    </div>
                                    <div class="py-5 pl-5">
                                        <span class="text-sm text-muted">Órdenes activas</span>
                                        <strong class="mt-2 block text-3xl tracking-tight">8</strong>
                                    </div>
                                </div>

                                <div class="mt-6">
                                    <p class="mb-3 text-xs font-black uppercase tracking-[0.16em] text-soft">Continuar trabajando</p>
                                    <button type="button" class="flex w-full items-center gap-4 rounded-lg bg-primary p-4 text-left text-primary-contrast">
                                        <span class="grid h-11 w-11 place-items-center rounded-md bg-white/15 text-xl">+</span>
                                        <span>
                                            <strong class="block">Emitir factura</strong>
                                            <span class="mt-0.5 block text-xs opacity-80">Factura, crédito fiscal o nota</span>
                                        </span>
                                        <span class="ml-auto text-xl">→</span>
                                    </button>
                                </div>

                                <div class="mt-3 flex items-center gap-3 rounded-lg border border-line bg-surface-muted/70 p-4">
                                    <span class="grid h-10 w-10 place-items-center rounded-md bg-success-soft font-black text-success">✓</span>
                                    <div class="min-w-0">
                                        <strong class="block text-sm">DTE aceptado por Hacienda</strong>
                                        <span class="block truncate text-xs text-muted">Factura DTE-01-M001P001-0000000000042</span>
                                    </div>
                                    <span class="ml-auto text-xs text-soft">10:19</span>
                                </div>
                            </div>
                        </div>

                        <div class="absolute -bottom-8 -left-5 hidden w-56 border border-line bg-surface/85 p-4 shadow-xl backdrop-blur-xl sm:block">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-soft">También en el móvil</p>
                            <div class="mt-3 flex items-center gap-3">
                                <span class="grid h-10 w-10 place-items-center rounded-md bg-primary-soft text-primary">▯</span>
                                <span class="text-sm font-bold">Instálalo como app</span>
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

            <section id="soluciones" class="section-wash py-20 sm:py-28">
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

            <section id="contacto" class="section-wash px-5 py-20 sm:py-28">
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
                    <img src="/pwa/stelfaro.svg" alt="" class="h-9 w-9 rounded-full" />
                    <span><strong class="text-text">Stelfaro</strong> · Tecnología salvadoreña para trabajar mejor.</span>
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
    background: var(--sf-color-navbar);
}

@supports (background: color-mix(in srgb, black 90%, transparent)) {
    .public-header {
        background: color-mix(in srgb, var(--sf-color-navbar) 88%, transparent);
        backdrop-filter: blur(18px) saturate(125%);
    }
}

.hero-grid,
.section-wash {
    background-color: var(--sf-color-app);
    background-image:
        linear-gradient(to right, color-mix(in srgb, var(--sf-color-line) 55%, transparent) 1px, transparent 1px),
        linear-gradient(to bottom, color-mix(in srgb, var(--sf-color-line) 45%, transparent) 1px, transparent 1px);
    background-size: 64px 64px;
}

.hero-grid::after {
    position: absolute;
    inset: 0;
    z-index: 0;
    content: '';
    pointer-events: none;
    background:
        linear-gradient(90deg, var(--sf-color-app) 0%, transparent 35%, transparent 70%, var(--sf-color-app) 100%),
        linear-gradient(0deg, var(--sf-color-app) 0%, transparent 25%);
    opacity: 0.72;
}

.hero-grid > div {
    position: relative;
    z-index: 1;
}

.product-window {
    border-radius: 1.25rem 1.25rem 1.25rem 0.25rem;
}

@media (max-width: 640px) {
    .hero-grid,
    .section-wash {
        background-size: 40px 40px;
    }
}
</style>
