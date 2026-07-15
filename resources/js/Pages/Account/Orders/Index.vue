<script setup lang="ts">
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

interface OrderRow {
    id: number;
    token: string;
    status: string;
    total_formatted: string;
    created_at: string | null;
    item_count: number;
}

defineProps<{ orders: OrderRow[] }>();

function formatDate(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString();
}
</script>

<template>
    <Head><title>My Orders | Soul Bossa Nova</title></Head>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <h1>Orders</h1>
            </header>

            <div v-if="orders.length === 0" class="sbn-account-empty">
                No orders yet.
            </div>

            <table v-else class="sbn-account-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in orders" :key="o.id">
                        <td>{{ formatDate(o.created_at) }}</td>
                        <td>{{ o.item_count }}</td>
                        <td>{{ o.total_formatted }}</td>
                        <td><span class="sbn-account-status" :class="`is-${o.status}`">{{ o.status }}</span></td>
                        <td><Link :href="`/account/orders/${o.token}`" class="sbn-account-section-link">Details →</Link></td>
                    </tr>
                </tbody>
            </table>
    </div>
</template>
