import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// The offline present package player: one IIFE file plus one CSS file with no network use.
// `npm run build` runs this after the main build. It writes into public/build/standalone because the deploy ships
// public/build/ and nothing else from public/ (the main build empties public/build first, so this must run second).
// app/Services/Press/PresentPackage.php inlines both files into every offline package.
export default defineConfig({
    plugins: [vue()],
    publicDir: false,
    define: { 'process.env.NODE_ENV': '"production"', __VUE_OPTIONS_API__: 'false', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    build: {
        outDir: 'public/build/standalone',
        emptyOutDir: true,
        cssCodeSplit: false,
        sourcemap: false,
        target: 'es2020',
        lib: {
            entry: 'resources/js/press/player/standalone.js',
            name: 'DotPressPlayer',
            formats: ['iife'],
            fileName: () => 'press-player.iife.js',
            cssFileName: 'press-player',
        },
    },
});
