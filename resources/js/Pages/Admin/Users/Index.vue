<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import PageHeader from '@/components/PageHeader.vue';
import StatusChip from '@/components/StatusChip.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

interface UserRow {
    id: number;
    name: string;
    login: string;
    email: string | null;
    phone: string | null;
    role: 'user' | 'admin';
    is_active: boolean;
    last_login_at: string | null;
}

defineProps<{ users: UserRow[] }>();
</script>

<template>
    <AppLayout>
        <PageHeader :title="t('Users')" back="/">
            <template #actions>
                <UiButton href="/admin/users/create">{{ t('Add') }}</UiButton>
            </template>
        </PageHeader>
        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="user in users" :key="user.id">
                <Link :href="`/admin/users/${user.id}/edit`" class="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-page">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium" :class="{ 'text-muted': !user.is_active }">{{ user.name }}</p>
                        <p class="text-sm break-words text-muted">
                            {{ user.login }}<template v-if="user.email"> · {{ user.email }}</template>
                        </p>
                        <p class="text-xs text-muted">{{ t('Last login: :date', { date: formatDateTime(user.last_login_at) }) }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <StatusChip v-if="user.role === 'admin'" tone="info">{{ t('Admin') }}</StatusChip>
                        <StatusChip v-if="!user.is_active" tone="danger">{{ t('Deactivated') }}</StatusChip>
                    </div>
                </Link>
            </li>
        </ul>
    </AppLayout>
</template>
