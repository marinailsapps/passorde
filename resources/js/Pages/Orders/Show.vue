<script setup lang="ts">
import { router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import UiButton from '@/components/UiButton.vue';
import ClientChangeCard from '@/components/orders/ClientChangeCard.vue';
import OrderStatusChip from '@/components/orders/OrderStatusChip.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ApiError, apiPost } from '@/lib/api';
import { formatAge, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { clientExtraField, clientExtraLabel } from '@/lib/clientExtraField';
import { t, tc } from '@/lib/i18n';
import { markRefusal, type MarkExportedAnswer } from '@/lib/markExported';
import { isFileMode } from '@/lib/syncMode';
import { testModeApprovalNote } from '@/lib/testMode';
import { dismissError } from '@/offline/orders';
import { currentDb, deleteOrder, localOrder, offline, outboxFor, refreshCounts } from '@/offline/runtime';
import type { OrderDetail, StockConflict } from '@/types';

const props = defineProps<{ order: OrderDetail }>();

/*
 * The phone's copy wins while it differs from the server: an order confirmed
 * offline, an edit waiting in the outbox, or one the server refused. Pages
 * rendered from local data (props.offline) always follow it.
 */
const renderedLocally = computed(() => usePage().props.offline === true);
const local = ref<OrderDetail | null>(null);
const outbox = ref<{ attempts: number; lastError: string | null; nextAttemptAt: number } | null>(null);

async function readLocal(): Promise<void> {
    const copy = await localOrder(props.order.uuid);
    local.value = copy && (renderedLocally.value || copy.local_state !== 'synced') ? copy : null;
    outbox.value = await outboxFor(props.order.uuid);
}

onMounted(readLocal);
watch(() => [offline.revision, props.order] as const, readLocal);

const order = computed<OrderDetail>(() => local.value ?? props.order);
const title = computed(() => order.value.number || t('Local draft'));

const isAdmin = computed(() => usePage().props.auth.user?.role === 'admin');
const fileMode = isFileMode();
/* The order has reached the point where its client hears about it (OrderSms::canBeSent). */
const smsDue = computed(() => order.value.status === (fileMode ? 'approved' : 'sent'));
const back = computed(() => (isAdmin.value && props.order.author.id !== usePage().props.auth.user?.id ? '/admin/orders' : '/orders'));

const error = ref<string | null>(null);
const busy = ref(false);
const dialog = ref<'delete' | 'approve' | 'reject' | 'unexport' | 'send' | 'export' | 'checked' | null>(null);
const reason = ref('');
/** The operator's "Appliquer" on the order's client correction. */
const applyClientChange = ref(true);
const pendingClientChange = computed(() => (order.value.client_change?.status === 'pending' ? order.value.client_change : null));

// While the push runs (admin view), refresh until Evoliz answered.
const { start, stop } = usePoll(3000, { only: ['order'] }, { autoStart: false });
watch(
    () => !fileMode && isAdmin.value && props.order.status === 'approved' && !renderedLocally.value,
    (pushing) => (pushing ? start() : stop()),
    { immediate: true },
);

async function run(action: () => Promise<unknown>, after: () => void): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await action();
        dialog.value = null;
        after();
    } catch (e) {
        error.value = e instanceof ApiError ? e.messages.join(' ') : e instanceof MarkRefused ? e.message : t('The action failed. Check the connection and try again.');
        dialog.value = null;
    } finally {
        busy.value = false;
    }
}

const reload = (): void => router.reload({ only: ['order'] });

const print = (): void => window.print();

// Deleting goes through the outbox too, so it also works offline.
function destroy(): void {
    run(() => deleteOrder(props.order.uuid), () => router.visit(back.value));
}

async function dismiss(): Promise<void> {
    const db = currentDb();
    if (db) await dismissError(db, props.order.uuid);
    await refreshCounts();
}

/* Stock conflicts (admin): one breakdown per article, under its first line. */
const conflicts = computed(() => new Map((order.value.stock_conflicts ?? []).map((conflict) => [conflict.article_id, conflict])));
const conflictLine = (position: number, articleId: number | null): StockConflict | null => {
    if (articleId === null) return null;
    const first = order.value.lines.find((line) => line.article_id === articleId);
    return first?.position === position ? (conflicts.value.get(articleId) ?? null) : null;
};
const shortfallText = computed(() =>
    (order.value.stock_conflicts ?? []).map((conflict) => t(':reference (short by :shortfall)', { reference: conflict.reference, shortfall: formatNumber(conflict.shortfall) })).join(', '),
);

function approve(): void {
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/approve`, pendingClientChange.value ? { apply_client_change: applyClientChange.value } : {}), reload);
}

function reject(): void {
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/reject`, { reason: reason.value }), reload);
}

function retry(): void {
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/retry`), reload);
}

// An order that was in a downloaded export file is sent only after the admin confirms they checked Evoliz.
function sendToEvoliz(): void {
    if (props.order.export_downloaded_at && dialog.value !== 'send') {
        dialog.value = 'send';
        return;
    }
    const body = props.order.export_downloaded_at ? { confirm_download: true } : {};
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/send-to-evoliz`, body), reload);
}

/* Evoliz back, an order approved during the lapse: marked exported again (never sent). */
function markExported(): void {
    run(async () => {
        const refusal = markRefusal(await apiPost<MarkExportedAnswer>('/api/admin/orders/mark-exported', { uuids: [props.order.uuid] }));
        // Refused (Evoliz may hold its BL, or it goes to Evoliz): say why instead of reloading as if it worked.
        if (refusal !== null) throw new MarkRefused(refusal);
    }, reload);
}

class MarkRefused extends Error {}

/* Standalone, a failed order Evoliz may hold: settled for the export once the admin checked Evoliz. */
function settleChecked(): void {
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/retry`, { checked_evoliz: true }), reload);
}

function unmarkExported(): void {
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/unmark-exported`), reload);
}

function resendSms(): void {
    run(() => apiPost(`/api/admin/orders/${props.order.uuid}/sms`), reload);
}

/* Client SMS (admin, SMS_ENABLED): what happened, or what will happen on approval. */
const sms = computed(() => order.value.sms ?? null);
const smsNote = computed(() => {
    if (!sms.value) return '';
    return sms.value.recipient
        ? t(fileMode ? 'The client will receive an SMS at :phone.' : 'The client will receive an SMS at :phone once the delivery note exists.', { phone: sms.value.recipient })
        : t('The client has no mobile number: no SMS will be sent.');
});
const smsStatus = computed(() => {
    const value = sms.value;
    if (!value || !smsDue.value) return null;
    switch (value.status) {
        case 'sent':
            return { tone: 'ok', text: t('SMS sent to :phone, :time', { phone: value.phone ?? '', time: formatDateTime(value.sent_at) }) };
        case 'failed':
            return { tone: 'error', text: t('SMS to :phone failed: :error', { phone: value.phone ?? '', error: value.error ?? '' }) };
        case 'skipped':
            return { tone: 'muted', text: t('No SMS: the client had no mobile number.') };
        case 'pending':
            return { tone: 'muted', text: t('SMS being sent…') };
        default:
            return { tone: 'muted', text: value.recipient ? t('SMS queued.') : t('No SMS: the client has no mobile number.') };
    }
});

/* An article added from an order: its designation is completed in Evoliz by hand before the BL. The catalogue syncs every 15 minutes. */
const missingNumbers = computed(() =>
    order.value.can_approve && order.value.articles_without_numbers?.length
        ? t(fileMode ? 'The designation of :articles is not complete yet. Complete it in the catalogue before approving.' : 'The designation of :articles is not complete yet. Complete it in Evoliz before approving; the app sees the change within 15 minutes.', { articles: order.value.articles_without_numbers.join(', ') })
        : '',
);

const approveMessage = computed(() => {
    const message = t(fileMode ? 'Approve the order of :client, :quantity units, :ttc TTC?' : 'Create the delivery note in Evoliz for :client, :quantity units, :ttc TTC? The BL is registered immediately and cannot be cancelled from the app.', {
        client: props.order.client.company_name,
        quantity: formatNumber(props.order.totals.quantity),
        ttc: formatMoney(props.order.totals.ttc),
    });
    const withStock = shortfallText.value ? `${message} ${t('Insufficient stock: :articles.', { articles: shortfallText.value })}` : message;
    const clientNote = pendingClientChange.value
        ? applyClientChange.value
            ? t(fileMode ? 'The client details are updated at the same time.' : 'The client details are updated in Evoliz first.')
            : t('The client details stay as they are.')
        : null;
    return [testModeApprovalNote(), withStock, missingNumbers.value, clientNote, smsNote.value].filter(Boolean).join(' ');
});
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="title" :back="back">
            <template #actions>
                <div class="print:hidden">
                    <OrderStatusChip :status="order.display_status" :label="order.status_label" :local-state="order.local_state" />
                </div>
            </template>
        </PageHeader>

        <!-- Paper gets the client, the lines and the totals; everything else stays on screen. -->
        <div class="print:hidden">
            <!-- Phone side: waiting in the outbox, or refused by the server -->
            <div v-if="order.local_state === 'queued'" class="mb-3 rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-slate-800">
                <p class="font-semibold">{{ order.deleted_at ? t('Deletion waiting for network') : t('Waiting for network') }}</p>
                <p class="mt-1 text-sm">{{ t('Saved on this phone. It will be sent automatically as soon as the network is back.') }}</p>
                <p v-if="outbox && outbox.attempts > 0" class="mt-1 text-sm text-muted">
                    {{ tc(':count attempts, last error: :error', outbox.attempts, { error: outbox.lastError ?? '—' }) }}
                    <template v-if="outbox.nextAttemptAt > Date.now()"> · {{ t('next try :age', { age: formatAge(new Date(outbox.nextAttemptAt).toISOString()) }) }}</template>
                </p>
            </div>
            <div v-else-if="order.local_state === 'unsent'" class="mb-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-900" role="alert">
                <p class="font-semibold">{{ t('Not sent: :message', { message: order.local_error ?? '' }) }}</p>
                <p v-if="order.number === ''" class="mt-1 text-sm">{{ t('The office has not received this order. Fix it and confirm again.') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <UiButton v-if="order.can_edit && (order.status === 'local' || order.status === 'pending')" :href="`/orders/${order.uuid}/edit`">{{ t('Fix the order') }}</UiButton>
                    <UiButton v-if="order.number !== ''" variant="secondary" @click="dismiss">{{ t('OK') }}</UiButton>
                </div>
            </div>

            <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-4 py-3 text-red-900" role="alert">{{ error }}</p>
            <div v-if="order.status === 'rejected'" data-flow="rejected-reason" class="mb-3 rounded-lg bg-red-50 px-4 py-3 text-red-900">
                <p v-if="order.rejected_reason">{{ t('Rejected: :reason', { reason: order.rejected_reason }) }}</p>
                <p class="mt-1 text-sm">{{ t('Rejected orders cannot be sent again: duplicate it to enter a corrected order.') }}</p>
            </div>

            <!-- Admin: push problems -->
            <div v-if="order.evoliz_error && !fileMode" data-flow="evoliz-error" class="mb-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-900">
                <p class="font-semibold">{{ t('Evoliz push failed') }}</p>
                <template v-if="order.evoliz_error_summary">
                    <p class="mt-1 break-words" data-flow="evoliz-error-summary">{{ order.evoliz_error_summary }}</p>
                    <p class="mt-2 text-xs break-words whitespace-pre-line text-slate-500">{{ t('Technical detail') }} : {{ order.evoliz_error }}</p>
                </template>
                <p v-else class="mt-1 text-sm break-words whitespace-pre-line">{{ order.evoliz_error }}</p>
            </div>
            <div v-if="order.evoliz_warning && !fileMode" class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
                <p class="font-semibold">{{ t('Evoliz warning') }}</p>
                <p class="mt-1 text-sm break-words whitespace-pre-line">{{ order.evoliz_warning }}</p>
            </div>
            <p v-if="!fileMode && isAdmin && order.push_pending === true" class="mb-3 rounded-lg bg-sky-50 px-4 py-3 text-sky-900">{{ t('Sending to Evoliz…') }}</p>
        </div>

        <div class="lg:grid lg:grid-cols-[1fr_20rem] lg:gap-6">
            <div class="space-y-4">
                <section class="rounded-xl border border-line bg-surface p-4">
                    <p class="font-semibold">{{ order.client.company_name }}</p>
                    <p v-if="order.client.contact_name" class="text-muted">{{ order.client.contact_name }}</p>
                    <p class="text-muted">{{ [order.client.address_line, [order.client.postcode, order.client.town].filter(Boolean).join(' ')].filter(Boolean).join(', ') }}</p>
                    <p v-if="order.client.extra_field && clientExtraField()" class="text-muted">{{ clientExtraLabel() }} {{ order.client.extra_field }}</p>
                    <p v-if="isAdmin && !order.client.linked" class="mt-2 text-sm text-amber-800 print:hidden">{{ fileMode ? t('New client: it joins the directory on approval.') : t('New client: it will be created in Evoliz on approval.') }}</p>
                </section>

                <ClientChangeCard
                    v-if="order.client_change && order.client_change.status !== 'cancelled'"
                    v-model:apply="applyClientChange"
                    class="print:hidden"
                    :change="order.client_change"
                    :can-choose="order.can_approve && !renderedLocally"
                />

                <section class="rounded-xl border border-line bg-surface p-4">
                    <h2 class="mb-2 font-semibold">{{ t('Lines') }}</h2>
                    <ul class="divide-y divide-line text-sm">
                        <template v-for="line in order.lines" :key="line.position">
                            <li class="flex gap-3 py-2 print:break-inside-avoid" :class="{ 'text-muted': line.is_free }">
                                <span class="w-10 shrink-0 text-right tabular-nums">{{ formatNumber(line.quantity) }} ×</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex gap-2">
                                        <span class="flex-1 font-medium" :class="{ 'font-normal': line.is_free }">{{ line.is_free ? `↳ ${t('Free lines')}` : line.reference }}</span>
                                        <span class="shrink-0 tabular-nums">{{ formatMoney(line.total_ht) }}</span>
                                    </span>
                                    <template v-if="!line.is_free">
                                        <span class="block text-muted">{{ line.designation }}</span>
                                        <span class="block text-xs text-muted">{{ formatMoney(line.unit_price_ht) }} HT · {{ t('VAT :rate %', { rate: formatNumber(line.vat_rate) }) }}</span>
                                    </template>
                                    <span v-if="line.note" class="mt-1 block break-words whitespace-pre-line text-ink" data-flow="line-note-text">{{ line.note }}</span>
                                </span>
                            </li>
                            <li v-if="conflictLine(line.position, line.article_id)" class="pb-2 print:hidden">
                                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900">
                                    <p class="font-semibold">{{ t('Insufficient stock for :reference', { reference: conflictLine(line.position, line.article_id)!.reference }) }}</p>
                                    <dl class="mt-1 grid grid-cols-[1fr_auto] gap-x-3">
                                        <dt>{{ fileMode ? t('Stock') : t('Evoliz stock') }}</dt>
                                        <dd class="text-right tabular-nums">{{ formatNumber(conflictLine(line.position, line.article_id)!.stock_qty) }}</dd>
                                        <dt>{{ t('Reserved by earlier orders') }}</dt>
                                        <dd class="text-right tabular-nums">{{ formatNumber(conflictLine(line.position, line.article_id)!.reserved_before) }}</dd>
                                        <dt>{{ t('Requested') }}</dt>
                                        <dd class="text-right tabular-nums">{{ formatNumber(conflictLine(line.position, line.article_id)!.requested) }}</dd>
                                        <dt class="font-semibold">{{ t('Shortfall') }}</dt>
                                        <dd class="text-right font-semibold tabular-nums">{{ formatNumber(conflictLine(line.position, line.article_id)!.shortfall) }}</dd>
                                    </dl>
                                    <p v-if="conflictLine(line.position, line.article_id)!.earlier_orders.length" class="mt-1">
                                        {{ t('Earlier orders: :numbers', { numbers: conflictLine(line.position, line.article_id)!.earlier_orders.join(', ') }) }}
                                    </p>
                                </div>
                            </li>
                        </template>
                    </ul>
                </section>

                <section v-if="order.activity" class="rounded-xl border border-line bg-surface p-4 print:hidden">
                    <h2 class="mb-2 font-semibold">{{ t('History') }}</h2>
                    <ol class="space-y-2 border-l-2 border-line pl-4 text-sm">
                        <li v-for="(entry, index) in order.activity" :key="index">
                            <p>
                                <span class="font-medium">{{ entry.label }}</span>
                                <span class="text-muted"> · {{ formatDateTime(entry.created_at) }} · {{ entry.user ?? t('System') }}</span>
                            </p>
                            <p v-if="entry.detail" class="text-muted break-words whitespace-pre-line">{{ entry.detail }}</p>
                        </li>
                    </ol>
                </section>
            </div>

            <aside class="mt-4 space-y-4 lg:mt-0">
                <section class="rounded-xl border border-line bg-surface p-4 print:break-inside-avoid">
                    <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-sm">
                        <dt class="text-muted">{{ t('Total quantity') }}</dt>
                        <dd class="text-right tabular-nums">{{ formatNumber(order.totals.quantity) }}</dd>
                        <dt class="text-muted">{{ t('Total HT') }}</dt>
                        <dd class="text-right tabular-nums">{{ formatMoney(order.totals.ht) }}</dd>
                        <template v-for="row in order.totals.vat_by_rate" :key="row.rate">
                            <dt class="text-muted">{{ t('VAT :rate %', { rate: formatNumber(row.rate) }) }}</dt>
                            <dd class="text-right tabular-nums">{{ formatMoney(row.vat) }}</dd>
                        </template>
                        <dt class="border-t border-line pt-1 text-base font-semibold">{{ t('Total TTC') }}</dt>
                        <dd class="border-t border-line pt-1 text-right text-base font-semibold tabular-nums">{{ formatMoney(order.totals.ttc) }}</dd>
                    </dl>
                </section>

                <section class="rounded-xl border border-line bg-surface p-4 text-sm print:hidden">
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <dt class="text-muted">{{ t('Payment method') }}</dt>
                        <dd>{{ order.paytype?.label ?? t('Not specified') }}</dd>
                        <dt class="text-muted">{{ t('Created') }}</dt>
                        <dd>{{ formatDateTime(order.created_at) }} · {{ order.author.name }}</dd>
                        <template v-if="order.approved_at">
                            <dt class="text-muted">{{ t('Approved') }}</dt>
                            <dd>{{ formatDateTime(order.approved_at) }}<template v-if="order.approved_by"> · {{ order.approved_by }}</template></dd>
                        </template>
                        <!-- A rejected order never gets one. -->
                        <template v-if="order.exported_at">
                            <dt class="text-muted">{{ t('Exported') }}</dt>
                            <dd>{{ formatDateTime(order.exported_at) }}<template v-if="order.exported_by"> · {{ order.exported_by }}</template></dd>
                        </template>
                        <template v-if="order.status !== 'rejected' && !fileMode">
                            <dt class="text-muted">{{ t('Delivery note') }}</dt>
                            <dd data-flow="delivery-number">{{ order.evoliz_delivery_number ?? t('Not yet in Evoliz') }}</dd>
                        </template>
                        <template v-if="sms && !smsDue">
                            <dt class="text-muted">{{ t('Client SMS') }}</dt>
                            <dd>{{ sms.recipient ?? t('No mobile number') }}</dd>
                        </template>
                    </dl>
                    <p v-if="order.comment" class="mt-3 whitespace-pre-line">{{ order.comment }}</p>
                </section>

                <section
                    v-if="smsStatus"
                    data-flow="order-sms"
                    class="rounded-xl border p-4 text-sm print:hidden"
                    :class="smsStatus.tone === 'error' ? 'border-red-300 bg-red-50 text-red-900' : 'border-line bg-surface'"
                >
                    <p class="font-semibold">{{ t('Client SMS') }}</p>
                    <p class="mt-1 break-words" :class="{ 'text-muted': smsStatus.tone === 'muted' }">{{ smsStatus.text }}</p>
                    <UiButton v-if="sms?.can_resend" variant="secondary" class="mt-3 w-full" data-flow="order-sms-resend" :disabled="busy" @click="resendSms">{{ t('Resend') }}</UiButton>
                </section>

                <p v-if="missingNumbers" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 print:hidden" data-flow="order-missing-numbers">{{ missingNumbers }}</p>

                <div class="grid gap-2 print:hidden">
                    <UiButton v-if="order.can_approve" data-flow="order-approve" @click="dialog = 'approve'">{{ t('Approve') }}</UiButton>
                    <UiButton v-if="order.can_reject" variant="secondary" data-flow="order-reject" @click="dialog = 'reject'">{{ t('Reject') }}</UiButton>
                    <UiButton v-if="order.can_retry" data-flow="order-retry" :disabled="busy" @click="retry">{{ isFileMode() ? t('Approve without Evoliz') : t('Retry') }}</UiButton>
                    <UiButton v-if="order.can_send_to_evoliz" data-flow="order-send-to-evoliz" :disabled="busy" @click="sendToEvoliz">{{ t('Send to Evoliz') }}</UiButton>
                    <UiButton v-if="order.can_settle_checked" variant="secondary" data-flow="order-settle-checked" :disabled="busy" @click="dialog = 'checked'">{{ t('Approve without Evoliz') }}</UiButton>
                    <UiButton v-if="order.can_mark_exported" variant="secondary" data-flow="order-mark-exported" :disabled="busy" @click="dialog = 'export'">{{ tc('Mark as exported', 1) }}</UiButton>
                    <UiButton v-if="order.can_unmark_exported" variant="secondary" data-flow="order-unmark-exported" :disabled="busy" @click="dialog = 'unexport'">{{ t('Cancel the export') }}</UiButton>
                    <UiButton v-if="order.can_edit" data-flow="order-edit" :href="`/orders/${order.uuid}/edit`" :variant="order.can_approve ? 'secondary' : 'primary'">{{ t('Edit') }}</UiButton>
                    <UiButton :variant="order.status === 'rejected' ? 'primary' : 'secondary'" :href="`/orders/new?from=${order.uuid}`">{{ t('Duplicate') }}</UiButton>
                    <UiButton variant="secondary" data-flow="order-print" @click="print">{{ t('Print') }}</UiButton>
                    <UiButton v-if="order.can_delete ?? order.can_edit" variant="ghost" class="text-red-700 hover:bg-red-50" data-flow="order-delete" @click="dialog = 'delete'">{{ t('Delete') }}</UiButton>
                </div>
            </aside>
        </div>

        <ConfirmDialog
            :open="dialog === 'delete'"
            :title="t('Delete :number?', { number: title })"
            :message="t('The order will be removed from your list. This cannot be undone.')"
            :confirm-label="t('Delete')"
            danger
            :busy="busy"
            @confirm="destroy"
            @cancel="dialog = null"
        />

        <ConfirmDialog
            :open="dialog === 'approve'"
            :title="t('Approve :number?', { number: order.number })"
            :message="approveMessage"
            :confirm-label="fileMode ? t('Approve') : t('Create the delivery note')"
            :busy="busy"
            @confirm="approve"
            @cancel="dialog = null"
        />

        <ConfirmDialog
            :open="dialog === 'send'"
            :title="t('Send :number to Evoliz?', { number: order.number })"
            :message="t('This order was in an export file on :date: check in Evoliz first that its BL was not typed by hand, or a second BL would be created.', { date: formatDateTime(order.export_downloaded_at ?? null) })"
            :confirm-label="t('I checked: send to Evoliz')"
            :busy="busy"
            @confirm="sendToEvoliz"
            @cancel="dialog = null"
        />

        <ConfirmDialog
            :open="dialog === 'export'"
            :title="t('Mark :number as exported?', { number: order.number })"
            :message="t('Its quantities leave the stock and it counts as a sale. It is never sent to Evoliz.')"
            :confirm-label="tc('Mark as exported', 1)"
            :busy="busy"
            @confirm="markExported"
            @cancel="dialog = null"
        />

        <ConfirmDialog
            :open="dialog === 'checked'"
            :title="t('Approve :number without Evoliz?', { number: order.number })"
            :message="t('The creation of its delivery note in Evoliz got no clear answer. Check in Evoliz that no delivery note carries the order number :number. Only then approve it for the export: it is never sent to Evoliz afterwards.', { number: order.number })"
            :confirm-label="t('I checked: approve without Evoliz')"
            danger
            :busy="busy"
            @confirm="settleChecked"
            @cancel="dialog = null"
        />

        <ConfirmDialog
            :open="dialog === 'unexport'"
            :title="t('Cancel the export of :number?', { number: order.number })"
            :message="t('The order goes back to the orders to export and its quantities return to the stock. If it was already retyped in the invoicing software, it stays there.')"
            :confirm-label="t('Cancel the export')"
            :busy="busy"
            @confirm="unmarkExported"
            @cancel="dialog = null"
        />

        <BottomSheet :open="dialog === 'reject'" :title="t('Reject :number?', { number: order.number })" @close="dialog = null">
            <form class="space-y-3" @submit.prevent="reject">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ t('Reason (shown to the sales person)') }}</span>
                    <textarea v-model="reason" data-flow="reject-reason" rows="3" maxlength="500" required class="block w-full rounded-lg border border-field p-3 text-base focus:outline-2 focus:outline-accent/30" />
                </label>
                <div class="grid gap-2 sm:grid-cols-2">
                    <UiButton variant="secondary" @click="dialog = null">{{ t('Cancel') }}</UiButton>
                    <UiButton type="submit" variant="danger" :disabled="busy || reason.trim() === ''">{{ t('Reject') }}</UiButton>
                </div>
            </form>
        </BottomSheet>
    </AppLayout>
</template>
