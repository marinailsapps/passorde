<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ title: string; back?: string }>();
</script>

<template>
    <Head :title="title" />
    <div class="mb-4 flex flex-wrap items-center gap-x-2 gap-y-1">
        <Link v-if="back" :href="back" class="-ml-2 inline-flex size-12 items-center justify-center rounded-lg text-muted hover:bg-surface print:hidden" aria-label="Retour">
            <svg viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M12.8 4.2a.75.75 0 0 1 0 1.06L8.06 10l4.74 4.74a.75.75 0 1 1-1.06 1.06l-5.27-5.27a.75.75 0 0 1 0-1.06l5.27-5.27a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
        </Link>
        <!-- Long titles wrap, and the actions (a status chip) go to the next line, rather than cutting the order number. -->
        <h1 class="min-w-0 flex-auto text-xl font-semibold break-words">{{ title }}</h1>
        <slot name="actions" />
    </div>
</template>
