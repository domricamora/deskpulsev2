import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Fonts are self-hosted from resources/fonts and declared with @font-face in
// resources/css/app.css. The skeleton's hosted-font helper is deliberately NOT
// used: the Content-Security-Policy sets font-src 'self', so a third-party font
// host would be blocked outright. See docs/migration/ui-inventory.md §3.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
