<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        v-model="model"
        class="h-12 rounded-xl border border-line bg-surface-raised px-3 text-sm text-text shadow-sm outline-none transition placeholder:text-soft focus:border-primary focus:ring-2 focus:ring-primary-soft disabled:bg-surface-muted disabled:text-muted"
    />
</template>
