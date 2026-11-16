<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationNav from '@/components/PaginationNav.vue';
import SearchField from '@/components/SearchField.vue';
import SelectField from '@/components/SelectField.vue';
import StatusChip from '@/components/StatusChip.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { catalogProp } from '@/lib/catalog';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    article_id: number;
    reference: string;
    designation: string;
    category: string | null;
    variant: string;
    reorder_threshold: number | null;
    needs_review: boolean;
    source: 'auto' | 'file' | 'manual';
}

const props = defineProps<{
    articles: Paginated<Row>;
    filters: { q: string; review: boolean; category: string; type: string };
    categories: { value: string; label: string; group: string }[];
    toReview: number;
}>();

const variants = catalogProp().variants;
const types = variants.map((variant) => ({ value: variant.value, label: variant.name }));
/** A variant that is not counted has no category (its own tab, or out of the stock report). */
const counted = (type: string): boolean => variants.find((variant) => variant.value === type)?.counts ?? false;
const sources = { auto: 'Automatic', file: 'From the file', manual: 'Set by hand' } as const;

const filters = reactive({ ...props.filters });
const categoryFilterOptions = computed(() => [
    { value: '', label: t('All categories') },
    { value: 'none', label: t('No category') },
    ...props.categories.map((category) => ({ value: category.value, label: category.label })),
]);
const typeFilterOptions = computed(() => [{ value: '', label: t('All types') }, ...types]);

function apply(): void {
    router.get(
        '/admin/article-attributes',
        {
            q: filters.q.trim().length >= 2 ? filters.q : undefined,
            review: filters.review ? 1 : undefined,
            category: filters.category || undefined,
            type: filters.type || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['articles', 'filters', 'toReview'] },
    );
}

/* Inline edit: every change is saved at once (and becomes "Set by hand"). */
const saving = ref<number | null>(null);

function save(row: Row, change: Partial<Pick<Row, 'category' | 'variant' | 'reorder_threshold'>>): void {
    const next = { category: row.category, variant: row.variant, reorder_threshold: row.reorder_threshold, ...change };
    saving.value = row.id;
    router.patch(`/admin/article-attributes/${row.id}`, next, {
        preserveScroll: true,
        preserveState: true,
        only: ['articles', 'toReview', 'flash'],
        onFinish: () => (saving.value = null),
    });
}

function thresholdInput(row: Row, value: string): void {
    const parsed = value.trim() === '' ? null : Math.max(0, Math.round(Number(value)));
    if (parsed !== row.reorder_threshold && (parsed === null || Number.isFinite(parsed))) save(row, { reorder_threshold: parsed });
}

/* Bulk: one category for the selected articles of counting variants. */
const selected = ref(new Set<number>());
const bulkCategory = ref('');

function toggle(row: Row): void {
    const next = new Set(selected.value);
    if (next.has(row.id)) next.delete(row.id);
    else next.add(row.id);
    selected.value = next;
}

function applyBulk(): void {
    if (!bulkCategory.value || selected.value.size === 0) return;
    router.post(
        '/admin/article-attributes/bulk-category',
        { ids: [...selected.value], category: bulkCategory.value },
        { preserveScroll: true, preserveState: true, onSuccess: () => (selected.value = new Set()) },
    );
}
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Article attributes')" back="/">
            <template #actions>
                <Link href="/admin/category-thresholds" class="inline-flex min-h-12 items-center rounded-lg px-3 font-medium text-accent hover:bg-accent-soft">{{ t('Thresholds per category') }}</Link>
            </template>
        </PageHeader>

        <p class="mb-3 text-sm text-muted">{{ isFileMode() ? t('Category, variant and reorder threshold of each article, for the stock report. They were guessed from the designation; what you change here is kept.') : t('Category, variant and reorder threshold of each article, for the stock report. Evoliz does not have them: they were guessed from the designation and the stock file; what you change here is kept.') }}</p>

        <div class="mb-3 grid gap-2 sm:grid-cols-[1fr_12rem_12rem]">
            <SearchField v-model="filters.q" :label="t('Reference or designation')" @search="apply" />
            <SelectField v-model="filters.category" :label="t('Category')" :options="categoryFilterOptions" @update:model-value="apply" />
            <SelectField v-model="filters.type" :label="t('Type')" :options="typeFilterOptions" @update:model-value="apply" />
        </div>
        <CheckboxField v-model="filters.review" :label="t('To check only (:count)', { count: toReview })" @update:model-value="apply" />

        <div v-if="selected.size" class="sticky top-[var(--header-height,3.5rem)] z-[5] my-3 flex flex-wrap items-end gap-2 rounded-xl border border-accent/40 bg-accent-soft p-3">
            <p class="w-full text-sm font-medium">{{ tc(':count selected', selected.size) }}</p>
            <select v-model="bulkCategory" class="min-h-12 flex-1 rounded-lg border border-field bg-surface px-3" :aria-label="t('Category')">
                <option value="">{{ t('Category…') }}</option>
                <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
            </select>
            <UiButton :disabled="!bulkCategory" @click="applyBulk">{{ t('Set this category') }}</UiButton>
        </div>

        <p class="my-2 text-sm text-muted">{{ tc(':count articles', articles.total) }}</p>

        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="row in articles.data" :key="row.id" class="px-4 py-3" :class="{ 'opacity-60': saving === row.id }">
                <div class="flex items-start gap-3">
                    <label class="flex size-12 shrink-0 cursor-pointer items-center justify-center" :aria-label="t('Select :reference', { reference: row.reference })">
                        <input type="checkbox" class="size-5 accent-accent" :checked="selected.has(row.id)" @change="toggle(row)">
                    </label>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            {{ row.reference }}
                            <StatusChip v-if="row.needs_review" tone="warning">{{ t('To check') }}</StatusChip>
                            <span class="text-xs font-normal text-muted">{{ t(sources[row.source]) }}</span>
                        </p>
                        <p class="text-sm break-words text-muted">{{ row.designation }}</p>
                    </div>
                </div>
                <div class="mt-2 grid gap-2 sm:ml-15 sm:grid-cols-[1fr_12rem_8rem]">
                    <select
                        class="min-h-12 rounded-lg border border-field bg-surface px-3"
                        :aria-label="t('Category of :reference', { reference: row.reference })"
                        :value="row.category ?? ''"
                        :disabled="!counted(row.variant)"
                        @change="save(row, { category: ($event.target as HTMLSelectElement).value || null })"
                    >
                        <option value="">{{ t('No category') }}</option>
                        <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }} · {{ category.group }}</option>
                    </select>
                    <select
                        class="min-h-12 rounded-lg border border-field bg-surface px-3"
                        :aria-label="t('Type of :reference', { reference: row.reference })"
                        :value="row.variant"
                        @change="save(row, { variant: ($event.target as HTMLSelectElement).value })"
                    >
                        <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                    <input
                        type="text"
                        inputmode="numeric"
                        class="min-h-12 rounded-lg border border-field bg-surface px-3 text-right tabular-nums"
                        :aria-label="t('Threshold of :reference', { reference: row.reference })"
                        :placeholder="t('Category threshold')"
                        :value="row.reorder_threshold ?? ''"
                        @change="thresholdInput(row, ($event.target as HTMLInputElement).value)"
                    >
                </div>
            </li>
            <li v-if="articles.data.length === 0" class="px-4 py-6 text-center text-muted">{{ t('No article found.') }}</li>
        </ul>
        <PaginationNav :page="articles" />
    </AppLayout>
</template>
