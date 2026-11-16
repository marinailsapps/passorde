<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import { t } from '@/lib/i18n';

const page = usePage();
const flash = computed(() => page.props.flash);
const copied = ref(false);

watch(flash, () => (copied.value = false));

async function copyPassword(value: string): Promise<void> {
    await navigator.clipboard?.writeText(value);
    copied.value = true;
}
</script>

<template>
    <div class="space-y-2" aria-live="polite">
        <p v-if="flash.success" class="rounded-lg bg-emerald-50 px-4 py-3 text-emerald-900">{{ flash.success }}</p>
        <p v-if="flash.error" class="rounded-lg bg-red-50 px-4 py-3 text-red-900">{{ flash.error }}</p>
        <div v-if="flash.password" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
            <p class="text-sm text-amber-900">
                {{ t('New password for :name. It is shown only once: write it down or copy it now.', { name: flash.password.user }) }}
            </p>
            <div class="mt-2 flex items-center gap-2">
                <code class="flex-1 rounded bg-white px-3 py-2 font-mono text-lg break-all select-all">{{ flash.password.value }}</code>
                <button type="button" class="min-h-12 rounded-lg px-3 text-accent hover:bg-accent-soft" @click="copyPassword(flash.password.value)">
                    {{ copied ? t('Copied') : t('Copy') }}
                </button>
            </div>
        </div>
    </div>
</template>
