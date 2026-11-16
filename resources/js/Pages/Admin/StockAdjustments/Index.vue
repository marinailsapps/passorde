<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationNav from '@/components/PaginationNav.vue';
import SearchPicker from '@/components/SearchPicker.vue';
import SelectField from '@/components/SelectField.vue';
import StatusChip from '@/components/StatusChip.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { clientSearchLabel } from '@/lib/clientExtraField';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { ArticleHit, ClientData, Paginated } from '@/types';

type Kind = 'broken' | 'gift';
type EvolizStatus = 'pending' | 'sent' | 'failed' | 'disabled' | 'skipped';

interface Entry {
    id: number;
    kind: Kind;
    happened_on: string;
    quantity: string;
    article: { id: number; reference: string; designation: string };
    client: { id: number; company_name: string } | null;
    note: string | null;
    user: string;
    created_at: string;
    deleted: boolean;
    evoliz_status: EvolizStatus | null;
    evoliz_error: string | null;
    evoliz_before: string | null;
    evoliz_after: string | null;
    can_delete: boolean;
    can_retry: boolean;
}

const fileMode = isFileMode();

const props = defineProps<{
    entries: Paginated<Entry>;
    filters: { kind: string; from: string; to: string; article_id: number | null };
    today: string;
}>();

const kinds = [
    { value: 'broken', label: t('Broken entries') },
    { value: 'gift', label: t('Gift outside an order') },
];

/* New entry */
const article = ref<ArticleHit | null>(null);
const client = ref<ClientData | null>(null);
const form = useForm({ kind: 'broken' as Kind, article_id: null as number | null, quantity: '', happened_on: props.today, client_id: null as number | null, note: '' });

function submit(): void {
    form.article_id = article.value?.id ?? null;
    form.client_id = form.kind === 'gift' ? (client.value?.id ?? null) : null;
    form.transform((data) => ({ ...data, quantity: data.quantity.replace(',', '.') })).post('/admin/stock-adjustments', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('quantity', 'note');
            article.value = null;
            client.value = null;
        },
    });
}

/* Filters */
const filters = reactive({ kind: props.filters.kind, from: props.filters.from, to: props.filters.to });
const kindFilters = [{ value: '', label: t('All') }, ...kinds];

function apply(): void {
    router.get(
        '/admin/stock-adjustments',
        { kind: filters.kind || undefined, from: filters.from || undefined, to: filters.to || undefined, article_id: props.filters.article_id ?? undefined },
        { preserveState: true, preserveScroll: true, replace: true, only: ['entries', 'filters'] },
    );
}

/* Evoliz stock: status of the deduction (or of the add-back, for a deleted entry) */
const evolizChip: Record<EvolizStatus, { tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info'; label: string }> = {
    pending: { tone: 'neutral', label: t('Evoliz: in progress') },
    sent: { tone: 'success', label: t('Evoliz: stock updated') },
    failed: { tone: 'danger', label: t('Evoliz: failed') },
    disabled: { tone: 'warning', label: t('Evoliz: not sent (test mode)') },
    skipped: { tone: 'neutral', label: t('Evoliz: no stock management') },
};
const retrying = ref<number | null>(null);

function retry(entry: Entry): void {
    retrying.value = entry.id;
    router.post(`/admin/stock-adjustments/${entry.id}/retry`, {}, { preserveScroll: true, onFinish: () => (retrying.value = null) });
}

/* Delete (24 h) */
const deleting = ref<Entry | null>(null);
const busy = ref(false);

function destroy(): void {
    if (!deleting.value) return;
    busy.value = true;
    router.delete(`/admin/stock-adjustments/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Broken / Gifts')" back="/" />
        <p class="mb-4 text-sm text-muted">
            {{ fileMode ? t('Broken stock and gifts made outside an order. Each entry is also subtracted from the stock of the article; deleting it (within 24 hours) puts the quantity back.') : t('Broken stock and gifts made outside an order. Each entry is also subtracted from the Evoliz stock of the article; deleting it (within 24 hours) puts the quantity back.') }}
        </p>

        <div class="lg:grid lg:grid-cols-[22rem_1fr] lg:gap-6">
            <form class="mb-6 space-y-3 rounded-xl border border-line bg-surface p-4 lg:sticky lg:top-20 lg:self-start" @submit.prevent="submit">
                <h2 class="font-semibold">{{ t('New entry') }}</h2>
                <fieldset class="grid grid-cols-2 gap-2">
                    <legend class="sr-only">{{ t('Kind') }}</legend>
                    <label
                        v-for="kind in kinds"
                        :key="kind.value"
                        class="flex min-h-12 cursor-pointer items-center justify-center rounded-lg border px-2 text-center text-sm font-medium"
                        :class="form.kind === kind.value ? 'border-accent bg-accent text-white' : 'border-field bg-surface'"
                    >
                        <input v-model="form.kind" type="radio" class="sr-only" :value="kind.value">
                        {{ kind.label }}
                    </label>
                </fieldset>
                <SearchPicker
                    v-model="article"
                    endpoint="/api/articles/search"
                    :label="t('Article')"
                    :placeholder="t('Article: reference or designation')"
                    :title="(a) => a.reference ?? a.designation"
                    :subtitle="(a) => a.designation"
                    :error="form.errors.article_id"
                />
                <div class="grid grid-cols-2 gap-3">
                    <TextField v-model="form.quantity" :label="t('Quantity')" inputmode="numeric" :error="form.errors.quantity" required />
                    <TextField v-model="form.happened_on" :label="t('Date')" type="date" :error="form.errors.happened_on" required />
                </div>
                <SearchPicker
                    v-if="form.kind === 'gift'"
                    v-model="client"
                    endpoint="/api/clients/search"
                    :label="t('Client (optional)')"
                    :placeholder="clientSearchLabel()"
                    :title="(c) => c.company_name"
                    :subtitle="(c) => [c.postcode, c.town].filter(Boolean).join(' ') || null"
                    :error="form.errors.client_id"
                />
                <TextField v-model="form.note" :label="t('Note (optional)')" :error="form.errors.note" />
                <UiButton type="submit" block :disabled="form.processing || !article">{{ t('Record') }}</UiButton>
            </form>

            <section>
                <div class="mb-3 grid gap-2 sm:grid-cols-3">
                    <SelectField v-model="filters.kind" :label="t('Kind')" :options="kindFilters" @update:model-value="apply" />
                    <TextField v-model="filters.from" :label="t('From')" type="date" @change="apply" />
                    <TextField v-model="filters.to" :label="t('To')" type="date" @change="apply" />
                </div>
                <p class="mb-2 text-sm text-muted">{{ tc(':count entries', entries.total) }}</p>
                <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
                    <li v-for="entry in entries.data" :key="entry.id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start sm:gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2">
                                <StatusChip :tone="entry.kind === 'broken' ? 'danger' : 'info'">{{ entry.kind === 'broken' ? t('Broken') : t('Gift') }}</StatusChip>
                                <StatusChip v-if="entry.deleted" tone="neutral">{{ t('Deleted: stock to give back') }}</StatusChip>
                                <span class="font-medium">{{ entry.article.reference }}</span>
                                <span class="tabular-nums">× {{ formatNumber(entry.quantity) }}</span>
                            </p>
                            <p class="mt-1 text-sm break-words text-muted">{{ entry.article.designation }}</p>
                            <p class="mt-1 text-sm text-muted">
                                {{ formatDate(entry.happened_on) }}<template v-if="entry.client"> · {{ entry.client.company_name }}</template> · {{ entry.user }}
                            </p>
                            <p v-if="entry.note" class="mt-1 text-sm break-words">{{ entry.note }}</p>
                            <p v-if="entry.evoliz_status" class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                <StatusChip :tone="evolizChip[entry.evoliz_status].tone">{{ evolizChip[entry.evoliz_status].label }}</StatusChip>
                                <span v-if="entry.evoliz_status === 'sent' && entry.evoliz_before !== null" class="text-muted tabular-nums">
                                    {{ formatNumber(entry.evoliz_before) }} → {{ formatNumber(entry.evoliz_after) }}
                                </span>
                            </p>
                            <p v-if="fileMode && entry.evoliz_before !== null" class="mt-2 text-sm text-muted tabular-nums">
                                {{ t('Stock: :before → :after', { before: formatNumber(entry.evoliz_before), after: formatNumber(entry.evoliz_after) }) }}
                            </p>
                            <p v-if="entry.evoliz_error && entry.evoliz_status !== 'disabled'" class="mt-1 text-sm break-words" :class="entry.evoliz_status === 'failed' ? 'text-red-700' : 'text-muted'">{{ entry.evoliz_error }}</p>
                        </div>
                        <!-- Phones: the buttons go under the entry, so its text keeps the full width. -->
                        <div v-if="entry.can_retry || entry.can_delete" class="flex shrink-0 gap-2">
                            <UiButton v-if="entry.can_retry" variant="secondary" :disabled="retrying === entry.id" @click="retry(entry)">{{ t('Retry') }}</UiButton>
                            <UiButton v-if="entry.can_delete" variant="ghost" class="text-red-700 hover:bg-red-50" @click="deleting = entry">{{ t('Delete') }}</UiButton>
                        </div>
                    </li>
                    <li v-if="entries.data.length === 0" class="px-4 py-6 text-center text-muted">{{ t('Nothing recorded for these filters.') }}</li>
                </ul>
                <PaginationNav :page="entries" />
            </section>
        </div>

        <ConfirmDialog
            :open="deleting !== null"
            :title="t('Delete this entry?')"
            :message="deleting ? `${deleting.article.reference} × ${formatNumber(deleting.quantity)} · ${formatDate(deleting.happened_on)}` : ''"
            :confirm-label="t('Delete')"
            danger
            :busy="busy"
            @confirm="destroy"
            @cancel="deleting = null"
        />
    </AppLayout>
</template>
