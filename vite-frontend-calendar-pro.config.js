import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        outDir: 'resources/frontend',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                main: 'resources/js/resrv-calendar-pro.js',
            },
            output: {
                entryFileNames: 'js/resrv-calendar-pro.js',
                assetFileNames: 'css/resrv-calendar-pro.css',
            }
        }
    },
});
