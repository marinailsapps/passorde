<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationNav from '@/components/PaginationNav.vue';
import SearchField from '@/components/SearchField.vue';
import StatusChip from '@/components/StatusChip.vue';
import SyncStatusBar from '@/components/SyncStatusBar.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { clientExtraField, clientExtraLabel, clientSearchLabel } from '@/lib/clientExtraField';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { Paginated, SyncState } from '@/types';

interface ClientRow {
    id: number;
    company_name: string;
    contact_name: string | null;
    postcode: string | null;
    town: string | null;
    phone: string | null;
    extra_field: string | null;
    is_active: boolean;
    linked: boolean;
}

const props = defineProps<{
    clients: Paginated<ClientRow>;
    filters: { q: string; unlinked: boolean; inactive: boolean };
    /** Null in file mode. */
    sync: SyncState | null;
}>();

const fileMode = isFileMode();

const filters = reactive({ ...props.filters });

function apply(): void {
    router.get(
        '/admin/clients',
        { q: filters.q || undefined, unlinked: filters.unlinked ? 1 : undefined, inactive: filters.inactive ? 1 : undefined },
        { preserveState: true, preserveScroll: true, replace: true, only: ['clients', 'filters'] },
    );
}
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Clients')" back="/">
            <template v-if="fileMode" #actions>
                <UiButton variant="ghost" href="/admin/import" data-flow="clients-import">{{ t('Import') }}</UiButton>
            </template>
        </PageHeader>
        <SyncStatusBar v-if="sync" :sync="sync" action="/admin/clients/sync" class="mb-4" />

        <div class="mb-3 space-y-1">
            <SearchField v-model="filters.q" data-flow="admin-client-search" :label="clientSearchLabel()" @search="apply" />
            <div class="flex flex-wrap gap-x-6">
                <CheckboxField v-if="!fileMode" v-model="filters.unlinked" :label="t('Not yet in Evoliz')" @update:model-value="apply" />
                <CheckboxField v-model="filters.inactive" :label="t('Include inactive')" @update:model-value="apply" />
            </div>
        </div>

        <p class="mb-2 text-sm text-muted">{{ tc(':count clients', clients.total) }}</p>

        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="client in clients.data" :key="client.id">
                <Link :href="`/admin/clients/${client.id}`" class="flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-page">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ client.company_name }}</p>
                        <p class="text-sm break-words text-muted">
                            {{ [client.contact_name, [client.postcode, client.town].filter(Boolean).join(' ')].filter(Boolean).join(' · ') }}
                        </p>
                        <p class="text-sm break-words text-muted">
                            {{ client.phone }}<template v-if="client.extra_field && clientExtraField()"> · {{ clientExtraLabel() }} {{ client.extra_field }}</template>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <StatusChip v-if="!client.linked && !fileMode" tone="warning">{{ t('Not in Evoliz') }}</StatusChip>
                        <StatusChip v-if="!client.is_active" tone="neutral">{{ t('Inactive') }}</StatusChip>
                    </div>
                </Link>
            </li>
            <li v-if="clients.data.length === 0" class="px-4 py-6 text-center text-muted">{{ t('No client found.') }}</li>
        </ul>
        <PaginationNav :page="clients" />
    </AppLayout>
</template>
