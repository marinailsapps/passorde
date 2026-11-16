<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { LICENCE_PAGE } from '@/lib/licence';
import { isFileMode } from '@/lib/syncMode';

defineProps<{ badges: { pending: number; failed: number; to_review?: number; to_export?: number } | null }>();

const user = computed(() => usePage().props.auth.user);
const isAdmin = computed(() => user.value?.role === 'admin');

const main = [
    { href: '/orders/new', label: 'New order', primary: true, flow: 'home-new-order' },
    { href: '/orders', label: 'My orders', primary: false, flow: 'home-my-orders' },
    { href: '/reports', label: 'Reports', primary: false, flow: 'home-reports' },
    { href: '/catalog', label: 'Catalogue', primary: false, flow: 'home-catalog' },
];

const evolizOnly = ['/admin/evoliz-users', '/admin/sync'];
// Evoliz switched on, running or not (licence lapsed, keys missing): its settings and licence stay at hand.
const evolizSwitchedOn = computed(() => (usePage().props.evolizState ?? null) !== null);

const admin = [
    { href: '/admin/orders', label: 'All orders' },
    { href: '/admin/users', label: 'Users' },
    { href: '/admin/clients', label: 'Clients' },
    { href: '/admin/article-attributes', label: 'Article attributes' },
    { href: '/admin/category-thresholds', label: 'Thresholds per category' },
    { href: '/admin/stock-adjustments', label: 'Broken / Gifts' },
    { href: '/admin/evoliz-users', label: 'Evoliz sellers' },
    { href: '/admin/sync', label: 'Synchronisation' },
    { href: '/admin/import', label: 'Import', fileOnly: true },
    { href: '/admin/paytypes', label: 'Payment methods', fileOnly: true },
    { href: '/admin/settings', label: 'Evoliz settings', evolizSwitch: true },
    // Passorde's own page (Blade): a full page load, not an Inertia visit.
    { href: LICENCE_PAGE, label: 'Licence', plain: true, evolizSwitch: true },
]
    .filter((item) => (isFileMode() ? !evolizOnly.includes(item.href) : !item.fileOnly))
    .filter((item) => !item.evolizSwitch || evolizSwitchedOn.value);
</script>

<template>
    <Head :title="t('Home')" />
    <AppLayout>
        <p class="mb-4 text-muted">{{ t('Hello :name', { name: user?.name ?? '' }) }}</p>
        <div class="grid gap-3">
            <Link
                v-for="item in main"
                :key="item.href"
                :href="item.href"
                :data-flow="item.flow"
                class="flex min-h-20 items-center justify-center rounded-xl text-lg font-semibold shadow-sm"
                :class="item.primary ? 'bg-accent text-white hover:bg-accent-strong' : 'border border-line bg-surface hover:bg-page'"
            >
                {{ t(item.label) }}
            </Link>
        </div>
        <template v-if="isAdmin">
            <h2 class="mt-8 mb-3 text-sm font-medium tracking-wide text-muted uppercase">{{ t('Administration') }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <component
                    :is="item.plain ? 'a' : Link"
                    v-for="item in admin"
                    :key="item.href"
                    :href="item.href"
                    class="relative flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border border-line bg-surface px-2 text-center font-medium hover:bg-page"
                >
                    {{ t(item.label) }}
                    <span v-if="item.href === '/admin/orders' && badges" class="flex gap-1">
                        <span v-if="badges.pending" class="rounded-full bg-amber-100 px-2 text-xs text-amber-900">{{ t(':count pending', { count: badges.pending }) }}</span>
                        <span v-if="badges.failed" class="rounded-full bg-red-100 px-2 text-xs text-red-800">{{ t(':count failed', { count: badges.failed }) }}</span>
                        <span v-if="badges.to_export" class="rounded-full bg-sky-100 px-2 text-xs text-sky-900" data-flow="badge-to-export">{{ t(':count to export', { count: badges.to_export }) }}</span>
                    </span>
                    <span v-if="item.href === '/admin/article-attributes' && badges?.to_review" class="rounded-full bg-amber-100 px-2 text-xs text-amber-900">
                        {{ t(':count to check', { count: badges.to_review }) }}
                    </span>
                </component>
            </div>
        </template>
    </AppLayout>
</template>
