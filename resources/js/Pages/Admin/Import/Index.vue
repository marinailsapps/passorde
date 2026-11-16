<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import PageHeader from '@/components/PageHeader.vue';
import StatusChip from '@/components/StatusChip.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ApiError, apiPost } from '@/lib/api';
import { t } from '@/lib/i18n';

type Kind = 'articles' | 'clients';
type Action = 'created' | 'updated' | 'unchanged' | 'error';

interface ReportLine {
    line: number;
    key: string;
    action: Action;
    fields: string[];
    messages: string[];
}

interface Report {
    created: number;
    updated: number;
    unchanged: number;
    errors: number;
    warnings: number;
    file_warnings: string[];
    lines: ReportLine[];
}

const props = defineProps<{ columns: Record<Kind, string[]>; maxMegabytes: number }>();

const kinds: { value: Kind; label: string }[] = [
    { value: 'articles', label: t('Articles') },
    { value: 'clients', label: t('Clients') },
];

const kind = ref<Kind>('articles');
/* Stays in the browser: sent once to preview, once more to apply. */
const file = ref<File | null>(null);
const input = ref<HTMLInputElement | null>(null);
const preview = ref<Report | null>(null);
const applied = ref<Report | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);

const report = computed(() => applied.value ?? preview.value);
const counts = computed(() =>
    report.value === null
        ? []
        : [
              { label: t('import.created'), value: report.value.created },
              { label: t('import.updated'), value: report.value.updated },
              { label: t('import.unchanged'), value: report.value.unchanged },
              { label: t('Warnings'), value: report.value.warnings },
              { label: t('Errors'), value: report.value.errors },
          ],
);
const canApply = computed(() => preview.value !== null && applied.value === null && preview.value.created + preview.value.updated > 0);

function clear(): void {
    preview.value = null;
    applied.value = null;
    error.value = null;
}

watch(kind, clear);

function chosen(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    clear();
}

async function send(dryRun: boolean): Promise<void> {
    if (!file.value) return;
    const form = new FormData();
    form.append('file', file.value);
    form.append('dry_run', dryRun ? '1' : '0');
    busy.value = true;
    error.value = null;
    try {
        const answer = await apiPost<Report>(`/admin/import/${kind.value}`, form);
        if (dryRun) {
            preview.value = answer;
        } else {
            applied.value = answer;
            // Applied: the file is done with. Picking it again gives a fresh preview.
            file.value = null;
            if (input.value) input.value.value = '';
        }
    } catch (e) {
        preview.value = null;
        error.value = e instanceof ApiError ? e.messages.join(' ') : t('The file could not be sent. Check the network and try again.');
    } finally {
        busy.value = false;
    }
}

const tone: Record<Action, 'success' | 'info' | 'neutral' | 'danger'> = { created: 'success', updated: 'info', unchanged: 'neutral', error: 'danger' };
const actionLabel: Record<Action, string> = { created: t('import.line_created'), updated: t('import.line_updated'), unchanged: t('import.line_unchanged'), error: t('import.rejected') };
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Import')" back="/" />
        <p class="mb-4 text-sm text-muted">
            {{ t('Fills the catalogue or the clients from a CSV file. An empty cell never erases a value, and rows absent from the file are left alone. Nothing changes until you apply the preview.') }}
        </p>

        <div class="lg:grid lg:grid-cols-[22rem_1fr] lg:gap-6">
            <form class="mb-6 space-y-3 rounded-xl border border-line bg-surface p-4 lg:self-start" @submit.prevent="send(true)">
                <fieldset class="grid grid-cols-2 gap-2">
                    <legend class="sr-only">{{ t('Kind') }}</legend>
                    <label
                        v-for="option in kinds"
                        :key="option.value"
                        class="flex min-h-12 cursor-pointer items-center justify-center rounded-lg border px-2 text-center text-sm font-medium"
                        :class="kind === option.value ? 'border-accent bg-accent text-white' : 'border-field bg-surface'"
                    >
                        <input v-model="kind" type="radio" class="sr-only" :value="option.value">
                        {{ option.label }}
                    </label>
                </fieldset>

                <div>
                    <label for="import-file" class="mb-1 block text-sm font-medium">{{ t('CSV file (:size MB at most)', { size: maxMegabytes }) }}</label>
                    <input
                        id="import-file"
                        ref="input"
                        type="file"
                        accept=".csv,text/csv"
                        class="block w-full min-w-0 text-sm"
                        data-flow="import-file"
                        @change="chosen"
                    >
                </div>

                <UiButton type="submit" block :disabled="busy || !file" data-flow="import-preview">{{ busy ? t('Working…') : t('Preview') }}</UiButton>

                <a :href="`/admin/import/templates/${kind}.csv`" class="flex min-h-12 items-center justify-center rounded-lg px-3 font-medium text-accent hover:bg-accent-soft" download>
                    {{ t('Download the template') }}
                </a>

                <details class="text-sm">
                    <summary class="flex min-h-11 cursor-pointer items-center text-muted">{{ t('Columns of the file') }}</summary>
                    <p class="break-words">{{ props.columns[kind].join(' ; ') }}</p>
                    <p v-if="kind === 'articles'" class="mt-2 text-muted">{{ t('Evoliz exports are read as they are. Articles: PU HT is prix_ht, Qté stockée is stock, Article stocké is gestion_stock, Code Classification vente is classification, Désactivé is the opposite of actif. Nature and the other columns are ignored and listed in the report.') }}</p>
                    <p v-else class="mt-2 text-muted">{{ t("Evoliz exports are read as they are. Clients: Société / Nom is societe, Adresse and Complément d'adresse are adresse, Portable is mobile, Désactivé is the opposite of actif. The other columns, banking details and comments included, are ignored and listed in the report.") }}</p>
                    <p v-if="kind === 'articles'" class="mt-2 text-muted">{{ t('Retype the exported orders first, then export the stock: a file older than the last retyping overwrites the stock with a figure that misses them.') }}</p>
                </details>
            </form>

            <section aria-live="polite">
                <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-4 py-3 text-sm break-words text-red-900" role="alert" data-flow="import-error">{{ error }}</p>

                <template v-if="report">
                    <p v-if="applied" class="mb-3 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status" data-flow="import-applied">{{ t('Import applied. Phones catch up at their next refresh.') }}</p>
                    <h2 class="mb-2 font-semibold">{{ applied ? t('Result') : t('Preview: nothing has changed yet') }}</h2>

                    <dl class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-5" data-flow="import-counts">
                        <div v-for="count in counts" :key="count.label" class="rounded-xl border border-line bg-surface p-3 text-center">
                            <dd class="text-xl font-semibold tabular-nums">{{ count.value }}</dd>
                            <dt class="text-xs text-muted">{{ count.label }}</dt>
                        </div>
                    </dl>

                    <ul v-if="report.file_warnings.length" class="mb-3 space-y-1 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <li v-for="warning in report.file_warnings" :key="warning" class="break-words">{{ warning }}</li>
                    </ul>

                    <UiButton v-if="canApply" block class="mb-3" :disabled="busy" data-flow="import-apply" @click="send(false)">{{ t('Apply this import') }}</UiButton>
                    <p v-else-if="!applied" class="mb-3 text-sm text-muted">{{ t('Nothing to apply.') }}</p>

                    <ul v-if="report.lines.length" class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
                        <li v-for="line in report.lines" :key="line.line" class="px-4 py-3 text-sm">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="text-muted tabular-nums">{{ t('Line :line', { line: line.line }) }}</span>
                                <span class="min-w-0 font-medium break-words">{{ line.key }}</span>
                                <StatusChip :tone="tone[line.action]">{{ actionLabel[line.action] }}</StatusChip>
                            </p>
                            <p v-if="line.fields.length" class="mt-1 break-words text-muted">{{ line.fields.join(', ') }}</p>
                            <p v-for="message in line.messages" :key="message" class="mt-1 break-words" :class="line.action === 'error' ? 'text-red-800' : 'text-amber-900'">{{ message }}</p>
                        </li>
                    </ul>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
