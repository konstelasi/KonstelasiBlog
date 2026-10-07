import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// The only front end here is the Filament theme. Everything else the app
// serves is JSON or a redirect.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
