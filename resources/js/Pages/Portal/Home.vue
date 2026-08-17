<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const themeStorageKey = 'stelfaro:theme';
const isDark = ref(false);
const mobileNavOpen = ref(false);
const configuredDemoVideoId = String(import.meta.env.VITE_STELFARO_DEMO_VIDEO_ID || '').trim();
const demoVideoId = /^[\w-]{11}$/.test(configuredDemoVideoId) ? configuredDemoVideoId : '';
const whatsappNumber = String(import.meta.env.VITE_STELFARO_WHATSAPP_NUMBER || '50375640652').replace(/\D/g, '');
const whatsappDemoUrl = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent('Hola, quiero conocer StelFaro y solicitar una demostración.')}`;

const platformModules = [
    {
        number: '01',
        name: 'Facturación',
        title: 'Vender, cobrar y emitir sin romper el ritmo.',
        description: 'La operación comercial y el cumplimiento fiscal viven en el mismo flujo, desde la venta hasta la respuesta de Hacienda.',
        features: ['Factura y crédito fiscal', 'Notas y sujeto excluido', 'Caja y formas de pago', 'Comprobantes organizados'],
        accent: 'primary',
    },
    {
        number: '02',
        name: 'Taller',
        title: 'Cada equipo conserva su historia completa.',
        description: 'Recepción, fotografías, diagnóstico, aprobación, reparación y cobro conectados a la misma información del negocio.',
        features: ['Recepción desde el móvil', 'Diagnóstico y presupuesto', 'Aprobación del cliente', 'Entrega y facturación'],
        accent: 'success',
    },
    {
        number: '03',
        name: 'Nuevas operaciones',
        title: 'La plataforma está preparada para crecer contigo.',
        description: 'Nuevos módulos pueden incorporar procesos propios sin duplicar clientes, usuarios, sucursales ni información fiscal.',
        features: ['Una identidad empresarial', 'Permisos compartidos', 'Datos conectados', 'Nuevos verticales'],
        accent: 'primary',
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

const vReveal = {
    mounted(el, binding) {
        el.classList.add('reveal');
        if (typeof binding.value === 'number') {
            el.style.transitionDelay = `${binding.value}ms`;
        }
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        el.classList.add('is-visible');
                        observer.unobserve(el);
                    }
                });
            },
            { threshold: 0.15, rootMargin: '0px 0px -60px 0px' },
        );
        observer.observe(el);
    },
};
</script>

<template>
    <Head>
        <title>Tu negocio, más simple</title>
        <meta
            head-key="description"
            name="description"
            content="Facturación electrónica y gestión de talleres para negocios en El Salvador. Una aplicación clara, práctica y acompañada."
        />
    </Head>

    <div class="landing min-h-screen w-full max-w-full overflow-x-clip text-text">
        <div class="landing-texture" aria-hidden="true"></div>

        <svg class="absolute h-0 w-0 overflow-hidden" aria-hidden="true" focusable="false">
            <defs>
                <filter id="liquid-glass" x="-15%" y="-40%" width="130%" height="180%" color-interpolation-filters="sRGB">
                    <feTurbulence type="fractalNoise" baseFrequency="0.004 0.007" numOctaves="2" seed="7" result="noise" />
                    <feGaussianBlur in="noise" stdDeviation="4" result="softNoise" />
                    <feDisplacementMap in="SourceGraphic" in2="softNoise" scale="45" xChannelSelector="R" yChannelSelector="G" />
                </filter>
            </defs>
        </svg>

        <header class="public-header header-glass fixed inset-x-0 top-0 z-50 overflow-hidden">
            <div class="relative z-10 mx-auto flex h-[calc(4rem+env(safe-area-inset-top))] max-w-7xl items-center justify-between gap-4 pb-0 pl-[max(1rem,env(safe-area-inset-left))] pr-[max(1rem,env(safe-area-inset-right))] pt-[env(safe-area-inset-top)] sm:h-[calc(5rem+env(safe-area-inset-top))] sm:px-5 sm:pt-[env(safe-area-inset-top)] lg:h-[calc(6rem+env(safe-area-inset-top))] lg:px-8">
                <a href="/" class="group relative flex min-w-0 items-center gap-2.5" aria-label="StelFaro, inicio">
                    <span class="beacon-glow" aria-hidden="true"></span>
                    <img src="/pwa/stelfaro-mark-on-light.svg" alt="" class="h-10 w-9 shrink-0 object-contain sm:h-12 sm:w-11 lg:h-16 lg:w-14" />
                    <span>
                        <strong class="block truncate text-base leading-none tracking-tight text-text sm:text-lg lg:text-xl">StelFaro</strong>
                        <span class="mt-1.5 hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-primary lg:block">
                            Tu negocio, más simple
                        </span>
                    </span>
                </a>

                <nav class="hidden items-center gap-1 text-base font-semibold text-muted min-[860px]:flex" aria-label="Navegación principal">
                    <a href="#soluciones" class="whitespace-nowrap rounded-lg px-3 py-2.5 transition hover:text-primary lg:px-5">Soluciones</a>
                    <a href="#forma-de-trabajo" class="whitespace-nowrap rounded-lg px-3 py-2.5 transition hover:text-primary lg:px-5">Cómo trabajamos</a>
                    <a href="#conoce-la-app" class="whitespace-nowrap rounded-lg px-3 py-2.5 transition hover:text-primary lg:px-5">Conoce la app</a>
                </nav>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-lg border border-line text-muted transition hover:bg-surface-muted min-[860px]:hidden"
                        :aria-label="mobileNavOpen ? 'Cerrar menú' : 'Abrir menú'"
                        :aria-expanded="mobileNavOpen"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        <svg v-if="mobileNavOpen" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18" />
                        </svg>
                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-lg border border-line text-muted transition hover:bg-surface-muted sm:h-11 sm:w-11"
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
                    <Link href="/login" class="inline-flex h-10 items-center rounded-lg border border-line px-3 text-sm font-bold text-text transition hover:bg-surface-muted sm:h-11 sm:px-4">
                        <span class="sm:hidden">Entrar</span>
                        <span class="hidden sm:inline">Iniciar sesión</span>
                    </Link>
                    <a
                        :href="whatsappDemoUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hidden h-11 items-center gap-2 rounded-lg bg-primary px-5 text-sm font-bold text-primary-contrast shadow-lg shadow-sky-950/10 transition hover:bg-primary-hover xl:inline-flex"
                        aria-label="Solicitar demostración por WhatsApp"
                    >
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12.04 2a9.84 9.84 0 0 0-8.53 14.75L2 22l5.38-1.41A9.99 9.99 0 0 0 12.04 22 9.93 9.93 0 0 0 22 12.08 9.96 9.96 0 0 0 12.04 2Zm5.8 14.05c-.25.7-1.47 1.34-2.04 1.42-.52.08-1.18.12-1.9-.12-.44-.14-1-.33-1.72-.64-3.02-1.3-4.99-4.34-5.14-4.54-.15-.2-1.23-1.63-1.23-3.11s.78-2.21 1.05-2.51c.28-.3.61-.38.81-.38h.59c.19 0 .45-.07.7.54.25.6.85 2.08.93 2.23.07.15.12.33.02.53-.1.2-.15.33-.3.51-.15.17-.32.38-.46.51-.15.15-.3.31-.13.61.18.3.78 1.28 1.67 2.08 1.15 1.02 2.12 1.34 2.42 1.49.3.15.48.12.66-.08.17-.2.75-.88.95-1.18.2-.3.4-.25.68-.15.27.1 1.75.83 2.05.98.3.15.5.22.57.35.08.12.08.72-.17 1.42Z" />
                        </svg>
                        Solicitar demostración
                    </a>
                </div>
            </div>

            <nav
                v-if="mobileNavOpen"
                class="border-t border-line bg-app px-5 py-3 min-[860px]:hidden"
                aria-label="Navegación principal"
            >
                <a href="#soluciones" class="block py-2.5 text-base font-semibold text-text" @click="mobileNavOpen = false">Soluciones</a>
                <a href="#forma-de-trabajo" class="block py-2.5 text-base font-semibold text-text" @click="mobileNavOpen = false">Cómo trabajamos</a>
                <a href="#conoce-la-app" class="block py-2.5 text-base font-semibold text-text" @click="mobileNavOpen = false">Conoce la app</a>
            </nav>
        </header>

        <main class="min-w-0">
            <section class="hero-home relative isolate overflow-hidden pt-[calc(4rem+env(safe-area-inset-top))] sm:pt-[calc(5rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]">
                <img src="/images/hero-negocio.webp" alt="" class="absolute inset-0 h-full w-full object-cover object-[68%_center]" aria-hidden="true" />
                <div class="hero-scrim absolute inset-0" aria-hidden="true"></div>
                <div class="relative z-10 mx-auto max-w-7xl px-4 py-16 sm:px-5 sm:py-24 lg:min-h-[620px] lg:px-8 lg:py-32">
                    <div class="max-w-xl">
                        <h1 class="text-[2.5rem] font-extrabold leading-[1.08] tracking-[-0.02em] text-white min-[390px]:text-[2.75rem] sm:text-6xl">
                            Una forma más simple de llevar tu negocio.
                        </h1>

                        <p class="mt-5 max-w-lg text-base leading-7 text-slate-200 sm:text-lg sm:leading-8">
                            Emite DTE, organiza clientes y controla inventario, taller y la operación diaria desde una aplicación clara en computadora, tablet y móvil.
                        </p>

                        <div class="mt-7 grid gap-3 sm:flex sm:flex-row">
                            <a
                                :href="whatsappDemoUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex h-12 items-center justify-center gap-2.5 rounded-lg bg-primary px-6 text-sm font-bold text-primary-contrast shadow-lg shadow-slate-950/20 transition hover:bg-primary-hover"
                                aria-label="Solicitar demostración por WhatsApp"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M12.04 2a9.84 9.84 0 0 0-8.53 14.75L2 22l5.38-1.41A9.99 9.99 0 0 0 12.04 22 9.93 9.93 0 0 0 22 12.08 9.96 9.96 0 0 0 12.04 2Zm5.8 14.05c-.25.7-1.47 1.34-2.04 1.42-.52.08-1.18.12-1.9-.12-.44-.14-1-.33-1.72-.64-3.02-1.3-4.99-4.34-5.14-4.54-.15-.2-1.23-1.63-1.23-3.11s.78-2.21 1.05-2.51c.28-.3.61-.38.81-.38h.59c.19 0 .45-.07.7.54.25.6.85 2.08.93 2.23.07.15.12.33.02.53-.1.2-.15.33-.3.51-.15.17-.32.38-.46.51-.15.15-.3.31-.13.61.18.3.78 1.28 1.67 2.08 1.15 1.02 2.12 1.34 2.42 1.49.3.15.48.12.66-.08.17-.2.75-.88.95-1.18.2-.3.4-.25.68-.15.27.1 1.75.83 2.05.98.3.15.5.22.57.35.08.12.08.72-.17 1.42Z" />
                                </svg>
                                Solicitar demostración
                            </a>
                            <a href="#soluciones" class="inline-flex h-12 items-center justify-center rounded-lg border border-white/25 px-6 text-sm font-bold text-white transition hover:bg-white/10">
                                Ver soluciones
                            </a>
                        </div>

                        <ul class="mt-7 grid gap-2 text-sm text-slate-200 sm:grid-cols-2">
                            <li class="flex items-center gap-2"><span class="text-sky-300">✓</span> Configuración acompañada</li>
                            <li class="flex items-center gap-2"><span class="text-sky-300">✓</span> Instalable como aplicación</li>
                            <li class="flex items-center gap-2"><span class="text-sky-300">✓</span> Acceso seguro por usuario</li>
                            <li class="flex items-center gap-2"><span class="text-sky-300">✓</span> Diseñado para El Salvador</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section id="conoce-la-app" class="section-textured scroll-mt-24 border-b border-line py-14 sm:py-20">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div v-reveal class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-center">
                        <div>
                            <p class="text-sm font-semibold text-primary">Míralo en acción.</p>
                            <h2 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">
                                Un recorrido breve por StelFaro.
                            </h2>
                            <p class="mt-4 max-w-md leading-7 text-muted">
                                Facturación, taller y la experiencia desde el móvil, explicados en unos minutos.
                            </p>
                        </div>

                        <div class="relative mx-auto w-full min-w-0 max-w-[610px] lg:ml-auto">
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
                </div>
            </section>

            <section class="border-b border-line bg-surface">
                <div class="mx-auto grid max-w-7xl divide-y divide-line px-5 md:grid-cols-3 md:divide-x md:divide-y-0 lg:px-8">
                    <div v-for="([title, text], index) in principles" :key="title" v-reveal="index * 100" class="py-8 md:px-7 md:first:pl-0 md:last:pr-0">
                        <span class="text-xs font-black text-primary">0{{ index + 1 }}</span>
                        <h2 class="mt-3 text-lg font-extrabold">{{ title }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ text }}</p>
                    </div>
                </div>
            </section>

            <section id="soluciones" class="section-textured scroll-mt-16 py-14 sm:scroll-mt-20 sm:py-20 lg:scroll-mt-24">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div v-reveal class="grid gap-7 lg:grid-cols-[0.7fr_1.3fr] lg:items-end">
                        <p class="text-sm font-semibold text-primary">Una base común para toda la operación.</p>
                        <h2 class="max-w-3xl text-4xl font-extrabold leading-tight tracking-[-0.025em] sm:text-5xl">
                            La operación cambia. La columna vertebral permanece.
                        </h2>
                    </div>

                    <div v-reveal class="mt-10 overflow-hidden rounded-2xl bg-[var(--sf-color-navbar)] text-white shadow-xl shadow-slate-950/15">
                        <div class="grid gap-7 p-6 sm:p-8 lg:grid-cols-[0.7fr_1.3fr] lg:items-center lg:p-10">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-sky-300">Base StelFaro</p>
                                <h3 class="mt-3 text-2xl font-extrabold leading-tight sm:text-3xl">Todo parte de la misma información.</h3>
                                <p class="mt-3 max-w-lg text-sm leading-6 text-slate-300">
                                    Cada módulo trabaja sobre una empresa, una identidad y un historial compartido.
                                </p>
                            </div>

                            <ul class="grid gap-px overflow-hidden rounded-xl bg-white/10 sm:grid-cols-2 xl:grid-cols-3">
                                <li
                                    v-for="foundation in ['Empresa y sucursales', 'Usuarios y permisos', 'Clientes y catálogo', 'Facturación DTE', 'Datos y trazabilidad', 'Indicadores del negocio']"
                                    :key="foundation"
                                    class="flex min-h-16 items-center gap-3 bg-white/[0.055] px-4 py-3 text-sm font-bold"
                                >
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-sky-400"></span>
                                    {{ foundation }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="relative mt-12 grid gap-10 lg:grid-cols-3 lg:gap-12">
                        <article
                            v-for="(module, index) in platformModules"
                            :key="module.name"
                            v-reveal="index * 100"
                            class="flex min-w-0 flex-col border-t-2 pt-6"
                            :class="module.accent === 'success' ? 'border-success' : 'border-primary'"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <p class="text-xs font-black uppercase tracking-[0.16em] text-soft">{{ module.name }}</p>
                                <span class="font-mono text-xs font-black" :class="module.accent === 'success' ? 'text-success' : 'text-primary'">{{ module.number }}</span>
                            </div>
                            <h3 class="mt-5 text-2xl font-extrabold leading-tight tracking-tight">{{ module.title }}</h3>
                            <p class="mt-4 leading-7 text-muted">{{ module.description }}</p>
                            <ul class="mt-7 grid gap-3 border-t border-line pt-6 text-sm font-semibold">
                                <li v-for="feature in module.features" :key="feature" class="flex items-center gap-3">
                                    <span :class="module.accent === 'success' ? 'text-success' : 'text-primary'">✓</span>
                                    {{ feature }}
                                </li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section id="forma-de-trabajo" class="border-y border-line bg-[var(--sf-color-navbar)] py-16 text-white sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-14 px-5 lg:grid-cols-[0.8fr_0.9fr_1.1fr] lg:items-center lg:px-8">
                    <div v-reveal>
                        <p class="border-l-2 border-sky-300 pl-4 text-sm font-semibold text-slate-300">No te dejamos solo con el sistema.</p>
                        <h2 class="mt-5 text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">Primero entendemos cómo trabajas.</h2>
                        <p class="mt-5 max-w-xl leading-7 text-slate-300">
                            Stelfaro se configura alrededor de tu empresa. Nuestro trabajo no termina cuando entregamos una contraseña.
                        </p>
                    </div>

                    <div v-reveal class="hidden flex-col overflow-hidden rounded-2xl bg-white/5 lg:flex">
                        <div class="work-photo relative isolate aspect-[16/9] w-full overflow-hidden">
                            <img src="/images/equipo-trabajo.webp" alt="" class="absolute inset-0 h-full w-full object-cover object-[100%_15%]" aria-hidden="true" />
                            <div class="work-photo-scrim absolute inset-0" aria-hidden="true"></div>
                        </div>
                        <p class="border-t border-white/10 px-5 py-4 text-sm font-semibold leading-6 text-slate-100">
                            Configuramos contigo, no solo para ti.
                        </p>
                    </div>

                    <ol class="border-t border-white/15">
                        <li v-for="(step, index) in ['Conocemos tu operación y tus prioridades', 'Configuramos empresa, sucursales y facturación', 'Preparamos a tu equipo con casos reales', 'Te acompañamos durante la puesta en marcha']" :key="step" v-reveal="index * 90" class="grid grid-cols-[3rem_1fr] gap-4 border-b border-white/15 py-5">
                            <span class="font-mono text-sm text-sky-300">{{ String(index + 1).padStart(2, '0') }}</span>
                            <strong class="text-lg">{{ step }}</strong>
                        </li>
                    </ol>
                </div>
            </section>

            <section id="contacto" class="crosshatch bg-app px-5 py-14 sm:py-20">
                <div v-reveal class="mx-auto grid max-w-7xl overflow-hidden border border-line bg-surface lg:grid-cols-[1.2fr_0.8fr]">
                    <div class="p-7 sm:p-12">
                        <p class="text-sm font-semibold text-primary">Hablemos de tu negocio.</p>
                        <h2 class="mt-5 max-w-2xl text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">Cuéntanos qué quieres simplificar.</h2>
                        <p class="mt-5 max-w-2xl text-lg leading-8 text-muted">
                            Te mostramos el producto con situaciones parecidas a las de tu operación, sin una presentación genérica.
                        </p>
                    </div>
                    <div class="work-photo relative isolate flex min-h-[220px] flex-col justify-end border-t border-line px-7 pb-2 pt-7 lg:border-l lg:border-t-0 sm:px-10 sm:pb-3 sm:pt-10">
                        <img src="/images/contacto-celular.webp" alt="" class="absolute inset-0 h-full w-full object-cover object-[75%_35%]" aria-hidden="true" />
                        <div class="work-photo-scrim absolute inset-0" aria-hidden="true"></div>
                        <a
                            :href="whatsappDemoUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="relative z-10 inline-flex h-14 items-center justify-center gap-2.5 rounded-lg bg-primary px-7 font-bold text-primary-contrast transition hover:bg-primary-hover"
                            aria-label="Solicitar una conversación por WhatsApp"
                        >
                            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12.04 2a9.84 9.84 0 0 0-8.53 14.75L2 22l5.38-1.41A9.99 9.99 0 0 0 12.04 22 9.93 9.93 0 0 0 22 12.08 9.96 9.96 0 0 0 12.04 2Zm5.8 14.05c-.25.7-1.47 1.34-2.04 1.42-.52.08-1.18.12-1.9-.12-.44-.14-1-.33-1.72-.64-3.02-1.3-4.99-4.34-5.14-4.54-.15-.2-1.23-1.63-1.23-3.11s.78-2.21 1.05-2.51c.28-.3.61-.38.81-.38h.59c.19 0 .45-.07.7.54.25.6.85 2.08.93 2.23.07.15.12.33.02.53-.1.2-.15.33-.3.51-.15.17-.32.38-.46.51-.15.15-.3.31-.13.61.18.3.78 1.28 1.67 2.08 1.15 1.02 2.12 1.34 2.42 1.49.3.15.48.12.66-.08.17-.2.75-.88.95-1.18.2-.3.4-.25.68-.15.27.1 1.75.83 2.05.98.3.15.5.22.57.35.08.12.08.72-.17 1.42Z" />
                            </svg>
                            Solicitar una conversación
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-line bg-surface">
            <div class="mx-auto max-w-7xl px-5 py-8 text-sm text-muted lg:px-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
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
                <div class="mt-6 flex flex-wrap gap-x-5 gap-y-2 border-t border-line pt-6 text-sm text-soft">
                    <Link href="/terminos-y-condiciones" class="hover:text-primary">Términos y condiciones</Link>
                    <Link href="/politica-de-privacidad" class="hover:text-primary">Política de privacidad</Link>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/*
 * Reusable "liquid glass" recipe. To apply this material to another
 * surface: set --glass-bg/--glass-border/--glass-blur/--glass-saturate/
 * --glass-brightness for that element, then reuse the background,
 * backdrop-filter and box-shadow declarations below (and the shared
 * #liquid-glass SVG filter for the refraction @supports layer).
 */
.header-glass {
    --glass-blur: 26px;
    --glass-saturate: 200%;
    --glass-brightness: 1.02;
    --glass-bg: color-mix(in oklab, var(--sf-color-app) 58%, transparent);
    --glass-border: color-mix(in oklab, color-mix(in oklab, var(--sf-color-app) 60%, white) 16%, transparent);

    background:
        radial-gradient(130% 240% at 14% -20%, color-mix(in oklab, var(--sf-color-primary) 26%, transparent), transparent 55%),
        radial-gradient(90% 200% at 88% 0%, color-mix(in oklab, #f59e0b 16%, transparent), transparent 60%),
        var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur)) saturate(var(--glass-saturate)) brightness(var(--glass-brightness));
    border-bottom: 1px solid var(--glass-border);
    box-shadow:
        inset 0 1px 0 color-mix(in oklab, white 40%, transparent),
        inset 0 -1px 0 color-mix(in oklab, white 10%, transparent),
        0 16px 32px -14px color-mix(in oklab, #0f172a 35%, transparent);
    transform: translateZ(0);
    will-change: backdrop-filter;
}

.header-glass::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(120% 180% at 0% 0%, color-mix(in oklab, white 20%, transparent), transparent 42%),
        radial-gradient(90% 140% at 100% 100%, color-mix(in oklab, white 8%, transparent), transparent 46%);
    pointer-events: none;
}

.header-glass::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(100deg, transparent 30%, color-mix(in oklab, white 55%, transparent) 48%, transparent 66%);
    transform: translateX(-120%);
    animation: header-sweep 1.3s cubic-bezier(0.4, 0, 0.2, 1) 0.4s 1 forwards;
    pointer-events: none;
}

@keyframes header-sweep {
    to {
        transform: translateX(120%);
    }
}

.beacon-glow {
    position: absolute;
    left: -8px;
    top: 50%;
    z-index: -1;
    width: 44px;
    height: 44px;
    transform: translateY(-50%);
    border-radius: 999px;
    background: radial-gradient(circle, rgb(245 158 11 / 55%), transparent 70%);
    filter: blur(7px);
    animation: beacon-pulse 4.5s ease-in-out infinite;
    pointer-events: none;
}

@keyframes beacon-pulse {
    0%,
    100% {
        opacity: 0.35;
    }
    50% {
        opacity: 0.85;
    }
}

@media (prefers-reduced-motion: reduce) {
    .header-glass::after {
        display: none;
    }

    .beacon-glow {
        animation: none;
        opacity: 0.5;
    }
}

@supports (backdrop-filter: url(#liquid-glass)) {
    .header-glass,
    .dark .header-glass {
        backdrop-filter: url(#liquid-glass) blur(var(--glass-blur)) saturate(var(--glass-saturate)) brightness(var(--glass-brightness));
    }
}

@media (prefers-reduced-transparency: reduce) {
    .header-glass {
        background: var(--sf-color-app);
        backdrop-filter: none;
    }
}

.hero-home img {
    z-index: -2;
}

.hero-scrim {
    z-index: -1;
    background: linear-gradient(90deg, rgb(7 22 47 / 96%) 0%, rgb(7 22 47 / 88%) 32%, rgb(7 22 47 / 45%) 62%, rgb(7 22 47 / 5%) 100%);
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

.landing-texture {
    position: fixed;
    inset: 0;
    z-index: -1;
    background-image: url('/images/textura-fondo.webp');
    background-size: cover;
    background-position: center 25%;
    opacity: 0.65;
    filter: grayscale(1) contrast(1.15);
    pointer-events: none;
}

.section-textured {
    background-color: color-mix(in srgb, var(--sf-color-app) 74%, transparent);
}

.crosshatch {
    background-image:
        repeating-linear-gradient(22.5deg, transparent, transparent 2px, rgb(75 85 99 / 6%) 2px, rgb(75 85 99 / 6%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(67.5deg, transparent, transparent 2px, rgb(107 114 128 / 5%) 2px, rgb(107 114 128 / 5%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(112.5deg, transparent, transparent 2px, rgb(55 65 81 / 4%) 2px, rgb(55 65 81 / 4%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(157.5deg, transparent, transparent 2px, rgb(31 41 55 / 3%) 2px, rgb(31 41 55 / 3%) 3px, transparent 3px, transparent 8px);
}

@media (prefers-reduced-motion: reduce) {
    .landing-texture {
        display: none;
    }
}

:global(html) {
    scroll-behavior: smooth;
}

.reveal {
    opacity: 0;
    transform: translateY(-64px);
    transition: opacity 0.55s cubic-bezier(0.34, 1.56, 0.64, 1), transform 0.75s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.reveal.is-visible {
    opacity: 1;
    transform: none;
}

.work-photo img {
    z-index: -2;
}

.work-photo-scrim {
    z-index: -1;
    background: linear-gradient(0deg, rgb(7 22 47 / 55%) 0%, rgb(7 22 47 / 0%) 45%);
}

@media (prefers-reduced-motion: reduce) {
    :global(html) {
        scroll-behavior: auto;
    }

    .reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>

<style>
/* Dark-mode overrides: kept unscoped because Vue's :global(.dark) .foo
   combinator form does not compile reliably in this build pipeline. */
.dark .landing-texture {
    opacity: 0.65;
    filter: grayscale(1) contrast(1.35);
}

.dark .section-textured {
    background-color: color-mix(in srgb, var(--sf-color-app) 38%, transparent);
}

.dark .header-glass {
    --glass-brightness: 1.25;
    --glass-bg: color-mix(in oklab, var(--sf-color-app) 40%, transparent);
    --glass-border: color-mix(in oklab, color-mix(in oklab, var(--sf-color-app) 40%, white) 10%, transparent);

    background:
        radial-gradient(130% 240% at 14% -20%, color-mix(in oklab, var(--sf-color-primary) 30%, transparent), transparent 55%),
        radial-gradient(90% 200% at 88% 0%, color-mix(in oklab, #fbbf24 22%, transparent), transparent 60%),
        var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur)) saturate(var(--glass-saturate)) brightness(var(--glass-brightness));
    border-bottom: 1px solid var(--glass-border);
    box-shadow:
        inset 0 1px 0 color-mix(in oklab, white 16%, transparent),
        inset 0 -1px 0 color-mix(in oklab, white 5%, transparent),
        0 16px 32px -14px color-mix(in oklab, black 55%, transparent);
}

.dark .header-glass::before {
    background:
        radial-gradient(120% 180% at 0% 0%, color-mix(in oklab, white 14%, transparent), transparent 42%),
        radial-gradient(90% 140% at 100% 100%, color-mix(in oklab, white 6%, transparent), transparent 46%);
}

.dark .beacon-glow {
    background: radial-gradient(circle, rgb(251 191 36 / 70%), transparent 70%);
}

.dark .crosshatch {
    background-image:
        repeating-linear-gradient(22.5deg, transparent, transparent 2px, rgb(16 185 129 / 18%) 2px, rgb(16 185 129 / 18%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(67.5deg, transparent, transparent 2px, rgb(245 101 101 / 10%) 2px, rgb(245 101 101 / 10%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(112.5deg, transparent, transparent 2px, rgb(234 179 8 / 8%) 2px, rgb(234 179 8 / 8%) 3px, transparent 3px, transparent 8px),
        repeating-linear-gradient(157.5deg, transparent, transparent 2px, rgb(249 115 22 / 6%) 2px, rgb(249 115 22 / 6%) 3px, transparent 3px, transparent 8px);
}
</style>
