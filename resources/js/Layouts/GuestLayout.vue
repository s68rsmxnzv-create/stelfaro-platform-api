<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="sf-app-background grid min-h-screen text-[#0d1629] dark:text-text lg:grid-cols-[0.95fr_1.05fr]">
        <section class="hidden bg-[#0d1629] px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between">
            <Link href="/" class="flex items-center gap-3">
                <span class="grid h-11 w-11 place-items-center rounded-md bg-blue-600 text-sm font-bold">SF</span>
                <span>
                    <span class="block text-lg font-semibold leading-tight">Stelfaro</span>
                    <span class="block text-sm text-slate-300">Plataforma de apps fiscales</span>
                </span>
            </Link>

            <div class="max-w-xl">
                <p class="text-sm font-semibold uppercase tracking-wide text-blue-300">Acceso seguro</p>
                <h1 class="mt-4 text-4xl font-bold leading-tight">Una cuenta para operar tus apps, tenants y servicios fiscales.</h1>
                <p class="mt-5 text-base leading-7 text-slate-300">
                    Laravel protege la sesión y Stelfaro resuelve automáticamente la app que corresponde a cada usuario.
                </p>
            </div>

            <p class="text-sm text-slate-400">Core fiscal, notificaciones e impresión conectados sin mezclar responsabilidades.</p>
        </section>

        <section class="flex min-h-screen items-center justify-center px-5 py-10">
            <div class="w-full max-w-md">
                <Link href="/" class="mb-8 flex items-center gap-3 lg:hidden">
                    <span class="grid h-11 w-11 place-items-center rounded-md bg-blue-600 text-sm font-bold text-white">SF</span>
                    <span>
                        <span class="block text-lg font-semibold leading-tight">Stelfaro</span>
                        <span class="block text-sm text-slate-500">Plataforma</span>
                    </span>
                </Link>

                <div class="rounded-md border border-slate-200 bg-white p-6 shadow-sm dark:border-line dark:bg-surface dark:text-text dark:shadow-black/20">
                    <slot />
                </div>
            </div>
        </section>
    </div>
</template>
