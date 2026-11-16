/**
 * The build side of the installation's brand (docs/RUNBOOK.md "Installation profile"):
 * what scripts/profile-assets.php wrote to build-profile/ (brand.json, logo.png), read by
 * vite.config.ts for the web manifest, the bundle's constants (resources/js/lib/brand.ts),
 * the accent colours of app.css and the stamp public/build/profile.json; and by
 * scripts/make-icons.mjs for the logo. No build-profile/: the neutral product.
 */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

import type { Plugin } from 'vite';

import defaults from '../resources/js/lib/brandDefaults.json';
import type { BuildBrand } from '../resources/js/lib/brand';

export const BUILD_PROFILE_DIR = 'build-profile';

export interface Brand {
    values: BuildBrand;
    /** The logo of the login page and the icons: the profile's, or resources/images/logo.png. */
    logo: string;
    /** Whether the values come from a profile build (build-profile/brand.json) rather than the neutral defaults. */
    fromProfile: boolean;
}

const COLOUR = /^#[0-9a-fA-F]{6}$/;
const PREFIX = /^[a-z0-9][a-z0-9_-]{0,39}$/;

/**
 * The brand of this build. With APP_ENV=production and no build-profile/brand.json the build stops:
 * a production build states its profile (scripts/profile-assets.php with PASSORDE_PROFILE, "none" included).
 */
export function readBrand(root: string, appEnv?: string): Brand {
    const dir = resolve(root, BUILD_PROFILE_DIR);
    const file = join(dir, 'brand.json');
    const neutralLogo = resolve(root, 'resources/images/logo.png');

    if (!existsSync(file)) {
        if (appEnv === 'production') {
            throw new Error(`${BUILD_PROFILE_DIR}/brand.json is missing: with APP_ENV=production run scripts/profile-assets.php first (PASSORDE_PROFILE=<dir> or none; docs/RUNBOOK.md "Installation profile").`);
        }
        return { values: { ...defaults }, logo: neutralLogo, fromProfile: false };
    }

    const read = JSON.parse(readFileSync(file, 'utf8')) as Partial<BuildBrand>;
    const values = { ...defaults, ...read } as BuildBrand;
    for (const key of ['theme_color', 'accent', 'accent_strong', 'accent_soft'] as const) {
        if (!COLOUR.test(values[key])) throw new Error(`${file}: ${key} must be a colour like #334155.`);
    }
    if (!PREFIX.test(values.storage_prefix)) throw new Error(`${file}: storage_prefix must be lower case letters, digits, "-" or "_".`);
    // PHP writes an empty map as []: every map is an object here.
    const aliases = (read.aliases ?? {}) as Partial<Record<keyof BuildBrand['aliases'], unknown>>;
    values.aliases = Object.fromEntries(
        (['client', 'article', 'totals', 'groupings'] as const).map((map) => {
            const value = aliases[map];
            return [map, value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, string>) : {}];
        }),
    ) as BuildBrand['aliases'];
    const logo = join(dir, 'logo.png');

    return { values, logo: existsSync(logo) ? logo : neutralLogo, fromProfile: true };
}

/** app.css with the brand's accent colours in place of its neutral ones (no inline style: CSP style-src 'self'). */
export function brandCss(css: string, values: BuildBrand): string {
    return css
        .replace(/(--color-accent:\s*)#[0-9a-fA-F]{3,8};/, `$1${values.accent};`)
        .replace(/(--color-accent-strong:\s*)#[0-9a-fA-F]{3,8};/, `$1${values.accent_strong};`)
        .replace(/(--color-accent-soft:\s*)#[0-9a-fA-F]{3,8};/, `$1${values.accent_soft};`);
}

/**
 * Puts the brand into the build: app.css's accent colours, `@brand/logo.png` (the login logo), and the stamp
 * public/build/profile.json (the values and the logo's SHA-256) that `passorde:check-profile` compares with the running profile.
 */
export function brandPlugin(brand: Brand): Plugin {
    return {
        name: 'passorde-brand',
        enforce: 'pre',
        resolveId(id) {
            return id === '@brand/logo.png' ? brand.logo : null;
        },
        transform(code, id) {
            return id.split('?')[0].endsWith('/resources/css/app.css') ? { code: brandCss(code, brand.values), map: null } : null;
        },
        generateBundle() {
            // The logo's SHA-256 beside the values: passorde:check-profile tells another logo apart (App\\Support\\Profile\\Brand::logoHash).
            const logo = createHash('sha256').update(readFileSync(brand.logo)).digest('hex');
            this.emitFile({ type: 'asset', fileName: 'profile.json', source: `${JSON.stringify({ ...brand.values, logo }, null, 4)}\n` });
        },
    };
}
