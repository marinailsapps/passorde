<script setup lang="ts">
import BottomSheet from '@/components/BottomSheet.vue';
import UiButton from '@/components/UiButton.vue';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ open: boolean; title: string; message: string; confirmLabel: string; danger?: boolean; busy?: boolean }>(), {
    danger: false,
    busy: false,
});
const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <BottomSheet :open="open" :title="title" @close="emit('cancel')">
        <p class="mb-4 text-muted">{{ message }}</p>
        <div class="grid gap-2 sm:grid-cols-2">
            <UiButton variant="secondary" @click="emit('cancel')">{{ t('Cancel') }}</UiButton>
            <UiButton :variant="danger ? 'danger' : 'primary'" data-flow="dialog-confirm" :disabled="busy" @click="emit('confirm')">{{ confirmLabel }}</UiButton>
        </div>
    </BottomSheet>
</template>
