<script setup lang="ts">
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

interface OrderItem { title: string; quantity: number }
interface Download { token: string; product_id: number; expires_at: string | null }

defineProps<{
    order: {
        id: number;
        token: string;
        status: string;
        total_formatted: string;
        created_at: string | null;
        items: OrderItem[];
        downloads: Download[];
    };
}>();
</script>

<template>
    <Head><title>Order #{{ order.id }} | Soul Bossa Nova</title></Head>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <Link href="/account/orders" class="sbn-account-section-link">← All orders</Link>
                <h1>Order {{ order.token }}</h1>
                <p class="sbn-account-subtle">{{ order.total_formatted }} · {{ order.status }}</p>
            </header>

            <section class="sbn-account-section">
                <h2>Items</h2>
                <ul class="sbn-account-list">
                    <li v-for="(i, idx) in order.items" :key="idx">{{ i.quantity }} × {{ i.title }}</li>
                </ul>
            </section>

            <section v-if="order.downloads.length" class="sbn-account-section">
                <h2>Downloads</h2>
                <ul class="sbn-account-list">
                    <li v-for="d in order.downloads" :key="d.token">
                        <a :href="`/shop/download/${d.token}/${d.product_id}`">Download</a>
                        <span v-if="d.expires_at" class="sbn-account-subtle"> · expires {{ d.expires_at }}</span>
                    </li>
                </ul>
            </section>
    </div>
</template>
