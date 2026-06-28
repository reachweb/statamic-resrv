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
            // Standalone Tailwind helper stylesheet, shipped separately so sites that
            // are not using Tailwind can opt in (see docs/frontend-setup). Kept out of
            // vite-frontend.config.js because an IIFE JS bundle requires a single input.
            input: {
                // Calendar styles, extracted here (rather than from the JS entry) so the
                // JS can stay a pure IIFE without Vite inlining the CSS into the bundle.
                'resrv-frontend': 'resources/css/resrv-frontend.css',
                'resrv-tailwind': 'resources/css/resrv-tailwind.css',
            },
            output: {
                assetFileNames: 'css/[name][extname]',
            },
        },
    },
});
