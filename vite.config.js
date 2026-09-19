import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Fonts are self-hosted from resources/fonts and declared with @font-face in
// resources/css/app.css. The skeleton's hosted-font helper is deliberately NOT
// used: the Content-Security-Policy sets font-src 'self', so a third-party font
// host would be blocked outright. See docs/migration/ui-inventory.md §3.
export default defineConfig({
    // Emit asset URLs *inside* built CSS relative to the stylesheet rather than to
    // the domain root. The app has to work from a subdirectory — dev serves it at
    // /deskpulsev2/public/ — and an absolute /build/... path 404s there.
    // The <link>/<script> tags are unaffected: laravel-vite-plugin builds those from
    // the manifest and APP_URL. See docs/migration/architecture.md §2.
    base: './',

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
