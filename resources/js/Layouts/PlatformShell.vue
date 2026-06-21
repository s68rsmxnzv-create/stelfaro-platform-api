<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    activeApp: {
        type: String,
        default: 'portal',
    },
    showFacturacionApp: {
        type: Boolean,
        default: true,
    },
    showPlatformNav: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

const navItems = computed(() => [
    { id: 'portal', label: 'Plataforma', href: 'https://platform.stelfaro.com' },
    { id: 'taller', label: 'Taller', href: 'https://taller.stelfaro.com' },
    ...(props.showFacturacionApp
        ? [{ id: 'facturacion', label: 'Facturación', href: 'https://taller.stelfaro.com/facturacion' }]
        : []),
]);

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-[#f6f8fb] text-[#0d1629]">
        <header class="relative z-50 border-b border-slate-200 bg-[#0d1629] text-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4">
                <Link href="/" class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-md bg-blue-600 text-sm font-bold">SF</span>
                    <span>
                        <span class="block text-base font-semibold leading-tight">Stelfaro</span>
                        <span class="block text-xs text-slate-300">Plataforma de apps fiscales</span>
                    </span>
                </Link>

                <div class="flex items-center gap-4">
                    <nav class="flex items-center gap-1 rounded-md bg-white/5 p-1">
                        <template v-if="showPlatformNav">
                            <Link
                                v-for="item in navItems"
                                :key="item.id"
                                :href="item.href"
                                class="rounded px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
                                :class="{ 'bg-white text-[#0d1629] hover:bg-white hover:text-[#0d1629]': activeApp === item.id }"
                            >
                                {{ item.label }}
                            </Link>
                        </template>
                        <slot name="nav" />
                        <slot name="nav-after" />
                    </nav>

                    <div v-if="user" class="hidden items-center gap-3 border-l border-white/10 pl-4 md:flex">
                        <span class="text-right">
                            <span class="block text-sm font-semibold leading-tight">{{ user.name }}</span>
                            <span class="block text-xs text-slate-300">{{ user.email }}</span>
                        </span>
                        <button
                            type="button"
                            class="rounded bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
                            @click="logout"
                        >
                            Salir
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>
    </div>
</template>
