<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import SearchField from '@/components/SearchField.vue';
import UiButton from '@/components/UiButton.vue';
import ClientStep from '@/components/orders/ClientStep.vue';
import LineCard from '@/components/orders/LineCard.vue';
import NewArticleSheet from '@/components/orders/NewArticleSheet.vue';
import TotalsBlock from '@/components/orders/TotalsBlock.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { catalogProp } from '@/lib/catalog';
import { corrected, correctionOf, hasCorrection } from '@/lib/clientCorrection';
import { formatAge, formatDate, formatMoney, formatNumber } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import { createLineEditor, groupsFromLines, type LineGroup } from '@/lib/orderLines';
import { fromCents } from '@/lib/orderTotals';
import { readDraft } from '@/offline/legacy';
import { localOrderFromDraft, selectionOf, type OrderBody } from '@/offline/orders';
import { confirmOrder, isOffline, localOrder, offline, recentOrders as loadRecentOrders, refreshMirror, registerLeaveGuard, searchArticles as findArticles } from '@/offline/runtime';
import type { ArticleHit, ClientData, ClientSelection, OrderDetail, Paytype, RecentOrder } from '@/types';

const props = defineProps<{
    order: OrderDetail | null;
    template: OrderDetail | null;
    paytypes: Paytype[];
}>();

type Step = 1 | 2 | 3;

interface Draft {
    uuid: string;
    step: Step;
    selection: ClientSelection | null;
    groups: LineGroup[];
    paytypeId: number | null;
    comment: string;
    duplicatedFrom: string | null;
}

// A refresh keeps the draft (sessionStorage). "Confirmer" hands the order to the outbox (resources/js/offline).
const storageKey = props.order ? `order-draft:edit:${props.order.uuid}` : props.template ? `order-draft:dup:${props.template.uuid}` : 'order-draft:new';

/** An edited order keeps the client correction still waiting on it; a duplicate starts without. */
function initialSelection(): ClientSelection | null {
    const source = props.order ?? props.template;
    if (!source) return null;
    const selection = selectionOf(source.client);
    const changes = props.order ? correctionOf(props.order.client_change) : undefined;
    return selection.kind === 'existing' && changes ? { ...selection, changes } : selection;
}

function initialDraft(): Draft {
    const source = props.order ?? props.template;
    const paytypeId = source?.paytype && props.paytypes.some((p) => p.id === source.paytype?.id) ? source.paytype.id : null;

    return {
        uuid: props.order?.uuid ?? crypto.randomUUID(),
        // Edits and duplicates already have a client: start on the lines.
        step: source ? 2 : 1,
        // A client typed on the phone (id 0) is still a new client.
        selection: initialSelection(),
        groups: source ? groupsFromLines(source.lines) : [],
        paytypeId,
        comment: source?.comment ?? '',
        duplicatedFrom: props.template?.uuid ?? null,
    };
}

function restoreDraft(): Draft | null {
    try {
        const stored = sessionStorage.getItem(storageKey);
        return stored ? readDraft(JSON.parse(stored) as Draft) : null;
    } catch {
        return null;
    }
}

const pristine = JSON.stringify(initialDraft());
const draft = reactive<Draft>(restoreDraft() ?? JSON.parse(pristine));
const editor = createLineEditor(draft.groups);
draft.groups = editor.state.groups;
watch(
    () => editor.state.groups,
    (groups) => (draft.groups = groups),
);

/** After "Confirmer": the number once the server has the order, empty while it waits for the network. */
const saved = ref<{ number: string; possibleDuplicate: ClientData | null } | null>(null);
/** The operator editing a seller's order goes back to that order, not to their own list. */
const correctingForSeller = computed(() => props.order !== null && props.order.author.id !== usePage().props.auth.user?.id);
const saving = ref(false);
const errors = ref<string[]>([]);

watch(
    draft,
    (value) => {
        if (saved.value) return;
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(value));
        } catch {
            // Private mode or quota: the draft just won't survive a refresh.
        }
    },
    { deep: true },
);

// Quantities of this order already counted in reserved_qty while editing.
const ownReserved = computed(() => {
    const map = new Map<number, number>();
    for (const line of props.order?.lines ?? []) {
        if (line.article_id !== null) map.set(line.article_id, (map.get(line.article_id) ?? 0) + Number(line.quantity));
    }
    return map;
});

const totals = editor.totals;
/** The client as the order will show it, with the correction made in step 1. */
const shownClient = computed(() =>
    draft.selection?.kind === 'existing' ? corrected(draft.selection.client, draft.selection.changes) : (draft.selection?.client ?? null),
);
const title = computed(() => (props.order ? t('Edit :number', { number: props.order.number || t('Local draft') }) : t('New order')));
const canLeaveStep1 = computed(() => draft.selection !== null);
const canLeaveStep2 = computed(() => editor.state.groups.length > 0 && editor.state.groups.every((g) => g.quantity > 0));

const clientStep = ref<InstanceType<typeof ClientStep> | null>(null);

async function goTo(step: Step): Promise<void> {
    if (step >= 2 && !canLeaveStep1.value) return;
    if (draft.step === 1 && step > 1 && clientStep.value && !(await clientStep.value.keepOpenForm())) return;
    if (step === 3 && !canLeaveStep2.value) return;
    draft.step = step;
    errors.value = [];
    window.scrollTo({ top: 0 });
}

/* Step 2: articles and previous orders */

const articleQuery = ref('');
const articleResults = ref<ArticleHit[]>([]);
const articleSearched = ref('');
const recentOrders = ref<RecentOrder[]>([]);
const copyOpen = ref(false);

async function searchArticles(value: string): Promise<void> {
    articleResults.value = value.trim().length < 2 ? [] : await findArticles(value);
    articleSearched.value = value;
}

const newArticleOpen = ref(false);
/** The profile's "new article" button (catalog.new_article). */
const hasNewArticle = catalogProp().new_article;
const offlineNow = computed(() => isOffline());

function addArticle(article: ArticleHit): void {
    newArticleOpen.value = false;
    editor.addArticle(article, 1, undefined, true);
    articleQuery.value = '';
    articleResults.value = [];
}

/** From the "new article" sheet: the phone's catalogue picks it up right away too. */
function addNewArticle(article: ArticleHit): void {
    addArticle(article);
    void refreshMirror();
}

watch(
    () => [draft.step, draft.selection?.kind === 'existing' ? draft.selection.client.id : null] as const,
    async ([step, clientId]) => {
        if (step !== 2) return;
        const recent = clientId === null || clientId === 0 ? [] : await loadRecentOrders(clientId);
        // Never offer the order being edited as its own source.
        recentOrders.value = recent.filter((order) => order.uuid !== draft.uuid);
    },
    { immediate: true },
);

function copyFrom(order: RecentOrder): void {
    editor.appendLines(order.lines.filter((line) => line.available));
    copyOpen.value = false;
}

/* Step 3: save */

function payload(): OrderBody {
    const selection = draft.selection!;
    return {
        ...(selection.kind === 'existing' ? { client_id: selection.client.id } : { client: selection.client }),
        ...(selection.kind === 'existing' && hasCorrection(selection.changes) ? { client_changes: selection.changes } : {}),
        lines: editor.toApiLines(),
        paytype_id: draft.paytypeId,
        comment: draft.comment,
        duplicated_from: draft.duplicatedFrom,
    };
}

/** Reads back the phone's copy: the number appears once the outbox has sent it. */
async function refreshSaved(): Promise<void> {
    const stored = await localOrder(draft.uuid);
    if (!stored || !saved.value) return;
    const ignored = draft.selection?.kind === 'new' ? draft.selection.ignoredDuplicateId : undefined;
    const duplicate = stored.possible_duplicate ?? null;
    saved.value = { number: stored.number, possibleDuplicate: duplicate && duplicate.id !== ignored ? duplicate : null };
}

watch(() => offline.revision, refreshSaved);

/**
 * "Confirmer": the order is kept on the phone and queued, then sent at once.
 * Without network it leaves on its own later. A refusal (422 …) is shown here
 * so it can be fixed straight away.
 */
async function confirm(): Promise<void> {
    const user = usePage().props.auth.user;
    if (!user) return;

    saving.value = true;
    errors.value = [];
    try {
        const body = payload();
        const order = localOrderFromDraft({
            uuid: draft.uuid,
            previous: props.order,
            selection: draft.selection!,
            groups: editor.state.groups,
            lines: body.lines,
            paytype: props.paytypes.find((paytype) => paytype.id === draft.paytypeId) ?? null,
            comment: draft.comment,
            author: user,
        });
        // The draft goes as soon as the order is safe on the phone, before any network wait.
        await confirmOrder(order, body, () => sessionStorage.removeItem(storageKey));

        const stored = await localOrder(draft.uuid);
        if (stored?.local_state === 'unsent') {
            errors.value = [stored.local_error ?? t('The order was refused.')];
            return;
        }

        // An order the operator corrected for a seller leaves the phone once sent.
        saved.value = { number: stored?.number || props.order?.number || '', possibleDuplicate: null };
        await refreshSaved();
        sessionStorage.removeItem(storageKey);
    } catch {
        errors.value = [t('The order could not be saved on this phone. Try again.')];
    } finally {
        saving.value = false;
    }
}

/** After saving with a new client, switch the order to the suggested existing one. */
async function useExistingClient(client: ClientData): Promise<void> {
    draft.selection = { kind: 'existing', client };
    await confirm();
}

/* Leaving mid-way */

const dirty = computed(() => !saved.value && JSON.stringify(draft) !== pristine);
let removeGuard: (() => void) | undefined;

onMounted(async () => {
    // A restored draft that was already confirmed (the page was left while it was being sent)
    // must not be confirmed again as if it were new: start afresh.
    if (!props.order && (await localOrder(draft.uuid))) {
        const fresh = JSON.parse(pristine) as Draft;
        Object.assign(draft, fresh);
        editor.state.groups = fresh.groups;
        sessionStorage.removeItem(storageKey);
    }

    removeGuard = registerLeaveGuard(() => {
        if (!dirty.value) return true;
        if (!window.confirm(t('Leave this order? What you entered will be lost.'))) return false;
        sessionStorage.removeItem(storageKey);
        return true;
    });
});
onBeforeUnmount(() => removeGuard?.());

function startNew(): void {
    sessionStorage.removeItem('order-draft:new');
    router.visit('/orders/new');
}

const steps: { step: Step; label: string }[] = [
    { step: 1, label: 'Client' },
    { step: 2, label: 'Lines' },
    { step: 3, label: 'Summary' },
];
</script>

<template>
    <Head :title="title" />
    <AppLayout wide>
        <!-- Success -->
        <NewArticleSheet :open="newArticleOpen" :initial-name="articleQuery" @close="newArticleOpen = false" @picked="addNewArticle" />

        <div v-if="saved" class="mx-auto max-w-md py-8 text-center" data-flow="order-saved" :data-number="saved.number">
            <template v-if="saved.number">
                <p class="text-sm text-muted">{{ order ? t('Order updated') : t('Order saved') }}</p>
                <p class="my-2 text-3xl font-semibold tracking-tight">{{ saved.number }}</p>
                <p v-if="!correctingForSeller" class="mb-6 text-muted">{{ t('It is waiting for approval by the office.') }}</p>
            </template>
            <template v-else>
                <p class="text-sm text-muted">{{ t('Saved on this phone') }}</p>
                <p class="my-2 text-2xl font-semibold tracking-tight">{{ t('Waiting for network') }}</p>
                <p class="mb-6 text-muted">{{ t('It will be sent automatically as soon as the network is back. Nothing else to do.') }}</p>
            </template>

            <div v-if="saved.possibleDuplicate" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-3 text-left">
                <p class="text-sm text-amber-900">{{ t('A similar client already exists:') }}</p>
                <p class="font-medium">{{ saved.possibleDuplicate.company_name }}</p>
                <p class="text-sm text-muted">{{ [saved.possibleDuplicate.postcode, saved.possibleDuplicate.town].filter(Boolean).join(' ') }}</p>
                <UiButton class="mt-2" block :disabled="saving" @click="useExistingClient(saved.possibleDuplicate)">{{ t('Use this client for the order') }}</UiButton>
            </div>

            <div v-if="correctingForSeller" class="mt-6 grid gap-3" data-flow="order-saved-operator">
                <UiButton block :href="`/orders/${order?.uuid}`">{{ t('Back to the order') }}</UiButton>
                <UiButton variant="secondary" block href="/admin/orders">{{ t('All orders') }}</UiButton>
            </div>
            <div v-else class="grid gap-3">
                <UiButton block @click="startNew">{{ t('New order') }}</UiButton>
                <UiButton variant="secondary" block href="/orders">{{ t('See my orders') }}</UiButton>
            </div>
        </div>

        <template v-else>
            <div class="mb-4 flex items-center gap-2">
                <h1 class="flex-1 text-xl font-semibold">{{ title }}</h1>
            </div>

            <ol class="mb-4 grid grid-cols-3 gap-1 text-sm" :aria-label="t('Steps')">
                <li v-for="item in steps" :key="item.step">
                    <button
                        type="button"
                        class="flex min-h-12 w-full flex-col items-center justify-center rounded-lg border-b-4 px-1"
                        :class="draft.step === item.step ? 'border-accent font-semibold text-ink' : item.step < draft.step ? 'border-accent/40 text-muted' : 'border-line text-muted'"
                        :aria-current="draft.step === item.step ? 'step' : undefined"
                        @click="goTo(item.step)"
                    >
                        <span class="text-center leading-tight break-words">{{ item.step }}. {{ t(item.label) }}</span>
                    </button>
                </li>
            </ol>

            <div class="pb-28 lg:grid lg:grid-cols-[1fr_20rem] lg:gap-6 lg:pb-0">
                <div class="min-w-0">
                    <!-- Step 1 -->
                    <ClientStep v-if="draft.step === 1" ref="clientStep" v-model="draft.selection" />

                    <!-- Step 2 -->
                    <section v-else-if="draft.step === 2" class="space-y-3">
                        <UiButton v-if="recentOrders.length" variant="secondary" block data-flow="copy-previous" @click="copyOpen = true">
                            {{ t('Copy a previous order') }}
                        </UiButton>

                        <div>
                            <SearchField v-model="articleQuery" data-flow="article-search" :label="t('Article: reference or designation')" :delay="250" @search="searchArticles" />
                            <ul v-if="articleResults.length" class="mt-1 divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface shadow-sm">
                                <li v-for="article in articleResults" :key="article.id">
                                    <button type="button" class="flex min-h-14 w-full items-start gap-3 px-4 py-2 text-left hover:bg-page" data-flow="article-result" @click="addArticle(article)">
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium">{{ article.reference }}</span>
                                            <span class="block text-sm text-muted">{{ article.designation }}</span>
                                        </span>
                                        <span class="shrink-0 text-right text-sm">
                                            <span class="block tabular-nums">{{ formatMoney(article.unit_price_ht) }}</span>
                                            <span v-if="article.is_stocked" class="block text-muted">{{ t('Stock: :qty', { qty: formatNumber(article.stock_qty) }) }}</span>
                                            <span v-if="article.is_stocked && article.stock_as_of" class="block text-xs text-muted">{{ formatAge(article.stock_as_of) }}</span>
                                        </span>
                                    </button>
                                </li>
                            </ul>
                            <p v-else-if="articleQuery.trim().length >= 2 && articleSearched === articleQuery" class="mt-1 text-sm text-muted">{{ t('No article found.') }}</p>
                            <div v-if="hasNewArticle && articleQuery.trim().length >= 2 && articleSearched === articleQuery" class="mt-2">
                                <UiButton variant="secondary" data-flow="article-new" :disabled="offlineNow" @click="newArticleOpen = true">{{ t('+ New article') }}</UiButton>
                                <p v-if="offlineNow" class="mt-1 text-sm text-muted">{{ t('Without network, a new article cannot be created.') }}</p>
                            </div>
                        </div>

                        <ul class="space-y-3">
                            <LineCard
                                v-for="(group, index) in editor.state.groups"
                                :key="group.key"
                                :group="group"
                                :editor="editor"
                                :first="index === 0"
                                :last="index === editor.state.groups.length - 1"
                                :own-reserved="ownReserved.get(group.article.id) ?? 0"
                            />
                        </ul>
                        <p v-if="editor.state.groups.length === 0" class="rounded-xl border border-dashed border-line p-6 text-center text-muted">
                            {{ t('No line yet. Search an article above.') }}
                        </p>
                    </section>

                    <!-- Step 3 -->
                    <section v-else class="space-y-4">
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">{{ shownClient?.company_name }}</p>
                                    <p class="text-sm text-muted">{{ [shownClient?.postcode, shownClient?.town].filter(Boolean).join(' ') }}</p>
                                    <p v-if="draft.selection?.kind === 'existing' && hasCorrection(draft.selection.changes)" class="text-sm text-amber-800">
                                        {{ isFileMode() ? t('Client details changed: applied on approval.') : t('Client details changed: sent to Evoliz on approval.') }}
                                    </p>
                                </div>
                                <button type="button" class="min-h-12 px-2 text-sm text-accent" @click="goTo(1)">{{ t('Change') }}</button>
                            </div>
                        </div>

                        <div class="rounded-xl border border-line bg-surface p-4">
                            <div class="mb-2 flex items-center">
                                <h2 class="flex-1 font-semibold">{{ t('Lines') }}</h2>
                                <button type="button" class="min-h-12 px-2 text-sm text-accent" @click="goTo(2)">{{ t('Change') }}</button>
                            </div>
                            <ul class="divide-y divide-line text-sm">
                                <template v-for="group in editor.state.groups" :key="group.key">
                                    <li class="flex gap-2 py-2">
                                        <span class="w-10 shrink-0 text-right tabular-nums">{{ formatNumber(group.quantity) }} ×</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">{{ group.article.reference }}</span>
                                            <span v-if="group.note?.trim()" class="block break-words whitespace-pre-line text-muted" data-flow="summary-note">{{ group.note.trim() }}</span>
                                        </span>
                                        <span class="tabular-nums">{{ formatMoney(Number(group.unitPrice) * group.quantity) }}</span>
                                    </li>
                                    <li v-if="group.freeQuantity > 0" class="flex gap-2 py-2 text-muted">
                                        <span class="w-10 shrink-0 text-right tabular-nums">{{ formatNumber(group.freeQuantity) }} ×</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">↳ {{ t('Free lines') }}</span>
                                            <span v-if="group.freeNote?.trim()" class="block break-words whitespace-pre-line" data-flow="summary-note">{{ group.freeNote.trim() }}</span>
                                        </span>
                                        <span class="tabular-nums">{{ formatMoney(0) }}</span>
                                    </li>
                                </template>
                            </ul>
                            <TotalsBlock class="mt-3 border-t border-line pt-3 lg:hidden" :totals="totals" />
                        </div>

                        <fieldset class="rounded-xl border border-line bg-surface p-4">
                            <legend class="sr-only">{{ t('Payment method') }}</legend>
                            <p class="font-semibold" aria-hidden="true">{{ t('Payment method') }}</p>
                            <!-- Usually unknown when the order is taken: "Non précisé" comes first and is the default (no paytype on the BL). -->
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label
                                    data-flow="paytype"
                                    class="flex min-h-12 cursor-pointer items-center rounded-full border px-4 has-focus-visible:outline-2 has-focus-visible:outline-accent"
                                    :class="draft.paytypeId === null ? 'border-accent bg-accent text-white' : 'border-line bg-surface'"
                                >
                                    <input v-model="draft.paytypeId" type="radio" name="paytype" :value="null" class="sr-only">
                                    {{ t('Not specified') }}
                                </label>
                                <label
                                    v-for="paytype in paytypes"
                                    :key="paytype.id"
                                    data-flow="paytype"
                                    class="flex min-h-12 cursor-pointer items-center rounded-full border px-4 has-focus-visible:outline-2 has-focus-visible:outline-accent"
                                    :class="draft.paytypeId === paytype.id ? 'border-accent bg-accent text-white' : 'border-line bg-surface'"
                                >
                                    <input v-model="draft.paytypeId" type="radio" name="paytype" :value="paytype.id" class="sr-only">
                                    {{ paytype.label }}
                                </label>
                            </div>
                        </fieldset>

                        <label class="block rounded-xl border border-line bg-surface p-4">
                            <span class="mb-1 block font-semibold">{{ t('Comment') }}</span>
                            <textarea v-model="draft.comment" rows="3" maxlength="2000" class="block w-full rounded-lg border border-field p-3 text-base focus:outline-2 focus:outline-accent/30" />
                        </label>

                        <ul v-if="errors.length" class="rounded-lg bg-red-50 px-4 py-3 text-red-900" role="alert">
                            <li v-for="message in errors" :key="message">{{ message }}</li>
                        </ul>

                        <UiButton block data-flow="wizard-confirm" :disabled="saving" @click="confirm">
                            {{ saving ? t('Saving…') : t('Confirm') }}
                        </UiButton>
                    </section>
                </div>

                <!-- Desktop: running summary and step buttons -->
                <aside class="hidden lg:block">
                    <div class="sticky top-20 space-y-4 rounded-xl border border-line bg-surface p-4">
                        <div v-if="draft.selection">
                            <p class="text-xs tracking-wide text-muted uppercase">{{ t('Client') }}</p>
                            <p class="font-medium">{{ shownClient?.company_name }}</p>
                            <p class="text-sm text-muted">{{ shownClient?.town }}</p>
                        </div>
                        <TotalsBlock :totals="totals" />
                        <div class="grid gap-2">
                            <UiButton v-if="draft.step < 3" data-flow="wizard-continue" :disabled="draft.step === 1 ? !canLeaveStep1 : !canLeaveStep2" @click="goTo((draft.step + 1) as Step)">
                                {{ t('Continue') }}
                            </UiButton>
                            <UiButton v-if="draft.step > 1" variant="secondary" @click="goTo((draft.step - 1) as Step)">{{ t('Back') }}</UiButton>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- Phone: bottom bar -->
            <div class="fixed inset-x-0 bottom-0 z-10 border-t border-line bg-surface/95 px-4 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
                <div class="mx-auto flex max-w-3xl items-center gap-2">
                    <UiButton v-if="draft.step > 1" variant="secondary" @click="goTo((draft.step - 1) as Step)">{{ t('Back') }}</UiButton>
                    <div class="min-w-0 flex-1 text-right text-sm">
                        <p class="text-muted">{{ t(':count :units', { count: formatNumber(totals.quantity) }) }}</p>
                        <p class="font-semibold tabular-nums">{{ formatMoney(fromCents(totals.ttcCents)) }} {{ t('TTC') }}</p>
                    </div>
                    <UiButton v-if="draft.step < 3" data-flow="wizard-continue" :disabled="draft.step === 1 ? !canLeaveStep1 : !canLeaveStep2" @click="goTo((draft.step + 1) as Step)">
                        {{ t('Continue') }}
                    </UiButton>
                </div>
            </div>
        </template>

        <BottomSheet :open="copyOpen" :title="t('Copy a previous order')" @close="copyOpen = false">
            <p class="mb-3 text-sm text-muted">{{ t('The lines are added to the order; you can change them afterwards.') }}</p>
            <ul class="space-y-2">
                <li v-for="recent in recentOrders" :key="recent.uuid">
                    <button type="button" class="w-full rounded-xl border border-line p-3 text-left hover:bg-page" data-flow="copy-source" @click="copyFrom(recent)">
                        <span class="flex items-center gap-2">
                            <span class="flex-1 font-medium">
                                {{ recent.number }}<template v-if="recent.delivery_number && recent.delivery_number !== recent.number"> · {{ recent.delivery_number }}</template>
                            </span>
                            <span class="text-sm text-muted">{{ formatDate(recent.created_at) }}</span>
                        </span>
                        <span class="block text-sm text-muted">
                            {{ recent.author }} · {{ formatMoney(recent.total_ttc) }} {{ t('TTC') }}<template v-if="recent.history_status"> · {{ t(`history.${recent.history_status}`) }}</template>
                        </span>
                        <span class="mt-1 block text-sm">
                            <template v-for="(line, index) in recent.lines.slice(0, 3)" :key="index">
                                {{ formatNumber(line.quantity) }} × {{ line.is_free ? t('free line') : line.reference }}<span v-if="!line.available" class="text-red-700"> ({{ t('no longer available') }})</span><br>
                            </template>
                            <span v-if="recent.lines.length > 3" class="text-muted">{{ tc('+ :count more lines', recent.lines.length - 3) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </BottomSheet>
    </AppLayout>
</template>
