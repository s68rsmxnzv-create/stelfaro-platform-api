<!--
  Shared SVG displacement filters for the liquid-glass material
  (see components/glass/LiquidGlass.vue). Mount this once per page that uses
  <LiquidGlass> — the filters are referenced by id via CSS url(#...), so
  duplicating this component on the same page would create duplicate ids.

  The displacement map (feImage) is a linear-gradient texture with a blurred
  flat rect at its center, so the middle of the surface stays undistorted
  and refraction concentrates near the edges — a real lens look, instead of
  uniform feTurbulence noise. Ported from https://github.com/srdavo/liquid-glass.

  #liquid-glass-aberration additionally splits the R/G/B displacement so
  edges pick up a subtle color fringe; opt into it via <LiquidGlass aberration>.
-->
<template>
    <svg class="absolute h-0 w-0 overflow-hidden" aria-hidden="true" focusable="false">
        <defs>
            <filter id="liquid-glass" primitiveUnits="objectBoundingBox" x="-15%" y="-40%" width="130%" height="180%" color-interpolation-filters="sRGB">
                <feImage
                    x="0"
                    y="0"
                    width="1"
                    height="1"
                    preserveAspectRatio="none"
                    result="displacementMap"
                    href="data:image/svg+xml;utf8,%3Csvg%20width%3D%22100%22%20height%3D%22100%22%20viewBox%3D%220%200%20100%20100%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cdefs%3E%3ClinearGradient%20id%3D%22gy%22%20x1%3D%220%22%20x2%3D%220%22%20y1%3D%220%25%22%20y2%3D%22100%25%22%3E%3Cstop%20offset%3D%220%25%22%20stop-color%3D%22%230F0%22%2F%3E%3Cstop%20offset%3D%22100%25%22%20stop-color%3D%22%23000%22%2F%3E%3C%2FlinearGradient%3E%3ClinearGradient%20id%3D%22gx%22%20x1%3D%220%25%22%20x2%3D%22100%25%22%20y1%3D%220%22%20y2%3D%220%22%3E%3Cstop%20offset%3D%220%25%22%20stop-color%3D%22%23F00%22%2F%3E%3Cstop%20offset%3D%22100%25%22%20stop-color%3D%22%23000%22%2F%3E%3C%2FlinearGradient%3E%3C%2Fdefs%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22%23808080%22%2F%3E%3Cg%20style%3D%22filter%3Ablur%282px%29%22%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22%23000080%22%2F%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22url%28%23gy%29%22%20style%3D%22mix-blend-mode%3Ascreen%22%2F%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22url%28%23gx%29%22%20style%3D%22mix-blend-mode%3Ascreen%22%2F%3E%3Crect%20x%3D%226%22%20y%3D%226%22%20width%3D%2288%22%20height%3D%2288%22%20fill%3D%22%23808080%22%20style%3D%22filter%3Ablur%286px%29%22%2F%3E%3C%2Fg%3E%3C%2Fsvg%3E"
                />
                <feDisplacementMap in="SourceGraphic" in2="displacementMap" scale="0.09" xChannelSelector="R" yChannelSelector="G" />
            </filter>
            <filter id="liquid-glass-aberration" primitiveUnits="objectBoundingBox" x="-15%" y="-40%" width="130%" height="180%" color-interpolation-filters="sRGB">
                <feImage
                    x="0"
                    y="0"
                    width="1"
                    height="1"
                    preserveAspectRatio="none"
                    result="displacementMap"
                    href="data:image/svg+xml;utf8,%3Csvg%20width%3D%22100%22%20height%3D%22100%22%20viewBox%3D%220%200%20100%20100%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cdefs%3E%3ClinearGradient%20id%3D%22gy%22%20x1%3D%220%22%20x2%3D%220%22%20y1%3D%220%25%22%20y2%3D%22100%25%22%3E%3Cstop%20offset%3D%220%25%22%20stop-color%3D%22%230F0%22%2F%3E%3Cstop%20offset%3D%22100%25%22%20stop-color%3D%22%23000%22%2F%3E%3C%2FlinearGradient%3E%3ClinearGradient%20id%3D%22gx%22%20x1%3D%220%25%22%20x2%3D%22100%25%22%20y1%3D%220%22%20y2%3D%220%22%3E%3Cstop%20offset%3D%220%25%22%20stop-color%3D%22%23F00%22%2F%3E%3Cstop%20offset%3D%22100%25%22%20stop-color%3D%22%23000%22%2F%3E%3C%2FlinearGradient%3E%3C%2Fdefs%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22%23808080%22%2F%3E%3Cg%20style%3D%22filter%3Ablur%282px%29%22%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22%23000080%22%2F%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22url%28%23gy%29%22%20style%3D%22mix-blend-mode%3Ascreen%22%2F%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22url%28%23gx%29%22%20style%3D%22mix-blend-mode%3Ascreen%22%2F%3E%3Crect%20x%3D%226%22%20y%3D%226%22%20width%3D%2288%22%20height%3D%2288%22%20fill%3D%22%23808080%22%20style%3D%22filter%3Ablur%286px%29%22%2F%3E%3C%2Fg%3E%3C%2Fsvg%3E"
                />
                <feDisplacementMap in="SourceGraphic" in2="displacementMap" scale="0.096" xChannelSelector="R" yChannelSelector="G" />
                <feColorMatrix type="matrix" values="1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0" result="displacedR" />
                <feDisplacementMap in="SourceGraphic" in2="displacementMap" scale="0.09" xChannelSelector="R" yChannelSelector="G" />
                <feColorMatrix type="matrix" values="0 0 0 0 0  0 1 0 0 0  0 0 0 0 0  0 0 0 1 0" result="displacedG" />
                <feDisplacementMap in="SourceGraphic" in2="displacementMap" scale="0.084" xChannelSelector="R" yChannelSelector="G" />
                <feColorMatrix type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 1 0 0  0 0 0 1 0" result="displacedB" />
                <feBlend in="displacedR" in2="displacedG" mode="screen" />
                <feBlend in2="displacedB" mode="screen" />
            </filter>
        </defs>
    </svg>
</template>
