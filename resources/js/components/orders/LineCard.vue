<script setup lang="ts">
import { computed } from 'vue';

import LineNote from '@/components/orders/LineNote.vue';
import QuantityStepper from '@/components/orders/QuantityStepper.vue';
import StockInfo from '@/components/orders/StockInfo.vue';
import { formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { LineEditor, LineGroup } from '@/lib/orderLines';
import { fromCents, lineHtCents } from '@/lib/orderTotals';

const props = defineProps<{ group: LineGroup; editor: LineEditor; first: boolean; last: boolean; ownReserved: number }>();

const lineTotal = computed(() => fromCents(lineHtCents(props.group.quantity, props.group.unitPrice)));
const priceNeedsAttention = computed(() => !props.group.priceEdited && Number(props.group.unitPrice) === 0);
// Copied orders keep their negotiated price; offer today's catalogue price when it differs.
const catalogPrice = computed(() => Number(props.group.article.unit_price_ht));
const showCatalogHint = computed(() => catalogPrice.value > 0 && Math.round(catalogPrice.value * 100) !== Math.round(Number(props.group.unitPrice) * 100));

const quantity = computed({
    get: () => props.group.quantity,
    set: (value: number) => props.editor.setQuantity(props.group.key, value),
});
const freeQuantity = computed({
    get: () => props.group.freeQuantity,
    set: (value: number) => props.editor.setFreeQuantity(props.group.key, value),
});
const note = computed({
    get: () => props.group.note ?? '',
    set: (value: string) => props.editor.setNote(props.group.key, value),
});
const freeNote = computed({
    get: () => props.group.freeNote ?? '',
    set: (value: string) => props.editor.setNote(props.group.key, value, true),
});
</script>

<template>
    <li class="rounded-xl border border-line bg-surface p-3" data-flow="line-card">
        <div class="flex items-start gap-2">
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ group.article.reference }}</p>
                <p class="text-sm text-muted">{{ group.article.designation }}</p>
            </div>
            <div class="flex shrink-0">
                <button type="button" class="size-12 rounded-lg text-lg text-muted hover:bg-page disabled:opacity-30" :disabled="first" :aria-label="t('Move up')" @click="editor.move(group.key, -1)">↑</button>
                <button type="button" class="size-12 rounded-lg text-lg text-muted hover:bg-page disabled:opacity-30" :disabled="last" :aria-label="t('Move down')" @click="editor.move(group.key, 1)">↓</button>
            </div>
        </div>

        <!-- The comment takes the free room in this row, so the card does not grow; on phones it gets its own row. -->
        <div class="mt-3 flex flex-wrap items-end gap-3 sm:flex-nowrap">
            <QuantityStepper v-model="quantity" data-flow="line-quantity" :label="t('Quantity')" :min="1" />
            <label class="block">
                <span class="mb-1 block text-xs text-muted">{{ t('Unit price HT') }}</span>
                <input
                    :value="String(group.unitPrice).replace('.', ',')"
                    type="text"
                    inputmode="decimal"
                    class="block h-12 w-28 rounded-lg border px-3 text-right text-base tabular-nums focus:outline-2 focus:outline-accent/30"
                    :class="priceNeedsAttention ? 'border-amber-500 bg-amber-50' : 'border-field bg-surface'"
                    @input="editor.setPrice(group.key, ($event.target as HTMLInputElement).value.replace(',', '.'))"
                >
            </label>
            <div class="order-last w-full sm:order-none sm:w-auto sm:min-w-0 sm:flex-1">
                <LineNote v-model="note" />
            </div>
            <div class="ml-auto shrink-0 text-right">
                <p class="text-xs text-muted">{{ t('VAT :rate %', { rate: formatNumber(group.article.vat_rate) }) }}</p>
                <p class="font-semibold tabular-nums">{{ formatMoney(lineTotal) }}</p>
            </div>
        </div>
        <p v-if="priceNeedsAttention" class="mt-1 text-sm text-amber-800">{{ t('The catalogue price is 0 €: type the price.') }}</p>
        <button
            v-else-if="showCatalogHint"
            type="button"
            class="mt-1 min-h-10 rounded-lg px-2 text-sm text-accent underline-offset-2 hover:underline"
            :title="t('Apply the catalogue price')"
            @click="editor.setPrice(group.key, group.article.unit_price_ht)"
        >
            {{ t('Catalogue price: :price', { price: formatMoney(group.article.unit_price_ht) }) }}
        </button>

        <StockInfo class="mt-2" :article="group.article" :quantity="group.quantity + group.freeQuantity" :own-reserved="ownReserved" />

        <div v-if="group.freeQuantity > 0" class="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-accent-soft/60 py-2 pr-2 pl-3 sm:flex-nowrap">
            <span class="flex-1 text-sm font-medium sm:flex-none">↳ {{ t('Free lines') }} <span class="text-muted">· 0,00 €</span></span>
            <div class="order-last w-full sm:order-none sm:w-auto sm:min-w-0 sm:flex-1">
                <LineNote v-model="freeNote" />
            </div>
            <QuantityStepper v-model="freeQuantity" :label="t('Free lines')" />
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="min-h-12 rounded-lg px-3 font-medium text-accent hover:bg-accent-soft" data-flow="line-free" @click="editor.addFree(group.key)">
                {{ t('+ free line') }}
            </button>
            <span class="flex-1" />
            <button type="button" class="min-h-12 rounded-lg px-3 text-red-700 hover:bg-red-50" @click="editor.remove(group.key)">{{ t('Delete') }}</button>
        </div>
    </li>
</template>
