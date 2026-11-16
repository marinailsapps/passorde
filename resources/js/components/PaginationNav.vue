<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

defineProps<{ page: Paginated<unknown> }>();
</script>

<template>
    <nav v-if="page.last_page > 1" class="mt-4 flex items-center justify-between gap-2" :aria-label="t('Pagination')">
        <Link
            v-if="page.prev_page_url"
            :href="page.prev_page_url"
            preserve-scroll
            class="inline-flex min-h-12 items-center rounded-lg border border-line bg-surface px-4"
        >
            {{ t('Previous') }}
        </Link>
        <span v-else />
        <span class="text-sm text-muted">{{ t('Page :current of :last', { current: page.current_page, last: page.last_page }) }}</span>
        <Link
            v-if="page.next_page_url"
            :href="page.next_page_url"
            preserve-scroll
            class="inline-flex min-h-12 items-center rounded-lg border border-line bg-surface px-4"
        >
            {{ t('Next') }}
        </Link>
        <span v-else />
    </nav>
</template>
