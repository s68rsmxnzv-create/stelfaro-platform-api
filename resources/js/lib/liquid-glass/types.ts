export type LiquidGlassVariant = 'bar' | 'surface' | 'dynamic'

export interface LiquidGlassProps {
    /** Element/component to render as the root (default: 'div'). */
    as?: string
    /**
     * Reserved for the future displacement engine. 'bar' and 'surface' are
     * currently identical — both use the static 100x100 displacement map
     * from LiquidGlassDefs. 'dynamic' is typed but not implemented: it will
     * eventually generate a per-element displacement map from measured
     * width/height/radius/depth for arbitrary geometries (cards, pills,
     * buttons).
     */
    variant?: LiquidGlassVariant
    /** Backdrop blur, in px. */
    blur?: number
    /** Backdrop saturate, in percent. */
    saturate?: number
    /** Backdrop brightness multiplier. */
    brightness?: number
    /** Use the chromatic-aberration displacement filter instead of the plain one. */
    aberration?: boolean
    /** Play the one-shot diagonal light sweep on mount. */
    sweep?: boolean
}
