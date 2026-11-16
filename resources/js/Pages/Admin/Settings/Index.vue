<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, useId, watch } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime, formatDay } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SyncLicence } from '@/types';

type Field = 'company_id' | 'public_key' | 'secret_key';

interface FieldView {
    set: boolean;
    source: 'database' | 'env' | null;
    hint: string | null;
}

interface EvolizBlock {
    active: boolean;
    reason: 'credentials' | 'licence' | null;
    fields: Record<Field, FieldView>;
    licence: Partial<SyncLicence> & {
        source: 'database' | 'file' | null;
        licence_id: string | null;
        licensee: string | null;
        plan: string | null;
        /** The plan in words ("abonnement 3 mois"); null without a plan. */
        plan_label?: string | null;
        updates_until: string | null;
    };
    test: { status: 'pending' | 'ok' | 'failed'; message: string | null; at: string } | null;
    /** What stops Evoliz, in French (missing licence key or Evoliz keys); empty when it runs. */
    missing: string[];
}

/**
 * `unreadable`: saved keys that no longer decrypt (APP_KEY changed); they count as unset.
 * `profileErrors`: what passorde:check-profile finds wrong in the installation profile (also in the digest).
 */
const props = defineProps<{ evoliz: EvolizBlock | null; unreadable?: string[]; profileErrors?: string[] }>();

// Secrets are never sent back: the fields start empty, an empty field keeps what is saved.
const form = useForm({ licence_key: '', company_id: '', public_key: '', secret_key: '', clear: [] as Field[] });
const keyId = useId();

function submit(): void {
    form.put('/admin/settings/evoliz', { preserveScroll: true, onSuccess: () => form.reset() });
}

function clearChecked(field: Field): boolean {
    return form.clear.includes(field);
}

function setClear(field: Field, value: boolean): void {
    form.clear = value ? [...form.clear, field] : form.clear.filter((f) => f !== field);
}

const placeholder = (field: Field): string | undefined => {
    const view = props.evoliz?.fields[field];
    if (!view?.set) {
        return undefined;
    }
    return field === 'secret_key' ? t('Saved, type to replace') : (view.hint ?? undefined);
};

const sourceText = (field: Field): string | undefined => {
    const source = props.evoliz?.fields[field].source;
    return source === 'env' ? t('Set by the installation (.env); a value typed here replaces it.') : undefined;
};

const status = computed(() => {
    const block = props.evoliz;
    if (block === null) {
        return null;
    }
    if (block.active) {
        return { tone: 'ok', text: t('Evoliz is running.') };
    }
    return { tone: 'off', text: t(block.reason === 'credentials' ? 'Evoliz keys incomplete: the app runs on its own.' : 'No usable licence: the app runs on its own.') };
});

/* "Tester la connexion": queued; the page asks again every 2 s while it is pending (30 times at most). */
let timer: ReturnType<typeof setInterval> | undefined;
let polls = 0;

function stopPolling(): void {
    if (timer !== undefined) {
        clearInterval(timer);
        timer = undefined;
    }
}

watch(
    () => props.evoliz?.test?.status,
    (value) => {
        if (value === 'pending' && timer === undefined) {
            polls = 0;
            timer = setInterval(() => {
                polls++;
                if (polls > 30) {
                    stopPolling();
                    return;
                }
                router.reload({ only: ['evoliz'] });
            }, 2000);
        } else if (value !== 'pending') {
            stopPolling();
        }
    },
    { immediate: true },
);
onBeforeUnmount(stopPolling);

function testConnection(): void {
    router.post('/admin/settings/evoliz/test', {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="evoliz === null ? t('Settings') : t('Evoliz settings')" back="/" />

        <section v-if="profileErrors?.length" class="mb-4 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" data-flow="settings-profile-errors">
            <p class="font-medium">{{ t('The installation profile does not fit the data (php artisan passorde:check-profile):') }}</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="error in profileErrors" :key="error">{{ error }}</li>
            </ul>
        </section>

        <p v-if="unreadable?.length" class="mb-4 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" data-flow="settings-unreadable">
            {{ t('Some saved values can no longer be read (the application key changed?) and count as not set: :keys. Enter them again.', { keys: unreadable.join(', ') }) }}
        </p>

        <p v-if="evoliz === null" class="rounded-xl border border-line bg-surface p-4 text-sm text-muted" data-flow="evoliz-off">
            {{ t('No add-on is switched on for this installation. The app runs on its own.') }}
        </p>

        <template v-else>
            <p
                v-if="status"
                class="mb-4 rounded-lg px-4 py-3 text-sm font-medium"
                :class="status.tone === 'ok' ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'"
                data-flow="evoliz-status"
            >
                {{ status.text }}
                <span v-for="item in evoliz.missing" :key="item" class="mt-1 block font-normal">{{ item }}</span>
            </p>

            <p class="mb-4 text-xs text-muted" data-flow="evoliz-notice">{{ t('Compatible with Evoliz. Not affiliated with Evoliz SAS / Visma.') }}</p>

            <section class="mb-4 rounded-xl border border-line bg-surface p-4" data-flow="evoliz-licence">
                <h2 class="mb-2 font-semibold">{{ t('Licence') }}</h2>
                <p v-if="evoliz.licence.reason" class="text-sm">{{ evoliz.licence.reason }}</p>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <template v-if="evoliz.licence.licensee">
                        <dt class="text-muted">{{ t('Licensee') }}</dt>
                        <dd>{{ evoliz.licence.licensee }}</dd>
                    </template>
                    <template v-if="evoliz.licence.plan">
                        <dt class="text-muted">{{ t('Plan') }}</dt>
                        <dd>{{ evoliz.licence.plan_label ?? evoliz.licence.plan }}</dd>
                    </template>
                    <template v-if="evoliz.licence.licence_id">
                        <dt class="text-muted">{{ t('Licence number') }}</dt>
                        <dd class="break-all">{{ evoliz.licence.licence_id }}</dd>
                    </template>
                    <template v-if="evoliz.licence.expires_at">
                        <dt class="text-muted">{{ t('Expires on') }}</dt>
                        <dd>{{ formatDay(evoliz.licence.expires_at) }}</dd>
                    </template>
                    <template v-if="evoliz.licence.updates_until">
                        <dt class="text-muted">{{ t('Updates until') }}</dt>
                        <dd>{{ formatDay(evoliz.licence.updates_until) }}</dd>
                    </template>
                    <template v-if="evoliz.licence.source">
                        <dt class="text-muted">{{ t('Kept in') }}</dt>
                        <dd>{{ t(evoliz.licence.source === 'database' ? 'this page (encrypted)' : 'the licence file of the installation') }}</dd>
                    </template>
                </dl>
            </section>

            <form class="space-y-4 rounded-xl border border-line bg-surface p-4" data-flow="evoliz-form" @submit.prevent="submit">
                <h2 class="font-semibold">{{ t('Evoliz') }}</h2>

                <div class="min-w-0">
                    <label :for="keyId" class="mb-1 block text-sm font-medium">{{ t('Licence key') }}</label>
                    <textarea
                        :id="keyId"
                        v-model="form.licence_key"
                        rows="3"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="evoliz.licence.source === 'database' ? t('Saved, paste a new key to replace it') : 'pck1.…'"
                        :aria-invalid="form.errors.licence_key ? 'true' : undefined"
                        class="block w-full min-w-0 rounded-lg border bg-surface px-3 py-2 font-mono text-sm break-all focus:border-accent focus:outline-2 focus:outline-accent/30"
                        :class="form.errors.licence_key ? 'border-red-600' : 'border-field'"
                    />
                    <p v-if="!form.errors.licence_key" class="mt-1 text-sm text-muted">{{ t('licence_key.help') }}</p>
                    <p v-else class="mt-1 text-sm text-red-700">{{ form.errors.licence_key }}</p>
                </div>

                <div>
                    <TextField
                        v-model="form.company_id"
                        :label="t('Evoliz company number')"
                        inputmode="numeric"
                        :placeholder="placeholder('company_id')"
                        :hint="sourceText('company_id') ?? t('company_id.help')"
                        :error="form.errors.company_id"
                    />
                    <CheckboxField v-if="evoliz.fields.company_id.source === 'database'" :model-value="clearChecked('company_id')" :label="t('Remove the value saved here')" @update:model-value="setClear('company_id', $event)" />
                </div>

                <div>
                    <TextField
                        v-model="form.public_key"
                        :label="t('Evoliz public key')"
                        :placeholder="placeholder('public_key')"
                        :hint="sourceText('public_key') ?? t('evoliz_keys.help')"
                        :error="form.errors.public_key"
                    />
                    <CheckboxField v-if="evoliz.fields.public_key.source === 'database'" :model-value="clearChecked('public_key')" :label="t('Remove the value saved here')" @update:model-value="setClear('public_key', $event)" />
                </div>

                <div>
                    <TextField
                        v-model="form.secret_key"
                        :label="t('Evoliz secret key')"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="placeholder('secret_key')"
                        :hint="sourceText('secret_key') ?? t('evoliz_secret.help')"
                        :error="form.errors.secret_key"
                    />
                    <CheckboxField v-if="evoliz.fields.secret_key.source === 'database'" :model-value="clearChecked('secret_key')" :label="t('Remove the value saved here')" @update:model-value="setClear('secret_key', $event)" />
                </div>

                <UiButton type="submit" :disabled="form.processing" data-flow="evoliz-save">{{ t('Save') }}</UiButton>
            </form>

            <section class="mt-4 rounded-xl border border-line bg-surface p-4" data-flow="evoliz-test">
                <UiButton variant="secondary" :disabled="evoliz.test?.status === 'pending'" @click="testConnection">{{ t('Test the connection') }}</UiButton>
                <p v-if="evoliz.test?.status === 'pending'" class="mt-2 text-sm text-muted">{{ t('Testing…') }}</p>
                <p v-else-if="evoliz.test" class="mt-2 text-sm" :class="evoliz.test.status === 'ok' ? 'text-emerald-800' : 'text-red-700'">
                    {{ evoliz.test.message }} <span class="text-muted">({{ formatDateTime(evoliz.test.at) }})</span>
                </p>
            </section>
        </template>
    </AppLayout>
</template>
