<script setup lang="ts">
import { router } from '@inertiajs/vue3';

import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';

interface PaytypeRow {
    id: number;
    label: string;
    is_enabled: boolean;
}

defineProps<{ paytypes: PaytypeRow[] }>();

function toggle(paytype: PaytypeRow): void {
    router.patch(`/admin/paytypes/${paytype.id}`, { is_enabled: !paytype.is_enabled }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="t('Payment methods')" back="/" />

        <p class="mb-2 text-sm text-muted">{{ t('Choose which methods sales people can pick.') }}</p>
        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="paytype in paytypes" :key="paytype.id">
                <label class="flex min-h-12 cursor-pointer items-center gap-3 px-4">
                    <input type="checkbox" class="size-5 accent-accent" :checked="paytype.is_enabled" @change="toggle(paytype)">
                    <span class="flex-1">{{ paytype.label }}</span>
                </label>
            </li>
        </ul>
    </AppLayout>
</template>
