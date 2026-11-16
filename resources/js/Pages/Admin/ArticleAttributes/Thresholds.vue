<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import PageHeader from '@/components/PageHeader.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t, tc } from '@/lib/i18n';

interface CategoryRow {
    category: string;
    group: string;
    threshold: number | null;
    articles: number;
    /** A category the profile no longer lists: its stored threshold can only be cleared. */
    orphan?: boolean;
}

const props = defineProps<{ categories: CategoryRow[] }>();

// Text inputs keep what is typed; empty means "no threshold for this category".
const form = useForm<{ thresholds: Record<string, string> }>({
    thresholds: Object.fromEntries(props.categories.map((row) => [row.category, row.threshold === null ? '' : String(row.threshold)])),
});

const groups = computed(() => {
    const byGroup = new Map<string, CategoryRow[]>();
    for (const row of props.categories) byGroup.set(row.group, [...(byGroup.get(row.group) ?? []), row]);
    return [...byGroup.entries()];
});

function submit(): void {
    form.transform((data) => ({
        thresholds: Object.fromEntries(Object.entries(data.thresholds).map(([category, value]) => [category, value.trim() === '' ? null : Number(value)])),
    })).put('/admin/category-thresholds', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="t('Thresholds per category')" back="/admin/article-attributes" />
        <p class="mb-4 text-sm text-muted">
            {{ t('Quantity to keep in stock per article. The stock report suggests ordering when the stock to come falls below it. A threshold set on an article (Article attributes) wins over its category’s.') }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <section v-for="[group, rows] in groups" :key="group" class="rounded-xl border border-line bg-surface p-4">
                <h2 class="mb-2 font-semibold">{{ group }}</h2>
                <div v-for="row in rows" :key="row.category" class="flex min-h-14 items-center gap-3 border-t border-line first:border-t-0">
                    <label :for="`threshold-${row.category}`" class="flex-1">
                        <span class="block font-medium">{{ row.category }}</span>
                        <span class="block text-xs text-muted">{{ tc(':count articles', row.articles) }}</span>
                    </label>
                    <template v-if="row.orphan">
                        <span class="tabular-nums" :class="{ 'line-through text-muted': form.thresholds[row.category] === '' }">{{ row.threshold }}</span>
                        <UiButton
                            variant="secondary"
                            :data-flow="`threshold-clear-${row.category}`"
                            @click="form.thresholds[row.category] = form.thresholds[row.category] === '' ? String(row.threshold) : ''"
                        >
                            {{ form.thresholds[row.category] === '' ? t('Keep') : t('Clear') }}
                        </UiButton>
                    </template>
                    <input
                        v-else
                        :id="`threshold-${row.category}`"
                        v-model="form.thresholds[row.category]"
                        type="text"
                        inputmode="numeric"
                        class="min-h-12 w-24 rounded-lg border border-field bg-surface px-3 text-right tabular-nums"
                        :placeholder="t('None')"
                    >
                </div>
                <p v-for="row in rows.filter((r) => form.errors[`thresholds.${r.category}` as keyof typeof form.errors])" :key="`e-${row.category}`" class="mt-1 text-sm text-red-700">
                    {{ row.category }} : {{ form.errors[`thresholds.${row.category}` as keyof typeof form.errors] }}
                </p>
            </section>
            <UiButton type="submit" block :disabled="form.processing">{{ t('Save the thresholds') }}</UiButton>
        </form>
    </AppLayout>
</template>
