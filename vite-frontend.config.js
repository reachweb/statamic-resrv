import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
    ],
    build: {
        outDir: 'resources/frontend',
        emptyOutDir: false,
        rollupOptions: {
            // Single JS input so the output can be wrapped in an IIFE. The bundle
            // is loaded as a classic <script> (see docs/frontend-setup), so an IIFE
            // is required to keep its internal symbols (e.g. the date-format parser
            // that minifies to `L`) out of the global scope where they would clash
            // with libraries such as Leaflet (window.L). Only the deliberate
            // `window.dayjs` assignment is allowed to escape the closure.
            input: {
                'resrv-frontend': 'resources/js/resrv-frontend.js',
            },
            output: {
                format: 'iife',
                entryFileNames: 'js/[name].js',
                assetFileNames: 'css/[name][extname]',
            },
        },
    },
});
