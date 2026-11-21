<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectField from '@/components/SelectField.vue';
import TextField from '@/components/TextField.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { generatePassword } from '@/lib/password';

interface EditableUser {
    id: number;
    name: string;
    login: string;
    email: string | null;
    phone: string | null;
    role: 'user' | 'admin';
    is_active: boolean;
}

const props = defineProps<{ user: EditableUser | null }>();

const isNew = computed(() => props.user === null);
const roles = [
    { value: 'user', label: t('Sales') },
    { value: 'admin', label: t('Admin') },
];

const form = useForm({
    name: props.user?.name ?? '',
    login: props.user?.login ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    role: props.user?.role ?? 'user',
    is_active: props.user?.is_active ?? true,
    password: '',
});

const passwordForm = useForm({ password: '' });

function save(): void {
    if (props.user === null) {
        form.post('/admin/users');
    } else {
        form.put(`/admin/users/${props.user.id}`, { preserveScroll: true });
    }
}

function setPassword(): void {
    if (props.user === null) return;
    // No preserveScroll: the one-time password is shown at the top of the page.
    passwordForm.put(`/admin/users/${props.user.id}/password`, {
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="isNew ? t('New user') : user!.name" back="/admin/users" />

        <form class="space-y-4 rounded-xl border border-line bg-surface p-4" @submit.prevent="save">
            <TextField v-model="form.name" :label="t('Name')" autocomplete="name" :error="form.errors.name" required />
            <TextField
                v-model="form.login"
                :label="t('Login')"
                :hint="t('Short handle used to log in, e.g. marie.')"
                autocomplete="off"
                :error="form.errors.login"
                required
            />
            <TextField v-model="form.email" :label="t('Email (optional)')" type="email" inputmode="email" :error="form.errors.email" />
            <TextField v-model="form.phone" :label="t('Phone')" type="tel" inputmode="tel" :error="form.errors.phone" />
            <SelectField v-model="form.role" :label="t('Role')" :options="roles" :error="form.errors.role" />
            <CheckboxField v-model="form.is_active" :label="t('Active account')" :error="form.errors.is_active" />

            <div v-if="isNew">
                <TextField
                    v-model="form.password"
                    :label="t('Password')"
                    :hint="t('At least 12 characters. Shown once after saving.')"
                    :error="form.errors.password"
                    required
                />
                <UiButton variant="ghost" class="mt-1" @click="form.password = generatePassword()">{{ t('Generate') }}</UiButton>
            </div>

            <UiButton type="submit" block :disabled="form.processing">{{ isNew ? t('Create the user') : t('Save') }}</UiButton>
        </form>

        <form v-if="!isNew" data-flow="set-password" class="mt-4 space-y-3 rounded-xl border border-line bg-surface p-4" @submit.prevent="setPassword">
            <h2 class="font-semibold">{{ t('Set a password') }}</h2>
            <p class="text-sm text-muted">{{ t('The user is signed out of all devices. The password is shown once after saving and never emailed.') }}</p>
            <TextField v-model="passwordForm.password" :label="t('New password')" :error="passwordForm.errors.password" required />
            <div class="flex flex-wrap gap-2">
                <UiButton variant="secondary" @click="passwordForm.password = generatePassword()">{{ t('Generate') }}</UiButton>
                <UiButton type="submit" :disabled="passwordForm.processing">{{ t('Set the password') }}</UiButton>
            </div>
        </form>
    </AppLayout>
</template>
