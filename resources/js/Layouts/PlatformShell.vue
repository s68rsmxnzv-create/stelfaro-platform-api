<script setup>
defineProps({
    activeApp: {
        type: String,
        default: 'portal',
    },
});

const navItems = [
    { id: 'portal', label: 'Plataforma', href: '/' },
    { id: 'taller', label: 'Taller', href: '/taller' },
    { id: 'facturacion', label: 'Facturación', href: '/facturacion' },
];
</script>

<template>
    <div class="min-h-screen bg-[#f6f8fb] text-[#0d1629]">
        <header class="border-b border-slate-200 bg-[#0d1629] text-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4">
                <a href="/" class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-md bg-blue-600 text-sm font-bold">SF</span>
                    <span>
                        <span class="block text-base font-semibold leading-tight">Stelfaro</span>
                        <span class="block text-xs text-slate-300">Plataforma de apps fiscales</span>
                    </span>
                </a>

                <nav class="flex items-center gap-1 rounded-md bg-white/5 p-1">
                    <a
                        v-for="item in navItems"
                        :key="item.id"
                        :href="item.href"
                        class="rounded px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
                        :class="{ 'bg-white text-[#0d1629] hover:bg-white hover:text-[#0d1629]': activeApp === item.id }"
                    >
                        {{ item.label }}
                    </a>
                </nav>
            </div>
        </header>

        <main>
            <slot />
        </main>
    </div>
</template>
