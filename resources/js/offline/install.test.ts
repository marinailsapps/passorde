import { describe, expect, it } from 'vitest';

import { installPlatform, INSTALL_BANNER_INTERVAL_MS, shouldShowInstallBanner } from './install';
import { matchRoute } from './pages';

const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const IPHONE_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0 Mobile/15E148 Safari/604.1';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

describe('install banner', () => {
    const now = 1_700_000_000_000;

    it('shows at most once per 24 hours', () => {
        expect(shouldShowInstallBanner({ now, lastShown: null, standalone: false, platform: 'android' })).toBe(true);
        expect(shouldShowInstallBanner({ now, lastShown: now - INSTALL_BANNER_INTERVAL_MS + 1, standalone: false, platform: 'android' })).toBe(false);
        expect(shouldShowInstallBanner({ now, lastShown: now - INSTALL_BANNER_INTERVAL_MS, standalone: false, platform: 'ios' })).toBe(true);
    });

    it('never shows once the app runs installed, or where it cannot be installed', () => {
        expect(shouldShowInstallBanner({ now, lastShown: null, standalone: true, platform: 'android' })).toBe(false);
        expect(shouldShowInstallBanner({ now, lastShown: null, standalone: false, platform: null })).toBe(false);
    });

    it('uses the browser prompt on Android and the steps on iOS Safari only', () => {
        expect(installPlatform(ANDROID, true)).toBe('android');
        expect(installPlatform(ANDROID, false)).toBeNull();
        expect(installPlatform(IPHONE_SAFARI, false)).toBe('ios');
        expect(installPlatform(IPHONE_CHROME, false)).toBeNull();
    });
});

describe('offline routes', () => {
    const route = (path: string) => matchRoute(new URL(path, 'https://orders.example.com'));
    const uuid = '0b7e4a0e-5f7c-4d8e-9a55-3c1f2d9e8a10';

    it('renders the ordering routes from local data and nothing else', () => {
        expect(route('/')).toEqual({ name: 'home' });
        expect(route('/orders')).toEqual({ name: 'orders' });
        expect(route(`/orders/new?from=${uuid}`)).toEqual({ name: 'create', from: uuid });
        expect(route(`/orders/${uuid.toUpperCase()}`)).toEqual({ name: 'show', uuid });
        expect(route(`/orders/${uuid}/edit`)).toEqual({ name: 'edit', uuid });
        expect(route('/admin/orders')).toEqual({ name: 'unavailable' });
        expect(route('/account')).toEqual({ name: 'unavailable' });
    });
});
