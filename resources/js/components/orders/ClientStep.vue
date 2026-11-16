<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import SearchField from '@/components/SearchField.vue';
import StatusChip from '@/components/StatusChip.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AddressAutocomplete from '@/components/orders/AddressAutocomplete.vue';
import ClientHistory from '@/components/orders/ClientHistory.vue';
import { ApiError, apiGet, apiPut } from '@/lib/api';
import {
    CORRECTABLE,
    correctionDiff,
    correctionErrors,
    correctionForm,
    corrected,
    fieldLabel,
    hasCorrection,
    type ClientField,
    type CorrectionForm,
} from '@/lib/clientCorrection';
import { clientExtraField, clientExtraLabel, clientSearchLabel } from '@/lib/clientExtraField';
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import { selectionOf } from '@/offline/orders';
import { isOffline, searchClients } from '@/offline/runtime';
import type { ClientData, ClientSelection, NewClientData } from '@/types';

const selection = defineModel<ClientSelection | null>({ required: true });

const mode = ref<'summary' | 'search' | 'form' | 'correct' | 'created'>(selection.value ? 'summary' : 'search');
const query = ref('');
const results = ref<ClientData[]>([]);
const searching = ref(false);
const duplicate = ref<ClientData | null>(null);
const errors = reactive<Record<string, string>>({});

const blank = (): NewClientData => ({
    uuid: crypto.randomUUID(),
    company_name: '',
    contact_name: '',
    address_line: '',
    postcode: '',
    town: '',
    phone: '',
    email: '',
    extra_field: '',
});
const form = reactive<NewClientData>(selection.value?.kind === 'new' ? { ...selection.value.client } : blank());
const offlineNow = computed(() => isOffline());
/** The open form's fields when it opened: an untouched form has nothing to keep. */
let formAsOpened = '';

async function search(value: string): Promise<void> {
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    searching.value = true;
    try {
        // The API when online, the phone's copy otherwise (or when the API fails).
        results.value = await searchClients(value);
    } finally {
        searching.value = false;
    }
}

function pick(client: ClientData): void {
    // A client typed on the phone (id 0) stays a new client, with the same uuid.
    selection.value = selectionOf(client);
    mode.value = 'summary';
    duplicate.value = null;
}

function openForm(): void {
    if (selection.value?.kind === 'new') Object.assign(form, selection.value.client);
    formAsOpened = JSON.stringify(form);
    mode.value = 'form';
}

function validate(): boolean {
    for (const key of Object.keys(errors)) delete errors[key];
    if (!form.company_name.trim()) errors.company_name = t('Required.');
    if (!/^\d{5}$/.test(form.postcode.trim())) errors.postcode = t('5 digits.');
    if (!form.town.trim()) errors.town = t('Required.');
    return Object.keys(errors).length === 0;
}

/** "Enregistrer sans commande": the client goes to the server now, not with an order. */
const withoutOrder = ref(false);
const savingAlone = ref(false);
const aloneErrors = ref<string[]>([]);
const created = ref<ClientData | null>(null);

async function saveNewClient(alone = false): Promise<void> {
    if (!validate()) return;
    withoutOrder.value = alone;
    aloneErrors.value = [];

    // Warn (never block) when a similar client already exists in this postcode. Offline the server checks it later.
    try {
        if (isOffline()) throw new Error('offline');
        const response = await apiGet<{ possible_duplicate: ClientData | null }>('/api/clients/possible-duplicate', {
            company_name: form.company_name,
            postcode: form.postcode,
        });
        duplicate.value = response.possible_duplicate;
    } catch {
        duplicate.value = null;
    }

    if (!duplicate.value) keepNewClient();
}

function keepNewClient(): void {
    const client = { ...form, company_name: form.company_name.trim(), town: form.town.trim(), postcode: form.postcode.trim() };
    if (withoutOrder.value) {
        void saveClientAlone(client);
        return;
    }
    selection.value = { kind: 'new', client, ignoredDuplicateId: duplicate.value?.id };
    duplicate.value = null;
    mode.value = 'summary';
}

async function saveClientAlone({ uuid, ...fields }: NewClientData): Promise<void> {
    duplicate.value = null;
    savingAlone.value = true;
    try {
        const response = await apiPut<{ client: ClientData }>(`/api/clients/${uuid}`, fields);
        created.value = response.client;
        Object.assign(form, blank());
        mode.value = 'created';
    } catch (error) {
        aloneErrors.value = error instanceof ApiError ? error.messages : [t('The client could not be saved. Check the network and try again.')];
    } finally {
        savingAlone.value = false;
    }
}

/* Correcting an existing client: kept with the order, applied to Evoliz on approval. */

const correction = computed(() => (selection.value?.kind === 'existing' ? selection.value.changes : undefined));
const correctedFields = computed(() => CORRECTABLE.filter((field) => correction.value && field in correction.value));
const correctionFields = reactive<CorrectionForm>(correctionForm({} as ClientData));
const correctionFieldErrors = reactive<Partial<Record<ClientField, string>>>({});

function openCorrection(): void {
    if (selection.value?.kind !== 'existing') return;
    Object.assign(correctionFields, correctionForm(selection.value.client, selection.value.changes));
    for (const key of Object.keys(correctionFieldErrors)) delete correctionFieldErrors[key as ClientField];
    formAsOpened = JSON.stringify(correctionFields);
    mode.value = 'correct';
}

function keepCorrection(): void {
    if (selection.value?.kind !== 'existing') return;
    for (const key of Object.keys(correctionFieldErrors)) delete correctionFieldErrors[key as ClientField];
    Object.assign(correctionFieldErrors, correctionErrors(selection.value.client, correctionFields));
    if (Object.keys(correctionFieldErrors).length > 0) return;

    const changes = correctionDiff(selection.value.client, correctionFields);
    selection.value = { kind: 'existing', client: selection.value.client, ...(hasCorrection(changes) ? { changes } : {}) };
    mode.value = 'summary';
}

function dropCorrection(): void {
    if (selection.value?.kind === 'existing') selection.value = { kind: 'existing', client: selection.value.client };
}

/**
 * Leaving the step ("Continuer", the step tabs) with a form open keeps what was
 * typed, as the form's own button does. False keeps the user here: an error to
 * fix, or a possible duplicate to decide on.
 */
async function keepOpenForm(): Promise<boolean> {
    const backOnSummary = (): boolean => mode.value === 'summary';

    if (mode.value === 'correct' && JSON.stringify(correctionFields) !== formAsOpened) {
        keepCorrection();
        return backOnSummary();
    }
    if (mode.value === 'form' && JSON.stringify(form) !== formAsOpened) {
        await saveNewClient();
        return backOnSummary();
    }
    return true;
}

defineExpose({ keepOpenForm });

const summary = computed(() => {
    if (!selection.value) return null;
    return selection.value.kind === 'existing' ? corrected(selection.value.client, selection.value.changes) : selection.value.client;
});
const changed = (field: ClientField): boolean => correctedFields.value.includes(field);

/* "Historique": the client's purchases (Evoliz BLs and app orders). */
const historyOpen = ref(false);
const historyClient = computed(() => (selection.value?.kind === 'existing' && selection.value.client.id > 0 ? selection.value.client : null));

watch(mode, (value) => {
    if (value === 'search') {
        query.value = '';
        results.value = [];
    }
});
</script>

<template>
    <section>
        <!-- Chosen client -->
        <div v-if="mode === 'summary' && summary" class="rounded-xl border border-line bg-surface p-4">
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                    <p class="text-lg font-semibold">{{ summary.company_name }}</p>
                    <p v-if="summary.contact_name" class="text-muted" :class="{ 'font-medium text-accent': changed('contact_name') }">{{ summary.contact_name }}</p>
                    <p class="text-muted" :class="{ 'font-medium text-accent': changed('address_line') || changed('postcode') || changed('town') }">
                        {{ [summary.address_line, [summary.postcode, summary.town].filter(Boolean).join(' ')].filter(Boolean).join(', ') }}
                    </p>
                    <p v-if="summary.phone" class="text-muted" :class="{ 'font-medium text-accent': changed('phone') }">{{ summary.phone }}</p>
                    <p v-if="'mobile' in summary && summary.mobile && summary.mobile !== summary.phone" class="text-muted" :class="{ 'font-medium text-accent': changed('mobile') }">
                        {{ t('Mobile') }} {{ summary.mobile }}
                    </p>
                    <p v-if="summary.email" class="break-all text-muted" :class="{ 'font-medium text-accent': changed('email') }">{{ summary.email }}</p>
                    <p v-if="summary.extra_field && clientExtraField()" class="text-muted" :class="{ 'font-medium text-accent': changed('extra_field') }">{{ clientExtraLabel() }} {{ summary.extra_field }}</p>
                </div>
                <StatusChip v-if="selection?.kind === 'new'" tone="warning">{{ t('New client') }}</StatusChip>
                <StatusChip v-else-if="correctedFields.length" tone="warning">{{ t('Modified') }}</StatusChip>
            </div>
            <p v-if="correctedFields.length" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-flow="client-correction-note">
                {{ t(isFileMode() ? 'Changed: :fields. Applied when the order is approved.' : 'Changed: :fields. Sent to Evoliz when the order is approved.', { fields: correctedFields.map((field) => fieldLabel(field).toLowerCase()).join(', ') }) }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <!-- A new client's details can be edited; a client from Evoliz can be swapped for another one or corrected. -->
                <UiButton variant="secondary" data-flow="client-change" @click="selection?.kind === 'new' ? openForm() : (mode = 'search')">
                    {{ selection?.kind === 'new' ? t('Change') : t('Choose another client') }}
                </UiButton>
                <UiButton v-if="selection?.kind === 'existing'" variant="secondary" data-flow="client-correct" @click="openCorrection">{{ t('Edit the details') }}</UiButton>
                <UiButton v-if="historyClient" variant="secondary" data-flow="client-history-open" @click="historyOpen = true">{{ t('History') }}</UiButton>
                <UiButton v-if="selection?.kind === 'existing' && correctedFields.length" variant="ghost" data-flow="client-correction-drop" @click="dropCorrection">
                    {{ t('Undo the changes') }}
                </UiButton>
                <UiButton v-if="selection?.kind === 'new'" variant="ghost" @click="mode = 'search'">{{ t('Pick an existing client') }}</UiButton>
            </div>
        </div>

        <!-- Correcting an existing client -->
        <form
            v-else-if="mode === 'correct' && selection?.kind === 'existing'"
            class="space-y-3 rounded-xl border border-line bg-surface p-4"
            data-flow="client-correct-form"
            novalidate
            @submit.prevent="keepCorrection"
        >
            <div>
                <h2 class="font-semibold">{{ selection.client.company_name }}</h2>
                <p class="text-sm text-muted">{{ isFileMode() ? t('The changes are applied with this order, once the operator approves it.') : t('The changes go to Evoliz with this order, once the operator approves it.') }}</p>
            </div>
            <TextField v-model="correctionFields.contact_name" :label="fieldLabel('contact_name')" autocomplete="name" :error="correctionFieldErrors.contact_name" />
            <AddressAutocomplete v-model="correctionFields.address_line" data-flow="client-correct-address" @pick="(address) => Object.assign(correctionFields, address)" />
            <div class="grid grid-cols-[7rem_1fr] gap-3">
                <TextField
                    v-model="correctionFields.postcode"
                    :label="fieldLabel('postcode')"
                    inputmode="numeric"
                    autocomplete="postal-code"
                    :error="correctionFieldErrors.postcode"
                    required
                />
                <TextField v-model="correctionFields.town" :label="fieldLabel('town')" autocomplete="address-level2" :error="correctionFieldErrors.town" required />
            </div>
            <TextField v-model="correctionFields.phone" :label="fieldLabel('phone')" type="tel" inputmode="tel" autocomplete="tel" :error="correctionFieldErrors.phone" />
            <TextField
                v-model="correctionFields.mobile"
                :label="t('Mobile (SMS)')"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                :error="correctionFieldErrors.mobile"
            />
            <TextField v-model="correctionFields.email" :label="fieldLabel('email')" type="email" inputmode="email" autocomplete="email" :error="correctionFieldErrors.email" />
            <TextField v-if="clientExtraField()" v-model="correctionFields.extra_field" :label="fieldLabel('extra_field')" inputmode="numeric" />

            <div class="flex flex-wrap gap-2">
                <UiButton type="submit" data-flow="client-correct-save">{{ t('Keep the changes') }}</UiButton>
                <UiButton variant="ghost" @click="mode = 'summary'">{{ t('Cancel') }}</UiButton>
            </div>
        </form>

        <!-- Search -->
        <div v-else-if="mode === 'search'" class="space-y-3">
            <!-- While looking for another client, the current one stays in view and can be kept. -->
            <div v-if="summary" class="flex items-center gap-3 rounded-xl border border-line bg-page px-4 py-2">
                <p class="min-w-0 flex-1 text-sm">
                    <span class="text-muted">{{ t('Current client:') }}</span>
                    <span class="font-medium break-words">{{ summary.company_name }}</span>
                </p>
                <UiButton variant="secondary" class="shrink-0" @click="mode = 'summary'">{{ t('Keep this client') }}</UiButton>
            </div>
            <SearchField v-model="query" data-flow="client-search" :label="clientSearchLabel()" :delay="250" @search="search" />
            <p v-if="query.trim().length >= 2 && !searching && results.length === 0" class="text-sm text-muted">{{ t('No client found.') }}</p>
            <ul v-if="results.length" class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
                <li v-for="client in results" :key="client.id || client.uuid || client.company_name">
                    <button type="button" class="flex min-h-16 w-full flex-col items-start px-4 py-3 text-left hover:bg-page" data-flow="client-result" @click="pick(client)">
                        <span class="flex items-center gap-2 font-medium">
                            {{ client.company_name }}
                            <StatusChip v-if="client.id === 0" tone="warning">{{ t('New client') }}</StatusChip>
                        </span>
                        <span class="text-sm text-muted">
                            {{ [client.contact_name, client.town, client.extra_field && clientExtraField() ? `${clientExtraLabel()} ${client.extra_field}` : null].filter(Boolean).join(' · ') }}
                        </span>
                    </button>
                </li>
            </ul>
            <div class="flex flex-wrap gap-2">
                <UiButton variant="secondary" data-flow="client-new" @click="openForm">{{ t('New client') }}</UiButton>
            </div>
        </div>

        <!-- Saved without an order -->
        <div v-else-if="mode === 'created' && created" class="rounded-xl border border-line bg-surface p-4" data-flow="client-created">
            <p class="text-lg font-semibold">{{ created.company_name }}</p>
            <p class="mt-1 text-muted">{{ isFileMode() ? t('Client saved.') : t('Client saved. It is on its way to Evoliz.') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <UiButton data-flow="client-created-order" @click="pick(created)">{{ t('Order for this client') }}</UiButton>
                <UiButton variant="secondary" href="/">{{ t('Home') }}</UiButton>
            </div>
        </div>

        <!-- New client -->
        <form v-else class="space-y-3 rounded-xl border border-line bg-surface p-4" data-flow="client-form" novalidate @submit.prevent="saveNewClient()">
            <h2 class="font-semibold">{{ t('New client') }}</h2>
            <TextField v-model="form.company_name" :label="t('Company')" autocomplete="organization" :error="errors.company_name" required />
            <TextField v-model="form.contact_name" :label="t('Name')" autocomplete="name" />
            <AddressAutocomplete v-model="form.address_line" data-flow="client-address" @pick="(address) => Object.assign(form, address)" />
            <div class="grid grid-cols-[7rem_1fr] gap-3">
                <TextField v-model="form.postcode" :label="t('Postcode')" inputmode="numeric" autocomplete="postal-code" :error="errors.postcode" required />
                <TextField v-model="form.town" :label="t('Town')" autocomplete="address-level2" :error="errors.town" required />
            </div>
            <TextField v-model="form.phone" :label="t('Phone')" type="tel" inputmode="tel" autocomplete="tel" />
            <TextField v-model="form.email" :label="t('Email')" type="email" inputmode="email" autocomplete="email" />
            <TextField v-if="clientExtraField()" v-model="form.extra_field" :label="clientExtraLabel()" inputmode="numeric" />

            <div v-if="duplicate" class="rounded-lg border border-amber-300 bg-amber-50 p-3">
                <p class="text-sm text-amber-900">{{ t('A similar client already exists:') }}</p>
                <p class="font-medium">{{ duplicate.company_name }}</p>
                <p class="text-sm text-muted">{{ [duplicate.contact_name, duplicate.postcode, duplicate.town].filter(Boolean).join(' · ') }}</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <UiButton @click="pick(duplicate)">{{ t('Use this client') }}</UiButton>
                    <UiButton variant="secondary" @click="keepNewClient">{{ t('Keep the new client') }}</UiButton>
                </div>
            </div>

            <ul v-if="aloneErrors.length" class="rounded-lg bg-red-50 px-4 py-3 text-red-900" role="alert">
                <li v-for="message in aloneErrors" :key="message">{{ message }}</li>
            </ul>

            <div v-if="!duplicate" class="flex flex-wrap gap-2">
                <UiButton type="submit" :disabled="savingAlone">{{ t('Save the client') }}</UiButton>
                <!-- Only from an empty order: a client already chosen stays with this order. The outbox only carries orders. -->
                <UiButton v-if="!selection" variant="secondary" data-flow="client-save-alone" :disabled="savingAlone || offlineNow" @click="saveNewClient(true)">
                    {{ savingAlone ? t('Saving…') : t('Save without an order') }}
                </UiButton>
                <UiButton variant="ghost" data-flow="client-form-cancel" @click="mode = selection ? 'summary' : 'search'">{{ t('Cancel') }}</UiButton>
            </div>
            <p v-if="!selection && !duplicate && offlineNow" class="text-sm text-muted">{{ t('Without network, a new client can only be saved with an order.') }}</p>
        </form>

        <BottomSheet v-if="historyClient" full :open="historyOpen" :title="t('History: :client', { client: historyClient.company_name })" @close="historyOpen = false">
            <ClientHistory :client-id="historyClient.id" :open="historyOpen" />
        </BottomSheet>
    </section>
</template>
