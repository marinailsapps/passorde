import '@fontsource-variable/inter';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import type { Page } from '@inertiajs/core';
import { createApp, h, type DefineComponent } from 'vue';

import { captureInstallPrompt, registerPwa } from './offline/pwa';
import { installOfflineRouter, localPage, offline, rememberServerPage } from './offline/runtime';
import { brand } from './lib/brand';
import { setWording } from './lib/i18n';
import type { Wording } from './types';

const appName = brand.short_name;

/**
 * The first page: normally the server's. When the service worker answered
 * with the offline shell (the network is down), the page for the current URL
 * is built from the phone's local data instead.
 */
async function initialPage(): Promise<Page> {
    const page = JSON.parse(document.getElementById('app')?.dataset.page ?? '{}') as Page;

    if (page.component !== 'Offline/Shell') {
        void rememberServerPage(page);
        return page;
    }

    offline.reachable = false;
    const local = await localPage(new URL(window.location.href));
    // Nobody has signed in on this phone with a network yet.
    return local ?? { ...page, component: 'Offline/Unavailable', props: { ...page.props, reason: 'session' }, url: window.location.pathname };
}

async function boot(): Promise<void> {
    const page = await initialPage();
    // The installation's wording, before the first page renders; then from every page (online or offline).
    setWording(page.props.wording as Wording | undefined);

    await createInertiaApp({
        page,
        title: (title) => (title ? `${title} · ${appName}` : appName),
        resolve: (name) => {
            const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
            return pages[`./Pages/${name}.vue`];
        },
        setup({ el, App, props, plugin }) {
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el);
        },
        // The bar's CSS is in app.css: an injected <style> would break the CSP (style-src 'self').
        progress: {
            color: brand.accent,
            includeCSS: false,
        },
    });

    installOfflineRouter();
    router.on('navigate', (event) => {
        setWording(event.detail.page.props.wording as Wording | undefined);
        void rememberServerPage(event.detail.page);
    });
    void registerPwa();
}

captureInstallPrompt();
void boot();
