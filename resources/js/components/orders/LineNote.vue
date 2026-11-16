<script setup lang="ts">
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';

/** A comment on an order line: one line high, like the fields beside it; grows with the text. */
const note = defineModel<string>({ required: true });
</script>

<template>
    <textarea
        v-model="note"
        rows="1"
        maxlength="255"
        :aria-label="t('Comment on this line')"
        :placeholder="isFileMode() ? t('Comment on the line') : t('Comment on the delivery note')"
        data-flow="line-note"
        class="block max-h-32 min-h-12 w-full resize-none rounded-lg border border-field bg-surface px-3 py-[11px] text-base field-sizing-content focus:outline-2 focus:outline-accent/30"
    />
</template>
