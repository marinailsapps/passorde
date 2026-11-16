import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

import defaults from './resources/js/lib/brandDefaults.json';

// Unit tests for pure frontend logic (no browser, no Laravel plugin).
export default defineConfig({
    // The neutral brand (resources/js/lib/brand.ts): tests never depend on a profile build.
    define: {
        __PASSORDE_BRAND__: JSON.stringify(defaults),
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
