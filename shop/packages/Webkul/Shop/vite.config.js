import { defineConfig, loadEnv } from "vite";
import vue from "@vitejs/plugin-vue";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import path from "path";

export default defineConfig(({ mode }) => {
    /*
     * laravel-vite-plugin resolves the public directory against the working
     * directory, so the build has to be started from this package directory.
     */
    const envDir = "../../../";

    Object.assign(process.env, loadEnv(mode, envDir));

    return {
        build: {
            emptyOutDir: true,
            minify: "esbuild",
            cssCodeSplit: true,
            rollupOptions: {
                output: {
                    manualChunks: {
                        vue: ["vue"],
                        veeValidate: ["vee-validate", "@vee-validate/rules", "@vee-validate/i18n"],
                        vendor: ["axios", "mitt"]
                    }
                }
            }
        },

        envDir,

        server: {
            host: process.env.VITE_HOST || "localhost",
            port: process.env.VITE_PORT || 5173,
            cors: true,
        },

        plugins: [
            vue(),

            tailwindcss(),

            laravel({
                hotFile: "../../../public/shop-ghost-laser-vite.hot",
                publicDirectory: "../../../public",
                buildDirectory: "themes/shop/ghost-laser/build",
                input: [
                    "src/Resources/assets/css/app.css",
                    "src/Resources/assets/js/app.js",
                ],
                refresh: true,
                preload: false,
            }),
        ],

        experimental: {
            /*
             * The store is served from a sub-directory (/shop/public), so the
             * absolute "/themes/..." URLs Vite writes into the built CSS do not
             * resolve. Assets referenced from CSS are emitted next to the CSS
             * file itself, so their bare file name resolves from any base. Files
             * served straight from the public directory keep Vite's own URL.
             */
            renderBuiltUrl(filename, { hostType, type }) {
                if (hostType === "css" && type === "asset") {
                    return path.basename(filename);
                }
            },
        },
    };
});
