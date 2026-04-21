import type { CartItem, Product } from '@/types/shop';
import { computed, ref, watch } from 'vue';

const STORAGE_KEY = 'sbn.cart';

// Reactive state (module-level singleton)
const items = ref<CartItem[]>([]);
const isOpen = ref(false);

// Load from localStorage on init
const loadFromStorage = () => {
    if (typeof window === 'undefined') return;

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            items.value = JSON.parse(stored);
        }
    } catch (e) {
        console.error('Failed to load cart from storage:', e);
    }
};

// Save to localStorage whenever items change
watch(
    items,
    (newItems) => {
        if (typeof window === 'undefined') return;

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(newItems));
        } catch (e) {
            console.error('Failed to save cart to storage:', e);
        }
    },
    { deep: true }
);

// Initialize
loadFromStorage();

// Computed values
const count = computed(() => items.value.reduce((sum, item) => sum + item.quantity, 0));

const subtotalCents = computed(() =>
    items.value.reduce((sum, item) => sum + item.price_cents * item.quantity, 0)
);

// Actions
const addToCart = (product: Product, quantity: number = 1) => {
    const existingItem = items.value.find((item) => item.product_id === product.id);

    if (existingItem) {
        existingItem.quantity += quantity;
    } else {
        items.value.push({
            product_id: product.id,
            slug: product.slug,
            title: product.title,
            price_cents: product.price_cents,
            thumbnail_path: product.thumbnail_url ? new URL(product.thumbnail_url).pathname : null,
            quantity,
        });
    }

    // Open cart drawer to show user what happened
    isOpen.value = true;
};

const removeFromCart = (productId: number) => {
    const index = items.value.findIndex((item) => item.product_id === productId);
    if (index > -1) {
        items.value.splice(index, 1);
    }
};

const setQuantity = (productId: number, quantity: number) => {
    if (quantity < 1) {
        removeFromCart(productId);
        return;
    }

    const item = items.value.find((item) => item.product_id === productId);
    if (item) {
        item.quantity = quantity;
    }
};

const clearCart = () => {
    items.value = [];
};

const openCart = () => {
    isOpen.value = true;
};

const closeCart = () => {
    isOpen.value = false;
};

const toggleCart = () => {
    isOpen.value = !isOpen.value;
};

// Export composable
export function useCart() {
    return {
        // State
        items,
        isOpen,

        // Computed
        count,
        subtotalCents,

        // Actions
        addToCart,
        removeFromCart,
        setQuantity,
        clearCart,
        openCart,
        closeCart,
        toggleCart,
    };
}
