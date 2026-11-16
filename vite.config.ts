import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';

import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

import { brandPlugin, readBrand } from './scripts/brand';

const fileRevision = (path: string): string => createHash('md5').update(readFileSync(path)).digest('hex');
const icons = ['icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png', 'favicon-32.png'];

// public/icons is generated (gitignored): on a fresh clone the dev server and a plain `vite build` make it first.
if (icons.some((icon) => !existsSync(`public/icons/${icon}`)) || !existsSync('public/favicon.ico')) {
    execFileSync(process.execPath, ['scripts/make-icons.mjs'], { stdio: 'inherit' });
}

export default defineConfig(({ mode }) => {
    // VITE_PORT from .env: the port the dev server listens on in the container and on the host.
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5193);
    // The installation's name, colours, logo and storage prefix (build-profile/, scripts/profile-assets.php).
    const brand = readBrand(process.cwd(), process.env.APP_ENV ?? env.APP_ENV);

    return {
        define: {
            __PASSORDE_BRAND__: JSON.stringify(brand.values),
        },
        plugins: [
            brandPlugin(brand),
            laravel({
                input: ['resources/js/app.ts'],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
            // Offline app shell (docs/API.md "Offline mirror"). The service worker
            // (resources/js/sw.ts) is written to public/ so its scope is the whole
            // site; built assets and the web manifest stay under /build/ (Laravel
            // serves the manifest at /manifest.webmanifest, see AppShellController).
            VitePWA({
                strategies: 'injectManifest',
                srcDir: 'resources/js',
                filename: 'sw.ts',
                outDir: 'public',
                base: '/',
                scope: '/',
                buildBase: '/build/',
                injectRegister: false,
                manifest: {
                    name: brand.values.name,
                    short_name: brand.values.short_name,
                    lang: 'fr',
                    start_url: '/',
                    scope: '/',
                    display: 'standalone',
                    orientation: 'portrait',
                    theme_color: brand.values.theme_color,
                    background_color: '#f7f7f5',
                    icons: [
                        { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                        { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                        { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                    ],
                },
                injectManifest: {
                    globDirectory: 'public/build',
                    globPatterns: ['assets/**/*.{js,css,woff2}'],
                    modifyURLPrefix: { assets: '/build/assets' },
                    additionalManifestEntries: icons.map((icon) => ({ url: `/icons/${icon}`, revision: fileRevision(`public/icons/${icon}`) })),
                    // The shell page embeds the hashed asset names: it changes whenever they do.
                    manifestTransforms: [
                        (entries) => {
                            const revision = createHash('md5').update(entries.map((entry) => `${entry.url}${entry.revision ?? ''}`).join()).digest('hex');
                            return { manifest: [...entries, { url: '/app-shell', revision, size: 0 }], warnings: [] };
                        },
                    ],
                },
                devOptions: { enabled: false },
            }),
        ],
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
        server: {
            // Listen on all interfaces so the compose "vite" service is reachable from the host.
            host: '0.0.0.0',
            port,
            strictPort: true,
            // Asset URLs written to public/hot and HMR websocket both use the mapped host port.
            origin: `http://localhost:${port}`,
            // The page is served by Laravel on APP_PORT; let it load modules from this server.
            cors: { origin: `http://localhost:${Number(env.APP_PORT || 8093)}` },
            hmr: {
                host: 'localhost',
                clientPort: port,
            },
            watch: {
                ignored: ['**/storage/framework/views/**', '**/vendor/**'],
            },
        },
    };
});
