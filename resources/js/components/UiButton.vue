<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        href?: string;
        type?: 'button' | 'submit';
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', href: undefined, type: 'button', disabled: false, block: false },
);

const classes = computed(() => [
    'inline-flex min-h-12 items-center justify-center gap-2 rounded-lg px-4 text-base font-medium transition-colors',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50',
    props.block ? 'w-full' : '',
    {
        primary: 'bg-accent text-white hover:bg-accent-strong',
        secondary: 'border border-line bg-surface text-ink hover:bg-page',
        danger: 'bg-red-700 text-white hover:bg-red-800',
        ghost: 'text-accent hover:bg-accent-soft',
    }[props.variant],
]);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
