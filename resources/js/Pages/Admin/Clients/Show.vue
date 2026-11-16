<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusChip from '@/components/StatusChip.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { CORRECTABLE, fieldLabel, type ClientField } from '@/lib/clientCorrection';
import { formatDateTime } from '@/lib/format';
import { clientExtraField, clientExtraLabel } from '@/lib/clientExtraField';
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { ClientChangeView } from '@/types';

interface ClientDetail {
    id: number;
    evoliz_client_id: number | null;
    evoliz_code: string | null;
    company_name: string;
    contact_name: string | null;
    address_line: string | null;
    postcode: string | null;
    town: string | null;
    phone: string | null;
    mobile: string | null;
    email: string | null;
    extra_field: string | null;
    is_active: boolean;
    source: 'evoliz' | 'app' | 'import';
    linked: boolean;
    evoliz_error: string | null;
    can_push: boolean;
    synced_at: string | null;
}

interface HistoryEntry extends ClientChangeView {
    id: number;
    source: 'order' | 'evoliz' | 'admin' | 'import';
    created_at: string;
    author: string | null;
    order: { uuid: string; number: string } | null;
    can_retry: boolean;
}

const props = defineProps<{ client: ClientDetail; history: HistoryEntry[] }>();

const fileMode = isFileMode();

const tones = { applied: 'success', failed: 'danger', pending: 'warning', rejected: 'neutral', cancelled: 'neutral' } as const;
const statusLabels: Record<ClientChangeView['status'], string> = {
    applied: t('Applied'),
    failed: t('Refused by Evoliz'),
    pending: t('Waiting for approval'),
    rejected: t('Left out'),
    cancelled: t('Cancelled'),
};

/** Fields of an entry in display order; the sync also records the company name, an import or an admin in file mode the code and the active flag. */
const ownLabels: Record<string, string> = { company_name: t('Company'), evoliz_code: t('Client code'), is_active: t('Active') };
const activeValue = (value: string | null) => (value === '1' ? t('Yes') : t('No'));
const rowsOf = (entry: HistoryEntry) =>
    (['evoliz_code', 'company_name', ...CORRECTABLE, 'is_active'] as const)
        .filter((field) => entry.changes[field as ClientField])
        .map((field) => {
            const pair = entry.changes[field as ClientField]!;
            return {
                field,
                label: ownLabels[field] ?? fieldLabel(field as ClientField),
                from: field === 'is_active' ? activeValue(pair.from) : pair.from,
                to: field === 'is_active' ? activeValue(pair.to) : pair.to,
            };
        });

const retrying = ref<number | null>(null);

function retry(entry: HistoryEntry): void {
    retrying.value = entry.id;
    router.post(`/admin/clients/${props.client.id}/changes/${entry.id}/retry`, {}, { preserveScroll: true, onFinish: () => (retrying.value = null) });
}

const pushing = ref(false);

function push(): void {
    pushing.value = true;
    router.post(`/admin/clients/${props.client.id}/push`, {}, { preserveScroll: true, onFinish: () => (pushing.value = false) });
}

const form = useForm({ extra_field: props.client.extra_field ?? '' });

function save(): void {
    form.patch(`/admin/clients/${props.client.id}`, { preserveScroll: true });
}

/* File mode: the app is the directory, so every field can be changed here. */
const fullForm = useForm({
    evoliz_code: props.client.evoliz_code ?? '',
    company_name: props.client.company_name,
    contact_name: props.client.contact_name ?? '',
    address_line: props.client.address_line ?? '',
    postcode: props.client.postcode ?? '',
    town: props.client.town ?? '',
    phone: props.client.phone ?? '',
    mobile: props.client.mobile ?? '',
    email: props.client.email ?? '',
    extra_field: props.client.extra_field ?? '',
    is_active: props.client.is_active,
});

function saveAll(): void {
    fullForm.patch(`/admin/clients/${props.client.id}`, {
        preserveScroll: true,
        onSuccess: () => fullForm.defaults(),
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="client.company_name" back="/admin/clients" />

        <div class="mb-3 flex flex-wrap gap-2">
            <template v-if="fileMode">
                <StatusChip v-if="client.evoliz_code" tone="success">{{ t('Code :code', { code: client.evoliz_code }) }}</StatusChip>
            </template>
            <template v-else>
                <StatusChip v-if="client.linked" tone="success">{{ t('Evoliz :code', { code: client.evoliz_code ?? '' }) }}</StatusChip>
                <StatusChip v-else tone="warning">{{ t('Not in Evoliz') }}</StatusChip>
            </template>
            <StatusChip v-if="!client.is_active" tone="neutral">{{ t('Inactive') }}</StatusChip>
        </div>

        <div v-if="client.can_push" class="mb-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm" data-flow="client-push">
            <p v-if="client.evoliz_error" class="mb-2 break-words text-red-900">{{ client.evoliz_error }}</p>
            <p v-else class="mb-2 text-amber-900">{{ t('This client goes to Evoliz when its first order is approved, or now with this button.') }}</p>
            <UiButton :disabled="pushing" @click="push">{{ t('Send to Evoliz') }}</UiButton>
        </div>

        <form v-if="fileMode" class="space-y-3 rounded-xl border border-line bg-surface p-4" novalidate data-flow="client-edit" @submit.prevent="saveAll">
            <TextField v-model="fullForm.company_name" :label="t('Company')" :error="fullForm.errors.company_name" required />
            <TextField v-model="fullForm.evoliz_code" :label="t('Client code')" :error="fullForm.errors.evoliz_code" />
            <TextField v-model="fullForm.contact_name" :label="fieldLabel('contact_name')" :error="fullForm.errors.contact_name" />
            <TextField v-model="fullForm.address_line" :label="fieldLabel('address_line')" :error="fullForm.errors.address_line" />
            <div class="grid grid-cols-2 gap-3">
                <TextField v-model="fullForm.postcode" :label="fieldLabel('postcode')" inputmode="numeric" :error="fullForm.errors.postcode" />
                <TextField v-model="fullForm.town" :label="fieldLabel('town')" :error="fullForm.errors.town" />
                <TextField v-model="fullForm.phone" :label="fieldLabel('phone')" inputmode="tel" :error="fullForm.errors.phone" />
                <TextField v-model="fullForm.mobile" :label="fieldLabel('mobile')" inputmode="tel" :error="fullForm.errors.mobile" />
            </div>
            <TextField v-model="fullForm.email" :label="fieldLabel('email')" type="email" inputmode="email" :error="fullForm.errors.email" />
            <TextField v-if="clientExtraField()" v-model="fullForm.extra_field" :label="clientExtraLabel()" :error="fullForm.errors.extra_field" />
            <CheckboxField v-model="fullForm.is_active" :label="t('Active')" :error="fullForm.errors.is_active" />
            <UiButton type="submit" :disabled="fullForm.processing || !fullForm.isDirty">{{ t('Save') }}</UiButton>
        </form>

        <dl v-if="!fileMode" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
            <dt class="text-muted">{{ t('Contact') }}</dt>
            <dd>{{ client.contact_name ?? '—' }}</dd>
            <dt class="text-muted">{{ t('Address') }}</dt>
            <dd>
                {{ client.address_line ?? '—' }}<br>
                {{ [client.postcode, client.town].filter(Boolean).join(' ') }}
            </dd>
            <dt class="text-muted">{{ t('Phone') }}</dt>
            <dd>
                <a v-if="client.phone" :href="`tel:${client.phone}`" class="-my-3 inline-flex min-h-11 items-center text-accent underline">{{ client.phone }}</a>
                <template v-else>—</template>
            </dd>
            <dt class="text-muted">{{ t('Mobile') }}</dt>
            <dd>{{ client.mobile ?? '—' }}</dd>
            <dt class="text-muted">{{ t('Email') }}</dt>
            <dd class="break-all">{{ client.email ?? '—' }}</dd>
            <dt class="text-muted">{{ t('Synced') }}</dt>
            <dd>{{ formatDateTime(client.synced_at) }}</dd>
        </dl>

        <form v-if="!fileMode && clientExtraField()" class="mt-4 space-y-3 rounded-xl border border-line bg-surface p-4" @submit.prevent="save">
            <TextField
                v-model="form.extra_field"
                :label="clientExtraLabel()"
                :hint="t('Stored only in the app; Evoliz has no field for it.')"
                :error="form.errors.extra_field"
            />
            <UiButton type="submit" :disabled="form.processing || !form.isDirty">{{ t('Save') }}</UiButton>
        </form>

        <section class="mt-4 rounded-xl border border-line bg-surface p-4" data-flow="client-history">
            <h2 class="font-semibold">{{ t('Change history') }}</h2>
            <p v-if="history.length === 0" class="mt-2 text-sm text-muted">{{ t('No change recorded yet.') }}</p>
            <ol v-else class="mt-2 divide-y divide-line">
                <li v-for="entry in history" :key="entry.id" class="py-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-medium">{{ formatDateTime(entry.created_at) }}</span>
                        <span class="text-muted">
                            <template v-if="entry.source === 'evoliz'">{{ t('Changed in Evoliz') }}</template>
                            <template v-else>{{ entry.author ?? '—' }}</template>
                            <template v-if="entry.order">
                                · <Link :href="`/orders/${entry.order.uuid}`" class="text-accent underline">{{ entry.order.number }}</Link>
                            </template>
                        </span>
                        <StatusChip :tone="tones[entry.status]">{{ statusLabels[entry.status] }}</StatusChip>
                    </div>
                    <dl class="mt-1 space-y-0.5 text-sm">
                        <div v-for="row in rowsOf(entry)" :key="row.field" class="min-w-0 break-words">
                            <dt class="mr-1 inline text-muted">{{ row.label }} :</dt>
                            <dd class="inline">
                                <span class="text-muted line-through">{{ row.from ?? '—' }}</span>
                                → <span class="font-medium">{{ row.to ?? t('(removed)') }}</span>
                            </dd>
                        </div>
                    </dl>
                    <p v-if="entry.status === 'failed' && entry.error" class="mt-1 text-sm break-words text-red-800">{{ entry.error }}</p>
                    <p v-if="entry.decided_by && entry.source === 'order'" class="mt-1 text-xs text-muted">{{ t('Decided by :name', { name: entry.decided_by }) }}</p>
                    <UiButton v-if="entry.can_retry" variant="secondary" class="mt-2" :disabled="retrying === entry.id" @click="retry(entry)">{{ t('Retry') }}</UiButton>
                </li>
            </ol>
        </section>
    </AppLayout>
</template>
