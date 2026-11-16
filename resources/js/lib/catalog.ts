import { usePage } from '@inertiajs/vue3';

import type { CatalogProp, CatalogVariant } from '@/types';

/**
 * The catalogue's variants and whether the order form has a "new article" button
 * (shared prop `catalog`, App\Domain\Catalog\Catalog, kept in the offline session).
 * A phone session stored before it existed keeps the button (disabled offline anyway).
 */
export function catalogProp(): CatalogProp {
    try {
        return (usePage()?.props?.catalog as CatalogProp | undefined) ?? { new_article: true, variants: [] };
    } catch {
        return { new_article: true, variants: [] };
    }
}

/** Variants a new article may have: the counting ones, first the default. */
export const newArticleVariants = (): CatalogVariant[] => catalogProp().variants.filter((variant) => variant.counts);
