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

## custom-theme (Blade port of the static site)

Blade sources live in this repo under `resources/themes/custom-theme/views`:

| File | Ported from |
| --- | --- |
| `components/layouts/index.blade.php` | `templates/header.php` (nav, logo, phone, mobile menu, user menu) |
| `components/layouts/footer.blade.php` | `templates/footer.php` |
| `home/index.blade.php` | `index.php` (hero, services, why-us, process, contact) |

The home page wraps everything in `<x-shop::layouts>`, sets the page title through
`<x-slot:title>` and pushes the glow styles through `@push('styles')`, which the
layout renders with `@stack('styles')`.

Copy the `resources/themes/custom-theme` folder into the Bagisto application, then
register the theme in the store's `config/themes.php` under the `shop` key:

```php
'shop' => [
    'custom-theme' => [
        'name'       => 'Custom Theme',
        'assets_path' => 'public/themes/shop/custom-theme',
        'views_path'  => 'resources/themes/custom-theme/views',
    ],
],
```

Set `'default' => 'custom-theme'` (or select the theme per channel) and then run in
the Bagisto app root:

```
php artisan view:clear
php artisan optimize:clear
```

Those artisan commands and `config/themes.php` belong to the Bagisto application,
not to this static-site repo, so they cannot be run from here.

## Reference

The original cyberpunk homepage lives in the separate repo `SPFXUNLIMITED/GHOST_LASER_FRONTEND`.

## custom-theme asset package (`packages/Webkul/CustomTheme`)

The compiled CSS/JS for `custom-theme` is built from this package:

| File | Purpose |
| --- | --- |
| `package.json` | npm package named `custom-theme` with the Vite/Tailwind toolchain |
| `vite.config.js` | `hotFile: public/custom-theme-vite.hot`, `buildDirectory: themes/shop/custom-theme/build`, inputs `src/Resources/assets/css/app.css` and `src/Resources/assets/js/app.js` |
| `tailwind.config.js` / `postcss.config.js` | Tailwind + PostCSS config (scans the `resources/themes/custom-theme/views` Blade files) |
| `src/Resources/assets/css/app.css` | Tailwind directives plus the glow helpers (`.glow-cyan`, `.glow-box`, `.btn-glow`, `.gradient-fade-bottom`, `.hero-grid`) |
| `src/Resources/assets/js/app.js` | Vue/axios entry point |
| `src/Resources/assets/{fonts,images,locales}` | Placeholders — copy these folders from `packages/Webkul/Shop/src/Resources/assets/` in the Bagisto app (the Shop package is not in this repo) |

### Deploy

1. Upload the whole `packages/Webkul/CustomTheme` folder to the server, keeping it at
   `packages/Webkul/CustomTheme` inside the Bagisto application root.
2. Copy `packages/Webkul/Shop/src/Resources/assets/fonts`, `images` and `locales`
   into `packages/Webkul/CustomTheme/src/Resources/assets/`.
3. `cd packages/Webkul/CustomTheme`
4. `npm install`
5. `npm run build`

The build output lands in `public/themes/shop/custom-theme/build`, which is the
`assets_path` registered for `custom-theme` in `config/themes.php`.
