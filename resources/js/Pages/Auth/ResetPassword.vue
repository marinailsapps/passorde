<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import TextField from '@/components/TextField.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import UiButton from '@/components/UiButton.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ token: string; email: string }>();

const turnstile = ref<InstanceType<typeof TurnstileWidget> | null>(null);
const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
    turnstile_token: '',
});

function submit(): void {
    form.post('/reset-password', {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
            turnstile.value?.reset();
        },
    });
}
</script>

<template>
    <GuestLayout :title="t('New password')">
        <form class="space-y-4" @submit.prevent="submit">
            <TextField v-model="form.email" :label="t('Email')" type="email" autocomplete="username" :error="form.errors.email" required />
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
            <TurnstileWidget ref="turnstile" v-model="form.turnstile_token" :error="form.errors.turnstile_token" />
            <UiButton type="submit" block :disabled="form.processing">{{ t('Save the password') }}</UiButton>
        </form>
    </GuestLayout>
</template>
