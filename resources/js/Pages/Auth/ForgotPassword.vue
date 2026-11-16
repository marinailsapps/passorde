<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import TextField from '@/components/TextField.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import UiButton from '@/components/UiButton.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineProps<{ status: string | null }>();

const turnstile = ref<InstanceType<typeof TurnstileWidget> | null>(null);
const form = useForm({ identifier: '', turnstile_token: '' });

function submit(): void {
    form.post('/forgot-password', { onFinish: () => turnstile.value?.reset() });
}
</script>

<template>
    <GuestLayout :title="t('Forgot password')">
        <p class="mb-4 text-sm text-muted">
            {{ t('Enter your login or email. If an email address is on file, you will receive a reset link valid for 60 minutes.') }}
        </p>
        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ status }}</p>
        <form class="space-y-4" @submit.prevent="submit">
            <TextField v-model="form.identifier" :label="t('Login or email')" autocomplete="username" :error="form.errors.identifier" required />
            <TurnstileWidget ref="turnstile" v-model="form.turnstile_token" :error="form.errors.turnstile_token" />
            <UiButton type="submit" block :disabled="form.processing">{{ t('Send the link') }}</UiButton>
        </form>
        <Link href="/login" class="mt-4 flex min-h-12 items-center justify-center text-sm text-accent">{{ t('Back to login') }}</Link>
    </GuestLayout>
</template>
