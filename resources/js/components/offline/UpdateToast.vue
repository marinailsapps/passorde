<script setup lang="ts">
import { t } from '@/lib/i18n';
import { pwa } from '@/offline/pwa';
</script>

<template>
    <div v-if="pwa.updateAvailable" class="fixed inset-x-4 bottom-24 z-30 mx-auto flex max-w-sm items-center gap-3 rounded-xl bg-ink px-4 py-3 text-sm text-white shadow-lg lg:bottom-6" role="status">
        <span class="flex-1">{{ t('New version available') }}</span>
        <button type="button" class="min-h-10 rounded-lg px-3 font-semibold text-emerald-300 hover:bg-white/10" @click="pwa.reload()">{{ t('Reload') }}</button>
    </div>
</template>
