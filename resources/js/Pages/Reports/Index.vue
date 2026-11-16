<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';

const fileMode = isFileMode();

const reports = [
    {
        href: '/reports/sales',
        title: 'Sales report',
        description: fileMode
            ? 'The exported orders of a period: quantities, HT, TTC per category, article, client, month or département. Excel download.'
            : 'What was delivered over a period, from the Evoliz delivery notes: quantities, HT, TTC per category, article, client, month or département. Excel download. Also works offline.',
    },
    {
        href: '/reports/stock',
        title: 'Stock by category',
        description: fileMode
            ? 'Stock, reserved, broken and gifts per article, stock to come and what to order. Excel download.'
            : 'Evoliz stock, reserved, broken and gifts per article, stock to come and what to order. Excel download.',
    },
    {
        href: '/reports/write-offs',
        title: 'Broken entries and gifts',
        description: fileMode
            ? 'Every broken entry and gift of a period, in an order or outside, with its author. Excel download.'
            : 'Every broken entry and gift of a period, in an order or outside, with its author and the Evoliz stock status. Excel download.',
    },
];
</script>

<template>
    <AppLayout>
        <PageHeader :title="t('Reports')" back="/" />
        <div class="grid gap-3">
            <Link
                v-for="report in reports"
                :key="report.href"
                :href="report.href"
                class="block rounded-xl border border-line bg-surface p-4 hover:border-accent"
            >
                <span class="block text-lg font-semibold">{{ t(report.title) }}</span>
                <span class="mt-1 block text-sm text-muted">{{ t(report.description) }}</span>
            </Link>
        </div>
    </AppLayout>
</template>
