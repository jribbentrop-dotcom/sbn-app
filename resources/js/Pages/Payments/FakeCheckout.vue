<script setup lang="ts">
import { router, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

interface OrderItem {
    title: string;
    quantity: number;
}

const props = defineProps<{
    order: {
        token: string;
        total_cents: number;
        total_formatted: string;
        guest_email: string;
        items: OrderItem[];
    };
}>();

function pay() {
    router.post(`/payments/fake/checkout/${props.order.token}/pay`);
}
</script>

<template>
    <Head title="Test Checkout" />

    <div class="fake-checkout">
        <div class="fake-card">
            <p class="fake-badge">DEV · Fake provider</p>
            <h1>Confirm payment</h1>
            <p class="fake-email">{{ order.guest_email }}</p>

            <ul class="fake-items">
                <li v-for="(item, i) in order.items" :key="i">
                    <span>{{ item.title }}</span>
                    <span>× {{ item.quantity }}</span>
                </li>
            </ul>

            <div class="fake-total">
                <span>Total</span>
                <strong>{{ order.total_formatted }}</strong>
            </div>

            <button class="sbn-btn sbn-btn-primary" style="width:100%" @click="pay">
                Pay {{ order.total_formatted }}
            </button>
            <p class="fake-note">No real charge. Fires a signed webhook to test the grant pipeline.</p>
        </div>
    </div>
</template>

<style scoped>
.fake-checkout {
    display: flex;
    justify-content: center;
    padding: clamp(2rem, 6vw, 5rem) 1rem;
}
.fake-card {
    width: 100%;
    max-width: 400px;
    background: var(--clr-surface, #fff);
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: var(--radius-lg, 16px);
    padding: 32px;
}
.fake-badge {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--clr-accent-dim, #e67e22);
    margin: 0 0 12px;
}
.fake-email { color: var(--clr-text-muted); font-size: 13px; margin: 0 0 18px; }
.fake-items { list-style: none; padding: 0; margin: 0 0 16px; }
.fake-items li { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
.fake-total {
    display: flex; justify-content: space-between;
    padding: 12px 0; margin-bottom: 18px;
    border-top: 1px solid var(--clr-border);
    font-size: 16px;
}
.fake-note { font-size: 12px; color: var(--clr-text-muted); margin: 12px 0 0; text-align: center; }
</style>
