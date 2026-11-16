<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';

import PageHeader from '@/components/PageHeader.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { logout } from '@/offline/logout';

defineProps<{ lastSyncs: Record<string, string | null> }>();

const page = usePage();
const form = useForm({ current_password: '', password: '', password_confirmation: '' });

function submit(): void {
    form.put('/account/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('current_password'),
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="t('My account')" back="/" />

        <section class="rounded-xl border border-line bg-surface p-4">
            <h2 class="mb-3 font-semibold">{{ t('Change my password') }}</h2>
            <form class="space-y-4" @submit.prevent="submit">
                <TextField
                    v-model="form.current_password"
                    :label="t('Current password')"
                    type="password"
                    autocomplete="current-password"
                    :error="form.errors.current_password"
                    required
                />
                <TextField
                    v-model="form.password"
                    :label="t('New password')"
                    type="password"
                    autocomplete="new-password"
                    :hint="t('At least 12 characters.')"
                    :error="form.errors.password"
                    required
                />
                <TextField
                    v-model="form.password_confirmation"
                    :label="t('Confirm the password')"
                    type="password"
                    autocomplete="new-password"
                    required
                />
                <UiButton type="submit" :disabled="form.processing">{{ t('Save the password') }}</UiButton>
            </form>
        </section>

        <section class="mt-4 rounded-xl border border-line bg-surface p-4 text-sm">
            <h2 class="mb-2 font-semibold">{{ t('About') }}</h2>
            <dl class="grid grid-cols-2 gap-y-1">
                <dt class="text-muted">{{ t('Version') }}</dt>
                <dd>{{ page.props.appVersion }}</dd>
                <template v-for="(value, kind) in lastSyncs" :key="kind">
                    <dt class="text-muted">{{ t(`Last sync (${kind})`) }}</dt>
                    <dd>{{ formatDateTime(value) }}</dd>
                </template>
            </dl>
        </section>

        <UiButton variant="secondary" block class="mt-4" @click="logout">{{ t('Log out') }}</UiButton>
    </AppLayout>
</template>
