# Theme Notes

Short reference for future theme edits.

## Colors and fonts

File: `packages/Webkul/Shop/src/Resources/assets/css/app.css` (the `@theme` block —
Tailwind 4 reads its tokens from the CSS, so the package's legacy
`tailwind.config.js` is no longer part of the build).

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

Same file: the `@theme` block in `packages/Webkul/Shop/src/Resources/assets/css/app.css`.

Added tokens:

| Token | Value |
| --- | --- |
| `--font-sans` | Inter |
| `--background-image-grid-pattern` | two linear gradients at 5% cyan opacity, 60px grid |

The zinc, cyan and violet shades the theme uses (`zinc-950`, `cyan-400`, `cyan-500`,
`violet-500`) ship with Tailwind, so they need no token of their own.

Applied via `bg-zinc-950 bg-grid-pattern text-zinc-100 font-sans` on the shop layout root.

## ghost-laser theme (Bagisto storefront)

Blade sources: `shop/resources/themes/ghost-laser/views`, registered as `ghost-laser`
in `shop/config/themes.php` with the same Appearance section types as the default
theme (image carousel, product carousel, category carousel, footer links, static
content, services content).

| File | What it does |
| --- | --- |
| `home/index.blade.php` | Same structure as the default theme homepage (the `$sections` loop is copied verbatim), with a Ghost Laser hero, brand/catalog blocks, and fallback product carousels for featured machines, Yongli & Reci laser tubes, and laser parts that render only while no product carousel section is configured |
| `components/layouts/index.blade.php` | Default layout with the cyberpunk shell: Inter font, `bg-grid-pattern bg-zinc-950 font-sans text-zinc-100` on `<main>`, and the glow helpers plus dark header/footer surfaces |
| `components/layouts/services.blade.php` | Default services-content section restyled onto zinc/cyan |

Copy is sales-and-distribution: Ghost Laser sells its own machines, distributes
Yongli and Reci laser tubes and CloudRay parts, and stocks lenses, mirrors, fume
extractors and air pumps. Repair is the sister company's business.

Every other view falls back to `packages/Webkul/Shop/src/Resources/views`, so only
these files need to stay in sync with Bagisto's default theme.

## Source CSS

File: `packages/Webkul/Shop/src/Resources/assets/css/app.css`

Tailwind 4: the file imports Tailwind, declares the theme tokens in `@theme`, and
pulls the `resources/themes/ghost-laser/views` Blade files in with `@source` so the
classes the theme uses are generated. Do not edit the compiled
`public/themes/shop/ghost-laser/build/` output directly.

## Build and deploy

The `ghost-laser` build comes out of `packages/Webkul/Shop`, whose `vite.config.js`
writes to the theme's own `hot_file` (`shop-ghost-laser-vite.hot`) and
`build_directory` (`themes/shop/ghost-laser/build`) registered in `config/themes.php`.
Its entry points must stay in step with the `@bagistoVite([...])` call in the shop
layout — today `src/Resources/assets/css/app.css` and `src/Resources/assets/js/app.js`.
An entry point missing from the manifest makes every storefront page fail with a 500.

Because the store is served from the `/shop/public/` sub-directory, the build rewrites
asset URLs inside the compiled CSS to bare file names, which resolve next to the CSS.

1. `cd packages/Webkul/Shop`
2. `npm install`
3. `npm run build` locally in Laragon (the build has to run from this package
   directory: the public directory is resolved relative to it).
4. Upload the compiled `public/themes/shop/ghost-laser/build` folder to HostGator.

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
