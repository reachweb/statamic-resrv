import { defineConfig } from 'vite';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

export default defineConfig({
    build: {
        outDir: 'resources/frontend',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                main: 'resources/js/resrv-frontend.js',
                tailwind: 'resources/css/resrv-tailwind.css'
            },
            output: {
                entryFileNames: 'js/resrv-frontend.js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name.includes('resrv-tailwind.css')) {
                        return 'css/resrv-tailwind.css'; 
                    } 
                    return 'css/[name].[ext]';
                },
            }
        }
    },
    css: {
        postcss: {
            plugins: [
                tailwindcss('./tailwind-frontend.config.js'),
                autoprefixer(),
            ],
        },
    },
});
