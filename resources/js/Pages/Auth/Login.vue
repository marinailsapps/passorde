<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import TextField from '@/components/TextField.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import StrandedOrders from '@/components/offline/StrandedOrders.vue';
import UiButton from '@/components/UiButton.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

defineProps<{ status: string | null }>();

const flashError = computed(() => usePage().props.flash.error);
const turnstile = ref<InstanceType<typeof TurnstileWidget> | null>(null);
const form = useForm({ identifier: '', password: '', turnstile_token: '' });

function submit(): void {
    form.post('/login', {
        onFinish: () => {
            form.reset('password');
            turnstile.value?.reset();
        },
    });
}
</script>

<template>
    <GuestLayout :title="t('Log in')">
        <template #before>
            <StrandedOrders />
        </template>
        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ status }}</p>
        <p v-if="flashError" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">{{ flashError }}</p>
        <form class="space-y-4" @submit.prevent="submit">
            <TextField
                v-model="form.identifier"
                data-flow="login-identifier"
                :label="t('Login or email')"
                autocomplete="username"
                :error="form.errors.identifier"
                required
            />
            <TextField
                v-model="form.password"
                data-flow="login-password"
                :label="t('Password')"
                type="password"
                autocomplete="current-password"
                :error="form.errors.password"
                required
            />
            <TurnstileWidget ref="turnstile" v-model="form.turnstile_token" :error="form.errors.turnstile_token" />
            <UiButton type="submit" block data-flow="login-submit" :disabled="form.processing">{{ t('Log in') }}</UiButton>
        </form>
        <Link href="/forgot-password" class="mt-4 flex min-h-12 items-center justify-center text-sm text-accent">
            {{ t('Forgot your password?') }}
        </Link>
    </GuestLayout>
</template>
