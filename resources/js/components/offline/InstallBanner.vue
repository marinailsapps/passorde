<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import UiButton from '@/components/UiButton.vue';
import { brand } from '@/lib/brand';
import { t } from '@/lib/i18n';
import { INSTALL_BANNER_KEY, installPlatform, shouldShowInstallBanner, type InstallPlatform } from '@/offline/install';
import { isStandalone, pwa } from '@/offline/pwa';

/**
 * "Installer l'application": once per 24 h until the app runs installed.
 * Android uses the browser's install prompt; iOS Safari gets the steps
 * (Partager → Sur l'écran d'accueil).
 */
const visible = ref(false);
const help = ref(false);
const platform = computed<InstallPlatform | null>(() => installPlatform(navigator.userAgent, pwa.installPrompt !== null));

function lastShown(): number | null {
    try {
        const value = Number(localStorage.getItem(INSTALL_BANNER_KEY));
        return value > 0 ? value : null;
    } catch {
        return null;
    }
}

function consider(): void {
    if (visible.value || !shouldShowInstallBanner({ now: Date.now(), lastShown: lastShown(), standalone: isStandalone(), platform: platform.value })) return;
    visible.value = true;
    try {
        localStorage.setItem(INSTALL_BANNER_KEY, String(Date.now()));
    } catch {
        // Private mode: the banner may show again on the next start.
    }
}

onMounted(consider);
// Chrome may fire beforeinstallprompt after the page is shown.
watch(() => pwa.installPrompt, consider);

async function install(): Promise<void> {
    if (platform.value === 'ios') {
        help.value = true;
        return;
    }
    const prompt = pwa.installPrompt;
    visible.value = false;
    if (!prompt) return;
    await prompt.prompt();
    pwa.installPrompt = null;
}
</script>

<template>
    <div v-if="visible" data-flow="install-banner" class="mb-4 rounded-xl border border-accent/30 bg-accent-soft p-3" role="region" :aria-label="t('Install the app')">
        <div class="flex items-start gap-3">
            <img src="/icons/icon-192.png" alt="" class="size-12 shrink-0 rounded-lg border border-line bg-white">
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold">{{ t('Install the app') }}</p>
                <p class="text-muted">{{ t('Open it from the home screen and enter orders without network.') }}</p>
            </div>
            <button type="button" class="-mt-1 -mr-1 size-10 shrink-0 rounded-lg text-xl text-muted hover:bg-white/60" :aria-label="t('Close')" @click="visible = false">×</button>
        </div>
        <UiButton class="mt-3" block @click="install">{{ platform === 'ios' ? t('How to install?') : t('Install') }}</UiButton>
    </div>

    <BottomSheet :open="help" :title="t('Install on iPhone or iPad')" @close="help = false">
        <ol class="space-y-4">
            <li class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-line text-sky-600" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3m0 0L8 7m4-4 4 4" /><path d="M7 10H5v11h14V10h-2" /></svg>
                </span>
                <span>{{ t('1. Tap Share in the Safari toolbar.') }}</span>
            </li>
            <li class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-line text-ink" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="4" /><path d="M12 8v8M8 12h8" /></svg>
                </span>
                <span>{{ t('2. Choose "Add to Home Screen".') }}</span>
            </li>
            <li class="flex items-center gap-3">
                <img src="/icons/apple-touch-icon.png" alt="" class="size-10 shrink-0 rounded-lg border border-line">
                <span>{{ t('3. Tap Add, then open :brand from the home screen.', { brand: brand.short_name }) }}</span>
            </li>
        </ol>
        <UiButton class="mt-5" block @click="(help = false), (visible = false)">{{ t('OK') }}</UiButton>
    </BottomSheet>
</template>
