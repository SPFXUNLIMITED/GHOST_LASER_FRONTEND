import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';

export default defineConfig(({ mode }) => {
    const envDir = '../../../';

    Object.assign(process.env, loadEnv(mode, envDir));

    return {
        envDir,

        build: {
            emptyOutDir: true,
        },

        server: {
            host: process.env.VITE_HOST || 'localhost',
            port: process.env.VITE_PORT || 5173,
            cors: true,
        },

        plugins: [
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),

            laravel({
                hotFile: 'public/custom-theme-vite.hot',
                publicDirectory: '../../../public',
                buildDirectory: 'themes/shop/custom-theme/build',
                input: [
                    'src/Resources/assets/css/app.css',
                    'src/Resources/assets/js/app.js',
                ],
                refresh: true,
            }),
        ],

        resolve: {
            alias: {
                '@': path.resolve(__dirname, 'src/Resources/assets'),
                vue: 'vue/dist/vue.esm-bundler.js',
            },
        },
    };
});
