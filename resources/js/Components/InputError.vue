<script setup>
import { AlertCircle } from 'lucide-vue-next';

defineProps({
    message: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-0.5 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <p v-if="message" role="alert" class="flex items-start gap-1.5 text-sm font-medium text-danger">
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{{ message }}</span>
        </p>
    </Transition>
</template>
