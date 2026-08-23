<script setup lang="ts">
import { computed, type CSSProperties } from 'vue';
import type { LiquidGlassProps } from '../../lib/liquid-glass/types';

const props = withDefaults(defineProps<LiquidGlassProps>(), {
    as: 'div',
    variant: 'surface',
    blur: 20,
    saturate: 140,
    brightness: 1.02,
    aberration: false,
    sweep: false,
});

const glassStyle = computed<CSSProperties>(() => ({
    '--glass-blur': `${props.blur}px`,
    '--glass-saturate': `${props.saturate}%`,
    '--glass-brightness': String(props.brightness),
}));
</script>

<template>
    <component
        :is="as"
        class="liquid-glass"
        :class="{
            'liquid-glass--aberration': aberration,
            'liquid-glass--sweep': sweep,
        }"
        :style="glassStyle"
    >
        <slot />
    </component>
</template>

<style scoped>
/*
 * Generic "liquid glass" material: refraction, blur, highlights. This
 * component does NOT define geometry (width/height/position/padding) —
 * that stays the consumer's responsibility via Tailwind classes on the
 * fallthrough `class` attribute. It also does NOT define brand tints
 * (colored gradients) — a consumer that needs those layers its own class
 * on top (see .landing-header-glass in Home.vue) so the background
 * shorthand below is the base look, meant to be overridden wholesale.
 *
 * --glass-blur/--glass-saturate/--glass-brightness come from the `blur`/
 * `saturate`/`brightness` props via inline style (see glassStyle above) so
 * they win the cascade over any class-based rule, including .dark — a
 * consumer that needs a different value in dark mode (e.g. a brighter
 * look) should pass a different prop value reactively, not rely on CSS
 * specificity to override it (it can't: inline style always wins).
 */
.liquid-glass {
    --glass-bg: color-mix(in oklab, var(--sf-color-surface) 8%, transparent);
    --glass-bg-opaque: var(--sf-color-surface);
    --glass-border: color-mix(in oklab, var(--sf-color-line) 70%, transparent);

    background: var(--glass-bg);
    backdrop-filter: blur(var(--glass-blur)) saturate(var(--glass-saturate)) brightness(var(--glass-brightness));
    border-bottom: 1px solid var(--glass-border);
    box-shadow:
        inset 0 1px 0 color-mix(in oklab, white 40%, transparent),
        inset 0 -1px 0 color-mix(in oklab, white 10%, transparent),
        0 16px 32px -14px color-mix(in oklab, #0f172a 35%, transparent);
    transform: translateZ(0);
    will-change: backdrop-filter;
}

/*
 * Relies on the host element already being a positioning context (the
 * consumer's own `fixed`/`relative`/`absolute` Tailwind class) — this
 * component intentionally does not add `position: relative` itself, since
 * defining positioning is explicitly the consumer's job.
 */
.liquid-glass::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(120% 180% at 0% 0%, color-mix(in oklab, white 20%, transparent), transparent 42%),
        radial-gradient(90% 140% at 100% 100%, color-mix(in oklab, white 8%, transparent), transparent 46%);
    pointer-events: none;
}

.liquid-glass--sweep::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(100deg, transparent 30%, color-mix(in oklab, white 55%, transparent) 48%, transparent 66%);
    transform: translateX(-120%);
    animation: liquid-glass-sweep 1.3s cubic-bezier(0.4, 0, 0.2, 1) 0.4s 1 forwards;
    pointer-events: none;
}

@keyframes liquid-glass-sweep {
    to {
        transform: translateX(120%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .liquid-glass--sweep::after {
        display: none;
    }
}

@supports (backdrop-filter: url(#liquid-glass)) {
    /*
     * blur runs BEFORE the SVG displacement on purpose: backdrop-filter
     * functions apply left to right, so a trailing blur(var(--glass-blur))
     * after url(#liquid-glass) re-smooths the warp it just introduced and
     * the edge-lens effect disappears. Blurring first keeps the frosted
     * look, then the displacement bends that already-soft backdrop and
     * stays visible in the final result.
     */
    .liquid-glass {
        backdrop-filter: blur(var(--glass-blur)) url(#liquid-glass) saturate(var(--glass-saturate)) brightness(var(--glass-brightness));
    }

    .liquid-glass--aberration {
        backdrop-filter: blur(var(--glass-blur)) url(#liquid-glass-aberration) saturate(var(--glass-saturate)) brightness(var(--glass-brightness));
    }
}

.dark .liquid-glass {
    --glass-bg: color-mix(in oklab, var(--sf-color-surface) 13%, transparent);
    --glass-bg-opaque: var(--sf-color-surface);
    --glass-border: color-mix(in oklab, color-mix(in oklab, var(--sf-color-surface) 40%, white) 10%, transparent);

    box-shadow:
        inset 0 1px 0 color-mix(in oklab, white 16%, transparent),
        inset 0 -1px 0 color-mix(in oklab, white 5%, transparent),
        0 16px 32px -14px color-mix(in oklab, black 55%, transparent);
}

.dark .liquid-glass::before {
    background:
        radial-gradient(120% 180% at 0% 0%, color-mix(in oklab, white 14%, transparent), transparent 42%),
        radial-gradient(90% 140% at 100% 100%, color-mix(in oklab, white 6%, transparent), transparent 46%);
}

@media (prefers-reduced-transparency: reduce) {
    .liquid-glass {
        background: var(--glass-bg-opaque);
        backdrop-filter: none;
    }
}
</style>
