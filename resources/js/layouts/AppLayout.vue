<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import FlashMessages from '@/components/FlashMessages.vue';
import LicenceBanner from '@/components/LicenceBanner.vue';
import InstallBanner from '@/components/offline/InstallBanner.vue';
import SyncPill from '@/components/offline/SyncPill.vue';
import UpdateToast from '@/components/offline/UpdateToast.vue';
import SyncMismatchBanner from '@/components/SyncMismatchBanner.vue';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { brand } from '@/lib/brand';
import { t } from '@/lib/i18n';
import { logout } from '@/offline/logout';

withDefaults(defineProps<{ wide?: boolean }>(), { wide: false });

const page = usePage();
const user = computed(() => page.props.auth.user);

// --header-height: what sticks under the header (a tab bar, a selection bar)
// sits below it, whether or not the test-mode banner makes it taller.
const header = ref<HTMLElement | null>(null);
let observer: ResizeObserver | undefined;
onMounted(() => {
    if (!header.value || typeof ResizeObserver === 'undefined') return;
    observer = new ResizeObserver(() => document.documentElement.style.setProperty('--header-height', `${header.value?.offsetHeight ?? 56}px`));
    observer.observe(header.value);
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="min-h-dvh">
        <header ref="header" class="sticky top-0 z-10 border-b border-line bg-surface/95 pt-[env(safe-area-inset-top)] backdrop-blur print:hidden">
            <div class="mx-auto flex h-14 max-w-3xl items-center gap-2 px-4" :class="{ 'lg:max-w-6xl': wide }">
                <Link href="/" class="flex min-h-12 shrink-0 items-center text-lg font-semibold tracking-tight">{{ brand.short_name }}</Link>
                <SyncPill v-if="user" />
                <span class="flex-1" />
                <Link v-if="user" href="/account" class="flex min-h-12 min-w-0 shrink-0 items-center rounded-lg px-1 text-sm hover:bg-page sm:shrink sm:px-3" :aria-label="user.name">
                    <!-- Phones: an initial, so the sync pill keeps its room. -->
                    <span class="flex size-9 items-center justify-center rounded-full bg-accent-soft font-semibold text-accent sm:hidden" aria-hidden="true">{{ user.name.charAt(0).toUpperCase() }}</span>
                    <span class="hidden truncate sm:inline">{{ user.name }}</span>
                </Link>
                <!-- On phones, "Se déconnecter" lives on the account page. -->
                <button v-if="user" type="button" class="hidden min-h-12 shrink-0 rounded-lg px-3 text-sm whitespace-nowrap text-muted hover:bg-page sm:block" @click="logout">
                    {{ t('Log out') }}
                </button>
            </div>
            <TestModeBanner />
            <SyncMismatchBanner />
            <LicenceBanner />
        </header>
        <main class="mx-auto max-w-3xl px-4 py-4 print:max-w-none print:box-decoration-clone print:px-[14mm] print:py-[12mm]" :class="{ 'lg:max-w-6xl': wide }">
            <div class="print:hidden">
                <InstallBanner />
                <FlashMessages class="mb-4" />
            </div>
            <slot />
        </main>
        <div class="print:hidden">
            <UpdateToast />
        </div>
    </div>
</template>
