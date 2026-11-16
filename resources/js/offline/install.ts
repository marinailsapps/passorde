/**
 * When to show the install banner: never once installed (standalone), at most
 * once per 24 h otherwise (the time it was last shown lives in localStorage),
 * and only where installing is possible: Android/Chrome after it fired
 * `beforeinstallprompt`, or iOS Safari (Partager → Sur l'écran d'accueil).
 */

import { storage } from '../lib/brand';

export const INSTALL_BANNER_INTERVAL_MS = 24 * 3600_000;
/** The localStorage key (phone.storage_prefix: never changes for an installation). */
export const INSTALL_BANNER_KEY = storage.installBannerKey;

export type InstallPlatform = 'android' | 'ios';

export function isIosSafari(userAgent: string): boolean {
    const ios = /iPad|iPhone|iPod/.test(userAgent) || (/Macintosh/.test(userAgent) && /Mobile/.test(userAgent));
    return ios && /Safari/.test(userAgent) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(userAgent);
}

export function installPlatform(userAgent: string, promptCaptured: boolean): InstallPlatform | null {
    if (promptCaptured) return 'android';
    return isIosSafari(userAgent) ? 'ios' : null;
}

export function shouldShowInstallBanner(input: { now: number; lastShown: number | null; standalone: boolean; platform: InstallPlatform | null }): boolean {
    if (input.standalone || input.platform === null) return false;
    return input.lastShown === null || input.now - input.lastShown >= INSTALL_BANNER_INTERVAL_MS;
}
