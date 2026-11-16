<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import logo from '@brand/logo.png';
import { brand } from '@/lib/brand';
import InstallBanner from '@/components/offline/InstallBanner.vue';
import UpdateToast from '@/components/offline/UpdateToast.vue';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-dvh items-start justify-center px-4 py-10 sm:items-center">
        <div class="w-full max-w-sm">
            <img :src="logo" :alt="brand.short_name" class="mx-auto mb-6 h-20 w-auto">
            <InstallBanner />
            <slot name="before" />
            <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                <h1 class="mb-4 text-lg font-semibold">{{ title }}</h1>
                <slot />
            </div>
        </div>
        <UpdateToast />
    </div>
</template>
