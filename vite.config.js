import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: process.env.PRODUCT_VITE ? {
        host: '0.0.0.0',
        origin: 'http://127.0.0.1:5173',
        hmr: { host: '127.0.0.1', clientPort: 5173 },
    } : undefined,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
    ],
});
