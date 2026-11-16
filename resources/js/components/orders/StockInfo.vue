<script setup lang="ts">
import { computed } from 'vue';

import { formatAge, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { LineArticle } from '@/lib/orderLines';

/**
 * "Stock Evoliz 7 · Réservé 3". Amber when stock − reserved does not cover the
 * quantity, red when Evoliz stock is 0 or below. `ownReserved` is this order's
 * own saved quantity, already counted in reserved_qty while editing.
 */
const props = withDefaults(defineProps<{ article: LineArticle; quantity: number; ownReserved?: number }>(), { ownReserved: 0 });

const stock = computed(() => Number(props.article.stock_qty ?? 0));
const reserved = computed(() => Math.max(0, Number(props.article.reserved_qty) - props.ownReserved));
const tone = computed(() => (stock.value <= 0 ? 'text-red-700' : stock.value - reserved.value < props.quantity ? 'text-amber-700' : 'text-muted'));
</script>

<template>
    <p v-if="article.is_stocked" class="text-sm" :class="tone">
        {{ t(isFileMode() ? 'Stock :stock · Reserved :reserved' : 'Evoliz stock :stock · Reserved :reserved', { stock: formatNumber(stock), reserved: formatNumber(reserved) }) }}
        <span v-if="article.stock_as_of" class="text-xs">({{ t('data :age', { age: formatAge(article.stock_as_of) }) }})</span>
    </p>
</template>
