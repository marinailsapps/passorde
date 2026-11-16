<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationNav from '@/components/PaginationNav.vue';
import SearchField from '@/components/SearchField.vue';
import StatusChip from '@/components/StatusChip.vue';
import SyncStatusBar from '@/components/SyncStatusBar.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useExcelExport } from '@/lib/excelExport';
import { formatMoney, formatNumber } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { Paginated, SyncState } from '@/types';

interface ArticleRow {
    id: number;
    reference: string;
    designation: string;
    unit_price_ht: string;
    vat_rate: string;
    is_stocked: boolean;
    stock_qty: string | null;
    reserved_qty: string;
    is_active: boolean;
    synced_at: string | null;
}

const props = defineProps<{
    articles: Paginated<ArticleRow>;
    filters: { q: string; inactive: boolean };
    /** Admins only (the sync bar). */
    sync: SyncState | null;
}>();

const filters = reactive({ ...props.filters });

function apply(): void {
    router.get(
        '/catalog',
        { q: filters.q.trim().length >= 2 ? filters.q : undefined, inactive: filters.inactive ? 1 : undefined },
        { preserveState: true, preserveScroll: true, replace: true, only: ['articles', 'filters'] },
    );
}

/* File mode: no sync brings the articles, so admins edit them here. */
const canEdit = computed(() => isFileMode() && usePage().props.auth.user?.role === 'admin');
const editing = ref<ArticleRow | null>(null);
const form = useForm({ designation: '', unit_price_ht: '', stock_qty: '', is_stocked: false, is_active: true });

function edit(article: ArticleRow): void {
    editing.value = article;
    form.clearErrors();
    form.designation = article.designation;
    form.unit_price_ht = article.unit_price_ht;
    form.stock_qty = article.stock_qty ?? '';
    form.is_stocked = article.is_stocked;
    form.is_active = article.is_active;
}

function saveArticle(): void {
    if (!editing.value) return;
    form.transform((data) => ({
        ...data,
        unit_price_ht: data.unit_price_ht.replace(',', '.'),
        stock_qty: data.stock_qty.trim() === '' ? null : data.stock_qty.replace(',', '.'),
    })).patch(`/admin/catalog/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

/* Excel: the clients' price list (active articles, prices, category, variant; no stock). */
const { exporting, exportError, pendingFile, exportExcel, openPending } = useExcelExport(
    () => '/catalog.xlsx',
    () => `tarifs-${new Date().toISOString().slice(0, 10)}.xlsx`,
);

function stockTone(article: ArticleRow): 'danger' | 'warning' | 'success' {
    const qty = Number(article.stock_qty ?? 0);
    return qty < 0 ? 'danger' : qty === 0 ? 'warning' : 'success';
}
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Catalogue')" back="/">
            <template #actions>
                <UiButton v-if="canEdit" variant="ghost" href="/admin/import" data-flow="catalog-import">{{ t('Import') }}</UiButton>
                <a
                    href="/catalog.xlsx"
                    class="inline-flex min-h-12 items-center rounded-lg px-3 font-medium text-accent hover:bg-accent-soft"
                    :class="{ 'opacity-60': exporting }"
                    :aria-busy="exporting"
                    data-flow="catalog-excel"
                    download
                    @click="exportExcel"
                >
                    {{ exporting ? t('Preparing…') : t('Excel') }}
                </a>
            </template>
        </PageHeader>
        <p v-if="exportError" class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ exportError }}</p>
        <button
            v-if="pendingFile"
            type="button"
            class="mb-3 flex min-h-12 w-full items-center justify-center rounded-lg bg-accent px-4 font-medium text-white"
            @click="openPending"
        >
            {{ t('Open the Excel file') }}
        </button>
        <SyncStatusBar v-if="sync" :sync="sync" action="/admin/catalog/sync" class="mb-4" />

        <div class="mb-3 space-y-1">
            <SearchField v-model="filters.q" data-flow="catalog-search" :label="t('Reference or designation')" @search="apply" />
            <CheckboxField v-model="filters.inactive" :label="t('Include inactive')" @update:model-value="apply" />
        </div>

        <p class="mb-2 text-sm text-muted">{{ tc(':count articles', articles.total) }}</p>

        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="article in articles.data" :key="article.id" class="px-4 py-3">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ article.reference }}</p>
                        <p class="text-sm break-words text-muted">{{ article.designation }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="font-medium tabular-nums">{{ formatMoney(article.unit_price_ht) }}</p>
                        <p class="text-xs text-muted">{{ t('VAT :rate %', { rate: formatNumber(article.vat_rate) }) }}</p>
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <StatusChip v-if="article.is_stocked" :tone="stockTone(article)">{{ t('Stock: :qty', { qty: formatNumber(article.stock_qty) }) }}</StatusChip>
                    <StatusChip v-else>{{ t('Not stocked') }}</StatusChip>
                    <StatusChip v-if="Number(article.reserved_qty) > 0" tone="info">{{ t('Reserved: :qty', { qty: formatNumber(article.reserved_qty) }) }}</StatusChip>
                    <StatusChip v-if="!article.is_active" tone="neutral">{{ t('Inactive') }}</StatusChip>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="ml-auto -my-1 inline-flex min-h-9 items-center rounded-lg px-2 text-sm font-medium text-accent hover:bg-accent-soft"
                        data-flow="catalog-edit"
                        @click="edit(article)"
                    >
                        {{ t('Edit') }}
                    </button>
                </div>
            </li>
            <li v-if="articles.data.length === 0" class="px-4 py-6 text-center text-muted">{{ t('No article found.') }}</li>
        </ul>
        <PaginationNav :page="articles" />

        <BottomSheet v-if="canEdit" :open="editing !== null" :title="editing?.reference ?? ''" @close="editing = null">
            <form class="space-y-3" novalidate data-flow="catalog-edit-form" @submit.prevent="saveArticle">
                <TextField v-model="form.designation" :label="t('Designation')" :error="form.errors.designation" required />
                <div class="grid grid-cols-2 gap-3">
                    <TextField v-model="form.unit_price_ht" :label="t('Unit price HT')" inputmode="decimal" :error="form.errors.unit_price_ht" required />
                    <TextField v-model="form.stock_qty" :label="t('Stock')" inputmode="decimal" :error="form.errors.stock_qty" />
                </div>
                <CheckboxField v-model="form.is_stocked" :label="t('Stock management')" :error="form.errors.is_stocked" />
                <CheckboxField v-model="form.is_active" :label="t('Active')" :error="form.errors.is_active" />
                <UiButton type="submit" block :disabled="form.processing || !form.isDirty">{{ t('Save') }}</UiButton>
            </form>
        </BottomSheet>
    </AppLayout>
</template>
