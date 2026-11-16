<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import SelectField from '@/components/SelectField.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import { ApiError, apiGet, apiPut } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { newArticleVariants } from '@/lib/catalog';
import { isFileMode } from '@/lib/syncMode';
import type { ArticleHit } from '@/types';

/*
 * The "new article" button (catalog.new_article): the article is created in Evoliz straight away, and the
 * sheet stays frozen until Evoliz has it, so an order only ever holds articles
 * Evoliz knows. The server queues the work; here we poll. In file mode the first
 * answer is already "created", so the loop never runs.
 */
const props = defineProps<{ open: boolean; initialName: string }>();
const emit = defineEmits<{ close: []; picked: [article: ArticleHit] }>();

interface RequestState {
    uuid: string;
    status: 'pending' | 'created' | 'failed';
    error: string | null;
    article: ArticleHit | null;
}

const POLL_MS = 1000;
const WAIT_MS = 30_000;
const MAX_SIMILAR = 3;

const step = ref<'form' | 'similar' | 'waiting'>('form');
const variants = newArticleVariants();
const defaultVariant = variants[0]?.value ?? '';
const fields = reactive({ name: '', category: '', variant: defaultVariant, price: '' });
const fieldErrors = reactive<Record<string, string>>({});
const error = ref<string | null>(null);
const similar = ref<ArticleHit[]>([]);
const checking = ref(false);
const categories = ref<{ value: string; label: string }[]>([]);
/** Kept across "Réessayer", so that the server finds the same request again. */
let uuid = '';

const variantOptions = variants.map((variant) => ({ value: variant.value, label: variant.name }));

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        uuid = crypto.randomUUID();
        step.value = 'form';
        error.value = null;
        similar.value = [];
        for (const key of Object.keys(fieldErrors)) delete fieldErrors[key];
        Object.assign(fields, { name: props.initialName.trim(), category: categories.value[0]?.value ?? '', variant: defaultVariant, price: '' });
        try {
            categories.value = (await apiGet<{ data: { value: string; label: string }[] }>('/api/categories')).data;
            // The first category, as the form opened, unless it was changed meanwhile.
            if (fields.category === '' && categories.value.length > 0) fields.category = categories.value[0].value;
        } catch {
            // Keeps the default: the create below will say what is wrong with the network.
        }
    },
);

const price = computed(() => Number(fields.price.replace(',', '.')));

function validate(): boolean {
    for (const key of Object.keys(fieldErrors)) delete fieldErrors[key];
    if (fields.name.trim().length < 2) fieldErrors.name = t('Required.');
    if (fields.price.trim() === '' || Number.isNaN(price.value) || price.value < 0) fieldErrors.unit_price_ht = t('Required.');
    return Object.keys(fieldErrors).length === 0;
}

/** First look for what may already be the same article: an article cannot be deleted through the API. */
async function submit(): Promise<void> {
    if (!validate()) return;
    checking.value = true;
    error.value = null;
    try {
        const found = (await apiGet<{ data: ArticleHit[] }>('/api/articles/search', { q: fields.name.trim() })).data.slice(0, MAX_SIMILAR);
        if (found.length > 0) {
            similar.value = found;
            step.value = 'similar';
            return;
        }
    } catch {
        // No check without an answer: the create below reports the network problem.
    } finally {
        checking.value = false;
    }
    await create();
}

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

async function create(): Promise<void> {
    step.value = 'waiting';
    error.value = null;
    try {
        const url = `/api/article-requests/${uuid}`;
        let { request } = await apiPut<{ request: RequestState }>(url, {
            name: fields.name.trim(),
            category: fields.category,
            variant: fields.variant,
            unit_price_ht: price.value,
        });

        const deadline = Date.now() + WAIT_MS;
        while (request.status === 'pending' && Date.now() < deadline) {
            await sleep(POLL_MS);
            ({ request } = await apiGet<{ request: RequestState }>(url));
        }

        if (request.status === 'created' && request.article) {
            emit('picked', request.article);
            return;
        }

        error.value = request.status === 'failed' ? (request.error ?? t('The article could not be created. Check the network and try again.')) : t('Evoliz is not answering yet. Try again: this will not create a second article.');
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            for (const [field, messages] of Object.entries(e.errors)) fieldErrors[field] = messages[0];
        } else {
            error.value = e instanceof ApiError ? e.messages.join(' ') : t('The article could not be created. Check the network and try again.');
        }
    }
    step.value = 'form';
}

/** Frozen while Evoliz works: the order must not move on without the article. */
function close(): void {
    if (step.value !== 'waiting') emit('close');
}
</script>

<template>
    <BottomSheet :open="open" :title="t('New article')" @close="close">
        <div v-if="step === 'waiting'" class="flex flex-col items-center gap-3 py-8 text-center" role="status" data-flow="article-new-waiting">
            <span class="size-10 animate-spin rounded-full border-4 border-line border-t-accent" aria-hidden="true" />
            <p class="font-medium">{{ isFileMode() ? t('Creating the article…') : t('Creating the article in Evoliz…') }}</p>
            <p class="text-sm text-muted">{{ t('Please wait, this takes a few seconds.') }}</p>
        </div>

        <div v-else-if="step === 'similar'" class="space-y-3" data-flow="article-new-similar">
            <p class="text-sm text-amber-900">{{ t('Similar articles already exist:') }}</p>
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line">
                <li v-for="article in similar" :key="article.id" class="flex items-center gap-3 px-3 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ article.reference }}</span>
                        <span class="block text-sm break-words text-muted">{{ article.designation }}</span>
                        <span class="block text-sm tabular-nums text-muted">{{ formatMoney(article.unit_price_ht) }}</span>
                    </span>
                    <UiButton variant="secondary" class="shrink-0" data-flow="article-new-use" @click="emit('picked', article)">{{ t('Use this one') }}</UiButton>
                </li>
            </ul>
            <UiButton block data-flow="article-new-anyway" @click="create">{{ t('Create it anyway') }}</UiButton>
        </div>

        <form v-else class="space-y-3" novalidate data-flow="article-new-form" @submit.prevent="submit">
            <p v-if="error" class="rounded-lg bg-red-50 px-4 py-3 text-sm break-words text-red-900" role="alert" data-flow="article-new-error">{{ error }}</p>
            <TextField v-model="fields.name" :label="t('Name of the article')" :error="fieldErrors.name" required />
            <div class="grid grid-cols-2 gap-3">
                <SelectField v-model="fields.category" :label="t('Category')" :options="categories" :error="fieldErrors.category" />
                <SelectField v-model="fields.variant" :label="t('Variant')" :options="variantOptions" :error="fieldErrors.variant" />
            </div>
            <TextField v-model="fields.price" :label="t('Unit price HT')" inputmode="decimal" :error="fieldErrors.unit_price_ht" required />
            <UiButton type="submit" block data-flow="article-new-submit" :disabled="checking">{{ error ? t('Try again') : t('Create the article') }}</UiButton>
        </form>
    </BottomSheet>
</template>
