# Theme Notes

Short reference for future theme edits.

## Colors and fonts

File: `packages/Webkul/Shop/tailwind.config.js`

Custom colors:

| Token | Hex |
| --- | --- |
| `navyBlue` | `#060C3B` |
| `lightOrange` | `#F6F2EB` |
| `darkGreen` | `#40994A` |
| `darkBlue` | `#0044F2` |
| `darkPink` | `#F85156` |

Fonts: Poppins and DM Serif Display.

## Cyberpunk grid theme

Same file: `packages/Webkul/Shop/tailwind.config.js`

Added tokens:

| Token | Value |
| --- | --- |
| `zinc950` | `#09090b` |
| `cyan400` | `#22d3ee` |
| `cyan500` | `#06b6d4` |
| `violet500` | `#8b5cf6` |
| font | Inter |
| `backgroundImage` → `grid-pattern` | two linear gradients at 5% cyan opacity, 60px grid |

Applied via `bg-zinc-950 bg-grid-pattern text-zinc-100 font-sans` on the shop layout root.

## Source CSS

File: `packages/Webkul/Shop/src/Resources/assets/css/app.css`

Contains Tailwind directives and icon fonts only. Do not edit the compiled
`public/themes/shop/ghost-laser/build/` output directly.

## Build and deploy

1. Run `npm run build` locally in Laragon.
2. Upload the compiled `public/themes/shop/ghost-laser/build` folder to HostGator.

Never run build commands on the server.

## Reference

The original cyberpunk homepage lives in the separate repo `SPFXUNLIMITED/GHOST_LASER_FRONTEND`.
