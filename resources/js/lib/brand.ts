/**
 * The installation's name, colours and storage prefix as the assets were built
 * (profile sections `brand` and `phone`, written by scripts/profile-assets.php
 * and put in the bundle by scripts/brand.ts). Without a profile: the neutral
 * values of brandDefaults.json, pinned equal to config/passorde.php by
 * tests/Feature/Profile/BrandTest.php. No browser globals: the service worker
 * and Vitest read it too.
 */
export interface BuildBrand {
    name: string;
    short_name: string;
    theme_color: string;
    accent: string;
    accent_strong: string;
    accent_soft: string;
    storage_prefix: string;
    /** Older names (profile `aliases`), current => old; for groupings old => current. Empty in a new installation. */
    aliases: { client: Record<string, string>; article: Record<string, string>; totals: Record<string, string>; groupings: Record<string, string> };
}

declare const __PASSORDE_BRAND__: BuildBrand;

export const brand: BuildBrand = __PASSORDE_BRAND__;

/** Where the phone keeps its data. Never changes for an installation once phones use it (RUNBOOK "Installation profile"). */
export interface StorageNames {
    /** IndexedDB database remembering who signed in last (offline/db.ts SessionDb). */
    sessionDb: string;
    /** Start of every user's IndexedDB database name ("Effacer les données de ce téléphone" deletes them all). */
    userDbPrefix: string;
    /** Web Lock shared by the page and the service worker around the outbox. */
    outboxLock: string;
    /** localStorage key of the install banner's last showing. */
    installBannerKey: string;
}

export function storageNames(prefix: string): StorageNames {
    return {
        sessionDb: prefix,
        userDbPrefix: `${prefix}-user-`,
        outboxLock: `${prefix}-outbox`,
        installBannerKey: `${prefix}.installBanner.lastShown`,
    };
}

export const storage: StorageNames = storageNames(brand.storage_prefix);
