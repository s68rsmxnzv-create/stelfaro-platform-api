# Refactor: componentizar Liquid Glass (Home.vue → `<LiquidGlass>` + `<LiquidGlassDefs>`)

## Contexto

La landing (`resources/js/Pages/Portal/Home.vue`) tiene la implementación de
"liquid glass" (filtros SVG de desplazamiento + receta CSS `.header-glass`)
incrustada directamente en el componente de página, mezclada con el
branding específico de StelFaro (tintes azul/naranja) y con un efecto
`header-sweep` exclusivo de la landing.

Esta spec cubre extraerlo a una infraestructura Vue reutilizable, sin cambiar
el resultado visual del navbar actual.

**Fuera de alcance:** `stelfaro-platform/packages/billing/.../BillingAppPage.vue`
vive en otro repo y tiene su propia copia del filtro SVG (`.toolbar-glass` en
`theme.css`). No se toca en este refactor.

## Objetivo

- El navbar debe verse pixel-perfect igual, en claro/oscuro, en los 4
  breakpoints, con y sin `prefers-reduced-motion`/`prefers-reduced-transparency`.
- Separar responsabilidades: motor SVG compartido, material óptico genérico,
  layout (Tailwind), y branding de la landing.
- Dejar la puerta abierta a una variante `dynamic` (displacement map generado
  en runtime para geometrías arbitrarias) sin implementarla todavía.

## Decisiones resueltas durante la exploración

- **Sin layout compartido**: `Home.vue` es una página standalone (no usa
  `AuthenticatedLayout`/`GuestLayout`/`LegalLayout`). `<LiquidGlassDefs />` se
  monta directamente en `Home.vue` por ahora, tal como el spec original
  contempla como fallback aceptable.
- **TypeScript**: el proyecto usa `jsconfig.json` (no `tsconfig.json`) y no
  tiene `vue-tsc` ni script de typecheck en `package.json`. Vite/esbuild
  transpila `.ts` y `<script setup lang="ts">` por archivo sin necesitar un
  proyecto TS completo, así que `types.ts` y `lang="ts"` funcionarán en build,
  pero **no habrá chequeo de tipos automatizado** — son tipos para
  autocompletado/documentación, no una red de seguridad de CI. Se lo señalo
  explícitamente en el reporte final; no se instala `vue-tsc` en este cambio
  (no lo pidió el spec, y agregar tooling nuevo está fuera de las reglas del
  refactor).
- **Scoped CSS y selectores `.dark .foo`**: el archivo actual ya prueba que
  `.dark .header-glass` funciona bien dentro de un bloque `<style scoped>`
  (aparece así en el `@supports` existente). El comentario sobre
  ":global(.dark) no compila" se refiere específicamente a la sintaxis
  `:global(.dark) .foo`, no a escribir `.dark .foo` a secas. Por lo tanto
  `LiquidGlass.vue` puede tener un único `<style scoped>` con las reglas
  claras y oscuras del material, sin necesitar un segundo bloque global.
- **Verificaciones disponibles**: solo existe `npm run build` (no hay lint ni
  test configurados en este paquete). Esa es la única verificación automática
  a correr; cualquier diferencia visual se valida con captura de pantalla real
  (Playwright) como en el resto de esta sesión.

## Arquitectura

```
resources/js/
├── components/glass/
│   ├── LiquidGlassDefs.vue   — <svg><defs> con los dos <filter> actuales, sin cambios
│   └── LiquidGlass.vue       — wrapper <component :is="as"> + receta .liquid-glass
└── lib/liquid-glass/
    └── types.ts              — LiquidGlassVariant, LiquidGlassProps
```

### `LiquidGlassDefs.vue`
Copia literal del bloque `<svg><defs>...</defs></svg>` que hoy vive en
`Home.vue` (líneas ~108-142): los filtros `#liquid-glass` y
`#liquid-glass-aberration`, sin tocar `feImage`/`feDisplacementMap`/
`feColorMatrix`/`feBlend`/`primitiveUnits`/escalas. Componente puramente
declarativo, sin props ni lógica.

### `types.ts`
```ts
export type LiquidGlassVariant = 'bar' | 'surface' | 'dynamic'

export interface LiquidGlassProps {
  as?: string
  variant?: LiquidGlassVariant
  blur?: number
  saturate?: number
  brightness?: number
  aberration?: boolean
  sweep?: boolean
}
```
`dynamic` queda reservado (documentado con un comentario, no implementado).
`variant` se acepta y se tipa, pero **todavía no cambia el renderizado**:
tanto `'bar'` como `'surface'` usan hoy el mismo mapa de desplazamiento
estático 100×100 (no hay lógica por variante aún). Se deja tipado para que la
fase 2 (`dynamic`) tenga dónde enganchar sin romper la API pública.

### `LiquidGlass.vue`
- `<script setup lang="ts">`, props tipadas desde `types.ts`, sin `any`.
- Defaults: `as='div'`, `variant='surface'`, `blur=20`, `saturate=140`,
  `brightness=1.02`, `aberration=false`, `sweep=false`.
- Renderiza `<component :is="as" class="liquid-glass" :class="{ 'liquid-glass--aberration': aberration, 'liquid-glass--sweep': sweep }" :style="glassStyle"><slot /></component>`.
- `glassStyle` computed tipado como `CSSProperties` que setea
  `--glass-blur`, `--glass-saturate`, `--glass-brightness`.
- No define `width/height/margin/position/top/left/right/padding/max-width` —
  eso lo sigue resolviendo el consumidor vía `class` (Tailwind), que Vue
  fusiona automáticamente con `class="liquid-glass ..."` por fallthrough de
  atributos.
- El único filtro variable es la URL SVG: clase `.liquid-glass` usa
  `url(#liquid-glass)`, `.liquid-glass--aberration` usa
  `url(#liquid-glass-aberration)` (resuelto por CSS, no armando strings en JS).
- CSS (un solo `<style scoped>`):
  - `.liquid-glass` — variables, `background: var(--glass-bg)` (sin tintes),
    `backdrop-filter: blur(...) saturate(...) brightness(...)` (fallback sin
    SVG), `border-bottom`, `box-shadow`, `transform: translateZ(0)`,
    `will-change: backdrop-filter`.
  - `.liquid-glass::before` — highlights neutros (blancos translúcidos), sin
    color de marca. Su `position: absolute; inset: 0` depende de que el
    elemento host ya sea un contexto de posicionamiento — hoy lo es porque el
    consumidor le pasa `fixed`/`relative` por `class` (regla #5: LiquidGlass
    no define posición). `.liquid-glass` mismo **no** debe agregar
    `position: relative` "por si acaso": si algún consumidor futuro lo usa
    sin `fixed`/`relative`/`absolute` propio, el highlight se posicionará
    contra el siguiente ancestro posicionado, que es el comportamiento
    esperado dado que layout es 100% responsabilidad de Tailwind/consumidor.
  - `.liquid-glass--sweep::after` + `@keyframes header-sweep` — opt-in.
  - `@supports (backdrop-filter: url(#liquid-glass))` — aplica
    `blur() url() saturate() brightness()` **en ese orden exacto** (comentario
    corto explicando por qué: el blur debe ir antes del displacement o la
    refracción del borde se disuelve). `.liquid-glass--aberration` cambia el
    `url(...)` referenciado.
  - `.dark .liquid-glass` — solo ajustes genéricos de material (brightness,
    bg base, border, box-shadow, highlights), sin tintes de marca.
  - `@media (prefers-reduced-transparency: reduce)` — `background: var(--sf-color-app)` +
    `backdrop-filter: none` (usa una prop/var de color base neutra, ver
    "Riesgo" abajo).
  - `@media (prefers-reduced-motion: reduce)` — desactiva `::after`/sweep.
- `--glass-bg`/`--glass-border` quedan como variables que el **consumidor**
  sobreescribe (igual que hoy `.header-glass` las define) — el componente les
  da un valor neutro por defecto razonable, y `landing-header-glass` (en
  `Home.vue`) las sobreescribe con los tonos de marca.

## Separación de tintes StelFaro (`landing-header-glass`)

En `Home.vue`, una nueva clase `.landing-header-glass` (scoped, en el mismo
archivo) reemplaza lo que hoy son los `radial-gradient` azul/naranja dentro de
`.header-glass` y su contraparte `.dark`. Se aplica junto a `.liquid-glass`:

```html
<LiquidGlass as="header" variant="bar" :blur="20" :saturate="140" :brightness="1.02" sweep
  class="public-header landing-header-glass fixed inset-x-0 top-0 z-50 overflow-hidden">
```

`landing-header-glass` solo aporta el `background` con los `radial-gradient`
de marca superpuestos a `var(--glass-bg)` (mismo valor/opacidad que hoy) — no
duplica blur/backdrop-filter/box-shadow, que ya los pone `.liquid-glass`.

## Riesgo identificado: `@media (prefers-reduced-transparency: reduce)`

Hoy `.header-glass` cae a `background: var(--sf-color-app)` (color de fondo
de la app) cuando el usuario pide menos transparencia. Si `LiquidGlass.vue`
es genérico, no puede asumir `--sf-color-app` como su fallback opaco (una
superficie fuera del contexto de la landing podría no querer ese color). Se
resuelve dejando que el fallback use `var(--glass-bg)` mismo pero forzado a
opacidad alta vía una nueva variable `--glass-bg-opaque` con default
`var(--sf-color-surface)`, que `landing-header-glass` puede sobreescribir a
`var(--sf-color-app)` si hace falta pixel-perfect. Esto es la única diferencia
de comportamiento que introduce el refactor; se marca explícitamente para que
el usuario lo revise en la validación visual con `prefers-reduced-transparency: reduce`
forzado (DevTools → Rendering → Emulate CSS media).

## Cambios en `Home.vue`

- Quita el bloque `<svg><defs>` inline → `<LiquidGlassDefs />` (montado una
  vez, antes del header).
- `<header class="public-header header-glass ...">` →
  `<LiquidGlass as="header" variant="bar" :blur="20" :saturate="140" :brightness="1.02" sweep class="public-header landing-header-glass fixed inset-x-0 top-0 z-50 overflow-hidden">`
  conservando el contenido interno (logo, nav, botones, mobile menu) sin
  reestructurar.
- `<style scoped>`: se elimina todo lo que se movió a `LiquidGlass.vue`
  (`.header-glass`, `::before`, `::after`, `@keyframes header-sweep`,
  `@supports`, `@media reduced-motion` de esas reglas, `@media
  reduced-transparency`). Queda `.header-content` (text-shadow, sin cambios),
  `.landing-header-glass` (tintes), y todo lo demás de la landing intacto.
- `<style>` no-scoped (dark overrides): `.dark .header-glass` se reemplaza por
  `.dark .landing-header-glass` (solo tintes); los ajustes genéricos de
  oscuro pasan a `.dark .liquid-glass` dentro de `LiquidGlass.vue`.
- No se toca `applyTheme()`, `stelfaro:theme`, hero, spacing, tipografía, ni
  el resto de la página.

## Verificación

- `npm run build` (única verificación automática disponible en este paquete).
- Captura visual real (Playwright contra `dev.stelfaro.com` tras el build,
  como en el resto de esta sesión) comparando antes/después en: desktop
  ancho, desktop mediano, tablet, móvil, claro, oscuro. Foco en: altura,
  blur, transparencia, saturación, refracción del borde inferior, shadow,
  highlight superior, tintes azul/naranja, posición de logo/nav/botones,
  mobile menu, y el caso `prefers-reduced-transparency: reduce` señalado
  arriba.

## Qué NO se hace en este cambio

- No se implementa `displacement.ts` / generación dinámica del mapa de
  desplazamiento (variante `dynamic` queda solo tipada y documentada).
- No se toca `BillingAppPage.vue` ni `theme.css` del otro repo.
- No se activa `aberration` ni se cambia el valor de `sweep` en el navbar
  actual (sigue `sweep` porque el navbar ya lo tenía; `aberration` queda en
  `false`).
- No se agrega `vue-tsc`, lint, ni ninguna dependencia nueva.
