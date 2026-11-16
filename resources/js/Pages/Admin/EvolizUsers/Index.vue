<script setup lang="ts">
import { router } from '@inertiajs/vue3';

import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { t, tc } from '@/lib/i18n';

interface EvolizUserRow {
    id: number;
    evoliz_user_id: number;
    label: string | null;
    user_id: number | null;
    deliveries: number;
    last_date: string | null;
}

defineProps<{
    evolizUsers: EvolizUserRow[];
    users: { id: number; name: string; is_active: boolean }[];
}>();

function map(row: EvolizUserRow, value: string): void {
    router.patch(`/admin/evoliz-users/${row.id}`, { user_id: value === '' ? null : Number(value) }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="t('Evoliz sellers')" back="/" />
        <p class="mb-4 text-sm text-muted">
            {{ t('Evoliz users who created delivery notes. A BL created from an app order shows that order’s author; any other BL shows the app user chosen here, or “Evoliz”.') }}
        </p>

        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="row in evolizUsers" :key="row.id" class="flex flex-wrap items-center gap-3 px-4 py-3" data-flow="evoliz-user">
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ row.label ?? t('Evoliz user #:id', { id: row.evoliz_user_id }) }}</p>
                    <p class="text-sm text-muted">
                        {{ tc(':count delivery notes', row.deliveries) }}<template v-if="row.last_date"> · {{ t('last :date', { date: formatDate(row.last_date) }) }}</template>
                    </p>
                </div>
                <label class="w-full sm:w-64">
                    <span class="sr-only">{{ t('App user') }}</span>
                    <select
                        class="min-h-12 w-full rounded-lg border border-field bg-surface px-3"
                        :value="row.user_id ?? ''"
                        @change="map(row, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">{{ t('Not mapped (Evoliz)') }}</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}{{ user.is_active ? '' : ` (${t('inactive')})` }}</option>
                    </select>
                </label>
            </li>
            <li v-if="evolizUsers.length === 0" class="px-4 py-6 text-center text-muted">{{ t('No Evoliz user yet: run the delivery note synchronisation.') }}</li>
        </ul>
    </AppLayout>
</template>
