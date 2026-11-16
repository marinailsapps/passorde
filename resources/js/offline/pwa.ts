import { reactive } from 'vue';

/**
 * Service worker registration (production builds only) and the install
 * prompt. A new version waits until the next launch; meanwhile the app offers
 * "Nouvelle version disponible — Recharger".
 */

interface InstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export const pwa = reactive({
    updateAvailable: false,
    reload: (): void => undefined,
    /** Android/Chrome: the deferred `beforeinstallprompt` event. */
    installPrompt: null as InstallPromptEvent | null,
});

/** Must run early: Chrome fires `beforeinstallprompt` once, soon after load. */
export function captureInstallPrompt(): void {
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        pwa.installPrompt = event as InstallPromptEvent;
    });
    window.addEventListener('appinstalled', () => (pwa.installPrompt = null));
}

export function isStandalone(): boolean {
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

export async function registerPwa(): Promise<void> {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return;

    // Registered at the root (not /build/) so the worker controls the whole app.
    const { Workbox } = await import('workbox-window');
    const workbox = new Workbox('/sw.js', { scope: '/' });
    workbox.addEventListener('waiting', () => (pwa.updateAvailable = true));
    pwa.reload = () => {
        workbox.addEventListener('controlling', () => window.location.reload());
        workbox.messageSkipWaiting();
    };
    await workbox.register();
}
