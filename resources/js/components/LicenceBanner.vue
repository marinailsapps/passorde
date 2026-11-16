<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { t } from '@/lib/i18n';

// Admins only (the props are null for sellers and with Evoliz off): amber in grace or with a key missing,
// red once the licence stops Evoliz. Either way the app runs standalone (export, import, catalogue edits).
const SETTINGS_PAGE = '/admin/settings';
const page = usePage();
const licence = computed(() => page.props.syncLicence ?? null);
const keysMissing = computed(() => page.props.evolizState?.reason === 'credentials');
const blocked = computed(() => licence.value !== null && (licence.value.state === 'expired' || licence.value.state === 'invalid'));
</script>

<template>
    <p v-if="licence?.state === 'grace'" class="bg-amber-100 px-4 py-1 text-center text-xs font-medium text-amber-900" role="status" data-flow="licence-grace">
        {{ t('Passorde licence:') }} {{ licence.reason }}
        <Link :href="SETTINGS_PAGE" class="underline">{{ t('Evoliz settings') }}</Link>
    </p>
    <p v-else-if="blocked" class="bg-red-700 px-4 py-1 text-center text-xs font-medium text-white" role="alert" data-flow="licence-blocked">
        {{ t(licence?.state === 'expired' ? 'Passorde licence expired: nothing is sent to Evoliz.' : 'Passorde licence invalid: nothing is sent to Evoliz.') }}
        {{ licence?.reason }}
        {{ t('The app runs on its own: approved orders are exported.') }}
        {{ licence?.renew }}
        <Link :href="SETTINGS_PAGE" class="underline">{{ t('Evoliz settings') }}</Link>
    </p>
    <p v-else-if="keysMissing" class="bg-amber-100 px-4 py-1 text-center text-xs font-medium text-amber-900" role="status" data-flow="evoliz-keys-missing">
        {{ t('Evoliz keys incomplete: nothing is sent to Evoliz') }}.
        {{ t('The app runs on its own: approved orders are exported.') }}
        <Link :href="SETTINGS_PAGE" class="underline">{{ t('Evoliz settings') }}</Link>
    </p>
</template>
