<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const themeStorageKey = 'stelfaro:theme';
const isDark = ref(false);

function applyTheme(darkMode) {
    isDark.value = darkMode;
    document.documentElement.classList.toggle('dark', darkMode);
    document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
    window.localStorage.setItem(themeStorageKey, darkMode ? 'dark' : 'light');
}

function toggleTheme() {
    applyTheme(!isDark.value);
}

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
});

const benefits = [
    {
        title: 'Emite sin complicarte',
        text: 'Factura electrónica, crédito fiscal, notas y sujeto excluido desde una experiencia clara y guiada.',
        icon: 'document',
    },
    {
        title: 'Trabaja desde cualquier lugar',
        text: 'Diseñado para computadora, tablet y móvil. Instálalo y úsalo como una aplicación.',
        icon: 'phone',
    },
    {
        title: 'Tu operación conectada',
        text: 'Clientes, catálogo, inventario, caja y documentos fiscales trabajan como un solo sistema.',
        icon: 'flow',
    },
];

const workshopFeatures = [
    'Recepción rápida de equipos',
    'Fotos, diagnóstico y aprobación',
    'Seguimiento de órdenes',
    'Cobro, entrega y facturación',
];

const billingFeatures = [
    'Emisión de los principales DTE',
    'Clientes y productos organizados',
    'Inventario, caja y cuentas pendientes',
    'Comprobantes y respuestas de Hacienda',
];
</script>

<template>
    <Head>
        <title>Stelfaro — Facturación y gestión para hacer crecer tu negocio</title>
        <meta
            head-key="description"
            name="description"
            content="Facturación electrónica y gestión operativa para negocios en El Salvador. Trabaja desde computadora, tablet o móvil."
        />
    </Head>

    <div class="min-h-screen overflow-hidden bg-slate-50 text-slate-950 transition-colors dark:bg-[#07111f] dark:text-white">
        <header class="fixed inset-x-0 top-0 z-50 border-b border-slate-200 bg-white/90 backdrop-blur-xl dark:border-white/10 dark:bg-[#07111f]/90">
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

                <nav class="hidden items-center gap-7 text-sm font-medium text-slate-600 dark:text-slate-300 md:flex">
                    <a href="#soluciones" class="transition hover:text-sky-600 dark:hover:text-white">Soluciones</a>
                    <a href="#beneficios" class="transition hover:text-sky-600 dark:hover:text-white">Beneficios</a>
                    <a href="#como-funciona" class="transition hover:text-sky-600 dark:hover:text-white">Cómo funciona</a>
                </nav>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="grid h-11 w-11 place-items-center rounded-xl border border-slate-200 bg-slate-100 text-slate-700 transition hover:bg-slate-200 dark:border-white/15 dark:bg-white/10 dark:text-white dark:hover:bg-white/20"
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
                        href="/login"
                        class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold shadow-sm transition hover:border-sky-300 hover:text-sky-700 dark:border-white/15 dark:bg-white/10 dark:text-white dark:hover:bg-white dark:hover:text-[#07111f] sm:px-5"
                    >
                        <span class="sm:hidden">Entrar</span>
                        <span class="hidden sm:inline">Iniciar sesión</span>
                    </Link>
                </div>
            </div>
        </header>

        <main>
            <section class="relative isolate pb-20 pt-32 sm:pb-28 sm:pt-40">
                <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_75%_15%,rgba(14,165,233,0.22),transparent_30%),radial-gradient(circle_at_15%_70%,rgba(16,185,129,0.13),transparent_28%)]"></div>
                <div class="absolute left-1/2 top-24 -z-10 h-[420px] w-[420px] -translate-x-1/2 rounded-full bg-sky-500/10 blur-3xl"></div>

                <div class="mx-auto grid max-w-7xl items-center gap-14 px-5 lg:grid-cols-[1.05fr_0.95fr] lg:px-8">
                    <div class="max-w-3xl">
                        <div class="inline-flex items-center gap-2 rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1.5 text-xs font-bold text-sky-200">
                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                            Creado para negocios de El Salvador
                        </div>

                        <h1 class="mt-6 text-4xl font-black leading-[1.05] tracking-[-0.04em] sm:text-6xl lg:text-7xl">
                            Menos papeleo.
                            <span class="block bg-gradient-to-r from-sky-600 via-cyan-600 to-emerald-600 bg-clip-text text-transparent dark:from-sky-300 dark:via-cyan-300 dark:to-emerald-300">
                                Más negocio.
                            </span>
                        </h1>

                        <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-600 dark:text-slate-300 sm:text-xl">
                            Facturación electrónica y operación diaria en una sola aplicación. Fácil para tu equipo, práctica desde el móvil y lista para acompañar tu crecimiento.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <a
                                href="mailto:soporte@stelfaro.com?subject=Quiero conocer Stelfaro"
                                class="inline-flex h-14 items-center justify-center rounded-2xl bg-sky-500 px-7 text-base font-black text-white shadow-xl shadow-sky-500/20 transition hover:-translate-y-0.5 hover:bg-sky-400"
                            >
                                Quiero conocer Stelfaro
                            </a>
                            <a
                                href="#soluciones"
                                class="inline-flex h-14 items-center justify-center rounded-2xl border border-slate-300 bg-white px-7 text-base font-bold text-slate-800 transition hover:bg-slate-100 dark:border-white/15 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"
                            >
                                Ver soluciones
                            </a>
                        </div>

                        <div class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-600 dark:text-slate-400">
                            <span class="flex items-center gap-2"><span class="text-emerald-400">✓</span> Sin instalaciones complicadas</span>
                            <span class="flex items-center gap-2"><span class="text-emerald-400">✓</span> Acompañamiento inicial</span>
                            <span class="flex items-center gap-2"><span class="text-emerald-400">✓</span> Acceso seguro por usuario</span>
                        </div>
                    </div>

                    <div class="relative mx-auto w-full max-w-xl">
                        <div class="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-sky-500/20 to-emerald-500/10 blur-2xl"></div>
                        <div class="relative overflow-hidden rounded-[2rem] border border-white/15 bg-[#0b1728] p-3 text-white shadow-2xl shadow-black/40">
                            <div class="flex items-center gap-2 border-b border-white/10 px-3 pb-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                <span class="ml-auto text-[10px] font-semibold uppercase tracking-widest text-slate-500">Resumen de hoy</span>
                            </div>

                            <div class="space-y-3 p-2 pt-5 sm:p-5">
                                <div class="flex items-end justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wider text-sky-300">Tu operación</p>
                                        <p class="mt-1 text-2xl font-black">Todo bajo control</p>
                                    </div>
                                    <span class="rounded-xl bg-emerald-400/10 px-3 py-2 text-xs font-bold text-emerald-300">En línea</span>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-4">
                                        <span class="text-2xl">↗</span>
                                        <strong class="mt-5 block text-2xl">$460.00</strong>
                                        <span class="mt-1 block text-sm text-slate-400">Ventas de hoy</span>
                                    </div>
                                    <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-4">
                                        <span class="text-2xl">✓</span>
                                        <strong class="mt-5 block text-2xl">12</strong>
                                        <span class="mt-1 block text-sm text-slate-400">Operaciones listas</span>
                                    </div>
                                </div>

                                <div class="rounded-2xl bg-sky-500 p-5 shadow-lg shadow-sky-500/20">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wider text-sky-100">Acción principal</p>
                                            <strong class="mt-1 block text-xl">Emitir factura</strong>
                                        </div>
                                        <span class="grid h-11 w-11 place-items-center rounded-full bg-white/15 text-2xl">→</span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                                    <div class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-400/10 text-xl text-emerald-300">✓</div>
                                    <div class="min-w-0">
                                        <strong class="block">Documento aceptado</strong>
                                        <span class="block truncate text-sm text-slate-400">Procesado correctamente por Hacienda</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="beneficios" class="border-y border-slate-200 bg-white py-20 dark:border-white/10 dark:bg-white/[0.025] sm:py-24">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="max-w-2xl">
                        <p class="text-sm font-black uppercase tracking-[0.2em] text-sky-300">Una herramienta para trabajar</p>
                        <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-5xl">Lo importante, sin complicaciones.</h2>
                    </div>

                    <div class="mt-12 grid gap-4 md:grid-cols-3">
                        <article
                            v-for="benefit in benefits"
                            :key="benefit.title"
                            class="rounded-3xl border border-slate-200 bg-slate-50 p-6 shadow-sm transition hover:-translate-y-1 hover:border-sky-400/30 dark:border-white/10 dark:bg-[#0b1728] dark:shadow-none"
                        >
                            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-sky-400/10 text-xl font-black text-sky-300">
                                <span v-if="benefit.icon === 'document'">✓</span>
                                <span v-else-if="benefit.icon === 'phone'">▯</span>
                                <span v-else>∞</span>
                            </div>
                            <h3 class="mt-6 text-xl font-black">{{ benefit.title }}</h3>
                            <p class="mt-3 leading-7 text-slate-600 dark:text-slate-400">{{ benefit.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <section id="soluciones" class="py-20 sm:py-28">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="mx-auto max-w-3xl text-center">
                        <p class="text-sm font-black uppercase tracking-[0.2em] text-emerald-300">Elige cómo empezar</p>
                        <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-5xl">Una plataforma, soluciones para tu negocio.</h2>
                        <p class="mt-5 text-lg leading-8 text-slate-600 dark:text-slate-400">Empieza con lo que necesitas hoy. Tu información y tu equipo permanecen en el mismo lugar cuando agregas nuevas funciones.</p>
                    </div>

                    <div class="mt-12 grid gap-5 lg:grid-cols-2">
                        <article class="group relative overflow-hidden rounded-[2rem] border border-sky-400/25 bg-gradient-to-br from-sky-500/15 to-[#0b1728] p-7 text-white sm:p-9">
                            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-sky-400/10 blur-2xl"></div>
                            <span class="inline-flex rounded-full bg-sky-400/15 px-3 py-1 text-xs font-black uppercase tracking-wider text-sky-200">Facturación</span>
                            <h3 class="mt-5 text-3xl font-black">Emite DTE con claridad</h3>
                            <p class="mt-3 max-w-xl leading-7 text-slate-300">Para comercios y profesionales que quieren facturar, cobrar y mantener su operación organizada.</p>
                            <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                                <li v-for="feature in billingFeatures" :key="feature" class="flex items-start gap-2 text-sm text-slate-300">
                                    <span class="mt-0.5 text-emerald-400">✓</span>{{ feature }}
                                </li>
                            </ul>
                        </article>

                        <article class="group relative overflow-hidden rounded-[2rem] border border-emerald-400/20 bg-gradient-to-br from-emerald-500/10 to-[#0b1728] p-7 text-white sm:p-9">
                            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-emerald-400/10 blur-2xl"></div>
                            <span class="inline-flex rounded-full bg-emerald-400/15 px-3 py-1 text-xs font-black uppercase tracking-wider text-emerald-200">Taller</span>
                            <h3 class="mt-5 text-3xl font-black">Cada equipo, bajo control</h3>
                            <p class="mt-3 max-w-xl leading-7 text-slate-300">Recepción, diagnóstico, aprobación, reparación y entrega conectados con cobro y facturación.</p>
                            <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                                <li v-for="feature in workshopFeatures" :key="feature" class="flex items-start gap-2 text-sm text-slate-300">
                                    <span class="mt-0.5 text-emerald-400">✓</span>{{ feature }}
                                </li>
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section id="como-funciona" class="border-y border-slate-200 bg-slate-100 py-20 dark:border-white/10 dark:bg-[#0a1524] sm:py-24">
                <div class="mx-auto max-w-7xl px-5 lg:px-8">
                    <div class="grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:items-center">
                        <div>
                            <p class="text-sm font-black uppercase tracking-[0.2em] text-sky-300">Empieza acompañado</p>
                            <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-5xl">De tu negocio a Stelfaro, paso a paso.</h2>
                            <p class="mt-5 text-lg leading-8 text-slate-600 dark:text-slate-400">Configuramos contigo lo necesario para que tu equipo empiece con seguridad y sin una curva de aprendizaje pesada.</p>
                        </div>

                        <ol class="grid gap-4">
                            <li v-for="(step, index) in ['Conocemos tu operación', 'Configuramos empresa y facturación', 'Acompañamos a tu equipo al comenzar']" :key="step" class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03] dark:shadow-none sm:p-5">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-sky-400/10 text-sm font-black text-sky-300">0{{ index + 1 }}</span>
                                <strong class="text-base sm:text-lg">{{ step }}</strong>
                            </li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="px-5 py-20 sm:py-28">
                <div class="mx-auto max-w-5xl overflow-hidden rounded-[2rem] border border-sky-300/20 bg-gradient-to-br from-sky-500 to-cyan-500 p-8 text-center shadow-2xl shadow-sky-500/20 sm:p-14">
                    <p class="text-sm font-black uppercase tracking-[0.2em] text-sky-950/70">Tu negocio puede sentirse más simple</p>
                    <h2 class="mx-auto mt-4 max-w-3xl text-3xl font-black tracking-tight text-white sm:text-5xl">Conversemos sobre lo que necesitas.</h2>
                    <p class="mx-auto mt-5 max-w-2xl text-lg leading-8 text-sky-50">Te mostramos Stelfaro y preparamos una ruta de implementación adecuada para tu operación.</p>
                    <a
                        href="mailto:soporte@stelfaro.com?subject=Quiero una demostración de Stelfaro"
                        class="mt-8 inline-flex h-14 items-center justify-center rounded-2xl bg-[#07111f] px-8 text-base font-black text-white transition hover:-translate-y-0.5 hover:bg-[#0b1d33]"
                    >
                        Solicitar demostración
                    </a>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 dark:border-white/10">
            <div class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-8 text-sm text-slate-600 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between lg:px-8">
                <div class="flex items-center gap-3">
                    <img src="/pwa/stelfaro.svg" alt="" class="h-9 w-9 rounded-full" />
                    <span><strong class="text-slate-950 dark:text-white">Stelfaro</strong> · Tecnología para trabajar mejor.</span>
                </div>
                <div class="flex flex-wrap gap-5">
                    <a href="mailto:soporte@stelfaro.com" class="hover:text-sky-600 dark:hover:text-white">soporte@stelfaro.com</a>
                    <Link href="/login" class="hover:text-sky-600 dark:hover:text-white">Iniciar sesión</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
