<script setup>
import { computed, useSlots } from 'vue';
import { AlertTriangle, CheckCircle2, Info } from 'lucide-vue-next';

const slots = useSlots();

const props = defineProps({
    /** danger | success | warning | info */
    variant: {
        type: String,
        default: 'danger',
    },
    title: {
        type: String,
        default: '',
    },
    message: {
        type: [String, Array],
        default: '',
    },
});

const VARIANTS = {
    danger: {
        wrapper: 'border-danger bg-danger-soft text-danger',
        icon: 'text-danger',
        role: 'alert',
        glyph: AlertTriangle,
    },
    warning: {
        wrapper: 'border-warning bg-warning-soft text-warning',
        icon: 'text-warning',
        role: 'alert',
        glyph: AlertTriangle,
    },
    success: {
        wrapper: 'border-success bg-success-soft text-success',
        icon: 'text-success',
        role: 'status',
        glyph: CheckCircle2,
    },
    info: {
        wrapper: 'border-primary bg-primary-soft text-primary',
        icon: 'text-primary',
        role: 'status',
        glyph: Info,
    },
};

const config = computed(() => VARIANTS[props.variant] ?? VARIANTS.danger);

const messages = computed(() => {
    if (Array.isArray(props.message)) {
        return props.message.filter(Boolean);
    }

    return props.message ? [props.message] : [];
});

const hasContent = computed(() => messages.value.length > 0 || Boolean(slots.default));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-1 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-1 opacity-0"
    >
        <div
            v-if="hasContent"
            :role="config.role"
            class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
            :class="config.wrapper"
        >
            <component :is="config.glyph" class="mt-0.5 h-5 w-5 shrink-0" :class="config.icon" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-1">
                <p v-if="title" class="font-semibold leading-5">{{ title }}</p>
                <template v-if="$slots.default">
                    <div class="leading-5 [&_a]:font-semibold [&_a]:underline">
                        <slot />
                    </div>
                </template>
                <p
                    v-for="(line, index) in messages"
                    :key="index"
                    class="leading-5"
                    :class="{ 'font-medium': !title && messages.length === 1 }"
                >
                    {{ line }}
                </p>
            </div>
        </div>
    </Transition>
</template>
