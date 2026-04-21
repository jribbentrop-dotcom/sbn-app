<script setup lang="ts">
import { computed } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

interface Props {
    eurCents: number;
    size?: 'sm' | 'md' | 'lg';
    showToggle?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
    showToggle: false,
});

const { displayCurrency, formatEurCents, toggleCurrency } = useCurrency();

const formattedPrice = computed(() => formatEurCents(props.eurCents));

const sizeClasses = computed(() => ({
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-2xl font-bold',
}[props.size]));
</script>

<template>
    <div class="product-price" :class="sizeClasses">
        <span class="price-value">{{ formattedPrice }}</span>

        <button
            v-if="showToggle"
            class="currency-toggle"
            @click.prevent="toggleCurrency"
            :title="`Switch to ${displayCurrency === 'EUR' ? 'USD' : 'EUR'}`"
        >
            {{ displayCurrency === 'EUR' ? '€' : '$' }}
        </button>
    </div>
</template>

<style scoped>
.product-price {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--clr-accent-dim);
    font-weight: 600;
}

.currency-toggle {
    background: var(--clr-surface-3);
    border: none;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    color: var(--clr-text-dim);
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.currency-toggle:hover {
    background: var(--clr-accent);
    color: white;
}
</style>
