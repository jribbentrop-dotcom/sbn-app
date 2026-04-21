import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const STORAGE_KEY = 'sbn.currency';

export type CurrencyCode = 'EUR' | 'USD';

// Reactive state
const displayCurrency = ref<CurrencyCode>('EUR');

// Load from localStorage on init
const loadFromStorage = () => {
    if (typeof window === 'undefined') return;

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored && (stored === 'EUR' || stored === 'USD')) {
            displayCurrency.value = stored as CurrencyCode;
        }
    } catch (e) {
        console.error('Failed to load currency from storage:', e);
    }
};

// Save to localStorage whenever currency changes
watch(displayCurrency, (newCurrency) => {
    if (typeof window === 'undefined') return;

    try {
        localStorage.setItem(STORAGE_KEY, newCurrency);
    } catch (e) {
        console.error('Failed to save currency to storage:', e);
    }
});

// Initialize
loadFromStorage();

// Format cents to currency string
const formatCents = (cents: number, currency?: CurrencyCode): string => {
    const code = currency || displayCurrency.value;
    const amount = cents / 100;

    if (code === 'EUR') {
        return new Intl.NumberFormat('de-DE', {
            style: 'currency',
            currency: 'EUR',
        }).format(amount);
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
};

// Convert EUR cents to display currency
const convertCents = (eurCents: number, currency?: CurrencyCode): number => {
    const code = currency || displayCurrency.value;

    if (code === 'EUR') {
        return eurCents;
    }

    // Get rate from Inertia shared props
    const page = usePage();
    const rate = (page.props.shop as { usd_rate?: number })?.usd_rate ?? 1.08;

    return Math.round(eurCents * rate);
};

// Format EUR cents to display currency
const formatEurCents = (eurCents: number, currency?: CurrencyCode): string => {
    const converted = convertCents(eurCents, currency);
    return formatCents(converted, currency);
};

// Toggle between currencies
const toggleCurrency = () => {
    displayCurrency.value = displayCurrency.value === 'EUR' ? 'USD' : 'EUR';
};

// Set specific currency
const setCurrency = (currency: CurrencyCode) => {
    if (currency === 'EUR' || currency === 'USD') {
        displayCurrency.value = currency;
    }
};

// Export composable
export function useCurrency() {
    return {
        displayCurrency,
        formatCents,
        convertCents,
        formatEurCents,
        toggleCurrency,
        setCurrency,
    };
}
