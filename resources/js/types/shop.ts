export interface Category {
    id: number;
    slug: string;
    name: string;
    products_count?: number;
    children?: Category[];
}

export interface Tag {
    id: number;
    slug: string;
    name: string;
}

export interface Product {
    id: number;
    slug: string;
    title: string;
    excerpt: string | null;
    description?: string | null;
    price_cents: number;
    price_cents_usd: number;
    thumbnail_url: string | null;
    attributes?: Record<string, string | string[]>;
    categories: Category[];
    tags: Tag[];
}

export interface CartItem {
    product_id: number;
    slug: string;
    title: string;
    price_cents: number;
    thumbnail_path: string | null;
    quantity: number;
}

export interface Cart {
    items: CartItem[];
    count: number;
    subtotalCents: number;
}

export interface OrderItem {
    title: string;
    price_cents: number;
    quantity: number;
}

export interface Order {
    id: number;
    token: string;
    guest_email: string;
    total_cents: number;
    display_currency: 'EUR' | 'USD';
    total_formatted: string;
    status: 'pending_stub' | 'paid' | 'failed';
    created_at: string;
    items: OrderItem[];
}

export interface DownloadLink {
    token: string;
    product_id: number;
    product_title: string;
    is_valid: boolean;
    downloads_remaining: number;
    expires_at: string | null;
    download_url: string;
}

export interface ShopSharedProps {
    usd_rate: number;
}

export interface CartSharedProps {
    count: number;
}
