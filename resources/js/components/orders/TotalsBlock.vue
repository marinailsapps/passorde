<script setup lang="ts">
import { formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { fromCents, type Totals } from '@/lib/orderTotals';

defineProps<{ totals: Totals }>();
</script>

<template>
    <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-sm">
        <dt class="text-muted">{{ t('Total quantity') }}</dt>
        <dd class="text-right tabular-nums">{{ formatNumber(totals.quantity) }}</dd>
        <dt class="text-muted">{{ t('Total HT') }}</dt>
        <dd class="text-right tabular-nums">{{ formatMoney(fromCents(totals.htCents)) }}</dd>
        <template v-for="row in totals.byRate" :key="row.rate">
            <dt class="text-muted">{{ t('VAT :rate %', { rate: formatNumber(row.rate) }) }}</dt>
            <dd class="text-right tabular-nums">{{ formatMoney(fromCents(row.vatCents)) }}</dd>
        </template>
        <dt class="border-t border-line pt-1 text-base font-semibold">{{ t('Total TTC') }}</dt>
        <dd class="border-t border-line pt-1 text-right text-base font-semibold tabular-nums">{{ formatMoney(fromCents(totals.ttcCents)) }}</dd>
    </dl>
</template>
