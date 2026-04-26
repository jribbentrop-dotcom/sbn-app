<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { Order, DownloadLink } from '@/types/shop';

interface Props {
    order: Order;
    downloadLinks: DownloadLink[];
    meta: {
        title: string;
        description: string;
    };
}

const props = defineProps<Props>();
</script>

<template>
    <Head>
        <title>{{ meta.title }}</title>
        <meta name="description" :content="meta.description" />
    </Head>

    <div class="success-page">
            <div class="success-header">
                <div class="success-icon">✓</div>
                <h1 class="success-title">Thank You for Your Order!</h1>
                <p class="success-message">
                    A confirmation email has been sent to {{ order.guest_email }}
                </p>
            </div>

            <div class="order-details">
                <h2>Order Details</h2>

                <div class="detail-row">
                    <span>Order Number</span>
                    <strong>#{{ order.id }}</strong>
                </div>

                <div class="detail-row">
                    <span>Date</span>
                    <strong>{{ new Date(order.created_at).toLocaleDateString() }}</strong>
                </div>

                <div class="detail-row">
                    <span>Total</span>
                    <strong>{{ order.total_formatted }}</strong>
                </div>

                <div class="detail-row">
                    <span>Status</span>
                    <span class="status-badge pending">{{ order.status }}</span>
                </div>
            </div>

            <div class="order-items">
                <h2>Order Items</h2>

                <ul class="items-list">
                    <li
                        v-for="item in order.items"
                        :key="item.title"
                        class="item-row"
                    >
                        <div class="item-info">
                            <h3>{{ item.title }}</h3>
                            <span>Qty: {{ item.quantity }}</span>
                        </div>
                        <span class="item-price">
                            {{ order.display_currency === 'EUR' ? '€' : '$' }}
                            {{ (item.price_cents / 100).toFixed(2) }}
                        </span>
                    </li>
                </ul>
            </div>

            <div v-if="downloadLinks.length > 0" class="download-section">
                <h2>Your Downloads</h2>

                <div class="download-notice">
                    <p>
                        Your download links are valid for 7 days or 5 downloads, whichever comes first.
                    </p>
                </div>

                <div class="download-list">
                    <div
                        v-for="link in downloadLinks"
                        :key="link.token"
                        class="download-item"
                        :class="{ expired: !link.is_valid }"
                    >
                        <div class="download-info">
                            <h3>{{ link.product_title }}</h3>
                            <span v-if="link.is_valid" class="download-meta">
                                {{ link.downloads_remaining }} downloads remaining
                            </span>
                            <span v-else class="download-meta expired">
                                Link expired
                            </span>
                        </div>

                        <a
                            v-if="link.is_valid"
                            :href="link.download_url"
                            class="download-btn"
                            download
                        >
                            Download PDF
                        </a>
                        <span v-else class="download-btn disabled">
                            Unavailable
                        </span>
                    </div>
                </div>
            </div>

            <div class="actions">
                <Link href="/shop" class="continue-btn">
                    Continue Shopping
                </Link>
            </div>
    </div>
</template>

<style scoped>
.success-page {
    max-width: 700px;
    margin: 0 auto;
    padding: 40px 20px;
}

.success-header {
    text-align: center;
    margin-bottom: 40px;
}

.success-icon {
    width: 80px;
    height: 80px;
    background: var(--clr-success);
    color: var(--clr-white);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    margin: 0 auto 24px;
}

.success-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 12px;
    color: var(--clr-text);
}

.success-message {
    font-size: 16px;
    color: var(--clr-text-dim);
    margin: 0;
}

.order-details,
.order-items,
.download-section {
    background: var(--clr-white);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 24px;
}

.order-details h2,
.order-items h2,
.download-section h2 {
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 20px;
    color: var(--clr-text);
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--clr-border);
    font-size: 15px;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-row span:first-child {
    color: var(--clr-text-dim);
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.pending {
    background: color-mix(in srgb, var(--clr-warning) 15%, var(--clr-white));
    color: var(--clr-warning);
}

.items-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    border-bottom: 1px solid var(--clr-border);
}

.item-row:last-child {
    border-bottom: none;
}

.item-info h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 4px;
    color: var(--clr-text);
}

.item-info span {
    font-size: 13px;
    color: var(--clr-text-muted);
}

.item-price {
    font-weight: 700;
    color: var(--clr-accent-dim);
}

.download-notice {
    background: var(--clr-accent-bg);
    border: 1px solid var(--clr-accent-border);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 20px;
}

.download-notice p {
    margin: 0;
    font-size: 14px;
    color: var(--clr-text-dim);
}

.download-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.download-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: var(--clr-surface-2);
    border-radius: var(--radius-sm);
}

.download-item.expired {
    opacity: 0.6;
}

.download-info h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 4px;
    color: var(--clr-text);
}

.download-meta {
    font-size: 13px;
    color: var(--clr-text-muted);
}

.download-meta.expired {
    color: var(--clr-red);
}

.download-btn {
    background: var(--clr-gradient);
    color: var(--clr-white);
    text-decoration: none;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    transition: transform 0.2s var(--ease);
}

.download-btn:hover:not(.disabled) {
    transform: scale(1.02);
}

.download-btn.disabled {
    background: var(--clr-surface-3);
    color: var(--clr-text-muted);
    cursor: not-allowed;
}

.actions {
    text-align: center;
    margin-top: 32px;
}

.continue-btn {
    display: inline-block;
    background: var(--clr-gradient);
    color: var(--clr-white);
    text-decoration: none;
    padding: 14px 28px;
    border-radius: var(--radius-sm);
    font-weight: 700;
    transition: transform 0.2s var(--ease);
}

.continue-btn:hover {
    transform: scale(1.02);
}
</style>
