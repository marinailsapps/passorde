import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, describe, expect, it } from 'vitest';

import { brandCss, readBrand } from '../../../scripts/brand';
import { INSTALL_BANNER_KEY } from '../offline/install';
import { userDbName } from '../offline/db';

import { brand, storage, storageNames } from './brand';
import defaults from './brandDefaults.json';

const roots: string[] = [];
function rootWith(brandJson?: object, logo = false): string {
    const root = mkdtempSync(join(tmpdir(), 'brand-'));
    roots.push(root);
    if (brandJson) {
        mkdirSync(join(root, 'build-profile'));
        writeFileSync(join(root, 'build-profile/brand.json'), JSON.stringify(brandJson));
        if (logo) writeFileSync(join(root, 'build-profile/logo.png'), 'png');
    }
    return root;
}
afterEach(() => roots.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true })));

describe('storage names', () => {
    it('keep exactly the names an installation older than the setting used, from its prefix', () => {
        // The fictional profile tests/Fixtures/profiles/fictional sets phone.storage_prefix "pepiniere".
        expect(storageNames('pepiniere')).toEqual({
            sessionDb: 'pepiniere',
            userDbPrefix: 'pepiniere-user-',
            outboxLock: 'pepiniere-outbox',
            installBannerKey: 'pepiniere.installBanner.lastShown',
        });
    });

    it('are the neutral ones without a profile, everywhere the phone stores data', () => {
        expect(brand).toEqual(defaults);
        expect(storage).toEqual(storageNames('passorde'));
        expect(userDbName(42)).toBe('passorde-user-42');
        expect(INSTALL_BANNER_KEY).toBe('passorde.installBanner.lastShown');
    });
});

describe('the build brand', () => {
    it('is the neutral one without build-profile/', () => {
        const read = readBrand(rootWith());
        expect(read.values).toEqual(defaults);
        expect(read.fromProfile).toBe(false);
        expect(read.logo.endsWith('resources/images/logo.png')).toBe(true);
    });

    it('comes from build-profile/ when scripts/profile-assets.php wrote it, logo included', () => {
        const values = { ...defaults, name: 'Pépinière Exemple', short_name: 'Exemple', accent: '#2f5d3a', storage_prefix: 'pepiniere' };
        const root = rootWith(values, true);
        const read = readBrand(root);
        expect(read.values).toEqual(values);
        expect(storageNames(read.values.storage_prefix).outboxLock).toBe('pepiniere-outbox');
        expect(read.logo).toBe(join(root, 'build-profile/logo.png'));
    });

    it('keeps the older names a profile states, and reads the empty maps PHP writes as [] as empty objects', () => {
        const groupings = { family: 'category', kind: 'variant' };
        const read = readBrand(rootWith({ ...defaults, aliases: { client: { extra_field: 'member_code' }, article: [], totals: [], groupings } }));
        expect(read.values.aliases).toEqual({ client: { extra_field: 'member_code' }, article: {}, totals: {}, groupings });
        expect(readBrand(rootWith({ ...defaults, aliases: undefined })).values.aliases).toEqual({ client: {}, article: {}, totals: {}, groupings: {} });
    });

    it('refuses a production build without a stated profile, and values that would break the CSS or the storage names', () => {
        expect(() => readBrand(rootWith(), 'production')).toThrow('build-profile/brand.json is missing');
        expect(readBrand(rootWith(defaults), 'production').fromProfile).toBe(true);
        expect(() => readBrand(rootWith({ ...defaults, accent: 'red;}body{x:y' }))).toThrow('accent must be a colour');
        expect(() => readBrand(rootWith({ ...defaults, storage_prefix: 'Mon App' }))).toThrow('storage_prefix');
    });

    it('puts the accent colours into app.css', () => {
        const css = '@theme {\n    --color-accent: #334155;\n    --color-accent-strong: #1e293b;\n    --color-accent-soft: #e2e8f0;\n}';
        expect(brandCss(css, { ...defaults, accent: '#2f5d3a', accent_strong: '#1f3f27', accent_soft: '#e6f0e8' }))
            .toBe('@theme {\n    --color-accent: #2f5d3a;\n    --color-accent-strong: #1f3f27;\n    --color-accent-soft: #e6f0e8;\n}');
    });
});
