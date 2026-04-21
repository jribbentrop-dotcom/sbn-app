<?php
/**
 * Cart Page - Mega Menu Card Style
 */

defined( 'ABSPATH' ) || exit;

get_header(); ?>

<style>
.sbn-cart-wrapper { 
    max-width: 1400px; 
    margin: 0 auto; 
    padding: 40px 20px; 
    background: white; 
}

.sbn-cart-header { 
    background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%); 
    padding: 30px 40px; 
    border-radius: 16px; 
    color: white; 
    margin-bottom: 30px; 
}

.sbn-cart-header h1 { 
    font-size: 2em; 
    margin: 0 0 8px 0; 
}

.sbn-cart-header p { 
    margin: 0; 
    opacity: 0.9; 
}

/* Side by Side Layout */
.sbn-cart-content { 
    display: grid; 
    grid-template-columns: 1fr 400px; 
    gap: 30px; 
    align-items: start;
}

/* Cart Items Container - Card Style like Mega Menu */
.sbn-cart-items-wrapper {
    background: white;
    border-radius: 16px;
    padding: 25px;
    border: 1px solid #e5e5e5;
}

.sbn-cart-items {
    list-style: none;
    margin: 0;
    padding: 0;
}

/* Individual Cart Item - Mega Menu Style */
.sbn-cart-item {
    display: flex;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid #f0f0f0;
    align-items: center;
    position: relative;
}

.sbn-cart-item:last-child {
    border-bottom: none;
}

/* Product Image - Full Size Icon like Mega Menu */
.sbn-cart-item-image {
    flex-shrink: 0;
    width: 100px;
    height: 100px;
    border-radius: 8px;
    overflow: hidden;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sbn-cart-item-image img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

/* Product Details */
.sbn-cart-item-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.sbn-cart-item-name {
    font-size: 1em;
    font-weight: 600;
    color: #2d3748;
    text-decoration: none;
    line-height: 1.3;
}

.sbn-cart-item-name:hover {
    color: #f39c12;
}

/* Price directly under title */
.sbn-cart-item-price {
    font-size: 1.1em;
    color: #f39c12;
    font-weight: 700;
}

/* Quantity Controls - Compact */
.sbn-cart-quantity {
    display: flex;
    align-items: center;
    background: #f7fafc;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    overflow: hidden;
    width: fit-content;
    margin-top: 5px;
}

.sbn-cart-quantity button {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    font-size: 1.1em;
    color: #4a5568;
    cursor: pointer;
    transition: all 0.2s;
}

.sbn-cart-quantity button:hover {
    background: #edf2f7;
    color: #f39c12;
}

.sbn-cart-quantity input {
    width: 40px;
    height: 32px;
    border: none;
    background: transparent;
    text-align: center;
    font-weight: 600;
    color: #2d3748;
    font-size: 0.9em;
}

/* Remove Button */
.sbn-cart-item-remove {
    position: absolute;
    top: 15px;
    right: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #fed7d7;
    color: #c53030;
    text-decoration: none;
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    transition: all 0.2s ease;
}

.sbn-cart-item-remove:hover {
    background: #c53030;
    color: white;
    transform: scale(1.1);
}

/* Cart Totals - Right Side */
.sbn-cart-totals {
    background: white;
    border-radius: 16px;
    padding: 30px;
    border: 1px solid #e5e5e5;
    position: sticky;
    top: 20px;
}

.sbn-cart-totals h2 {
    font-size: 1.5em;
    margin: 0 0 20px 0;
    color: #2d3748;
}

.sbn-totals-row {
    display: flex;
    justify-content: space-between;
    padding: 15px 0;
    border-bottom: 1px solid #e2e8f0;
}

.sbn-totals-row.total {
    border-bottom: none;
    border-top: 2px solid #2d3748;
    font-size: 1.2em;
    font-weight: 700;
    color: #2d3748;
    padding-top: 20px;
    margin-top: 10px;
}

.sbn-totals-label {
    color: #718096;
}

.sbn-totals-value {
    font-weight: 600;
    color: #2d3748;
}

/* Checkout Button */
.sbn-checkout-btn {
    width: 100%;
    background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);
    color: white;
    border: none;
    padding: 18px;
    border-radius: 12px;
    font-size: 1.1em;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 20px;
}

.sbn-checkout-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(243, 156, 18, 0.3);
    background: linear-gradient(135deg, #e67e22 0%, #c0392b 100%);
}

.sbn-continue-shopping {
    display: block;
    text-align: center;
    color: #718096;
    text-decoration: none;
    margin-top: 15px;
    font-weight: 500;
}

.sbn-continue-shopping:hover {
    color: #2d3748;
}

/* Empty Cart */
.sbn-empty-cart {
    background: white;
    border-radius: 16px;
    padding: 80px 60px;
    text-align: center;
    border: 1px solid #e5e5e5;
    max-width: 600px;
    margin: 60px auto;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.empty-cart-icon {
    width: 120px;
    height: 120px;
    margin: 0 auto 30px;
    background: var(--sbn-light, #f7fafc);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-cart-icon svg {
    width: 60px;
    height: 60px;
    color: var(--sbn-orange, #f39c12);
}

.sbn-empty-cart h2 {
    font-size: 2em;
    color: var(--sbn-dark, #2c3e50);
    margin: 0 0 15px 0;
}

.sbn-empty-cart p {
    color: var(--sbn-text, #5a5a5a);
    font-size: 1.1em;
    line-height: 1.6;
    margin: 0 0 30px 0;
}

.continue-shopping-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);
    color: white !important;
    padding: 14px 28px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
}

.continue-shopping-btn svg {
    width: 20px;
    height: 20px;
}

.continue-shopping-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(243, 156, 18, 0.3);
}

/* Coupon Section */
.sbn-coupon-wrapper {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.sbn-coupon-form {
    display: flex;
    gap: 10px;
}

.sbn-coupon-input {
    flex: 1;
    padding: 12px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.95em;
}

.sbn-coupon-button {
    background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.sbn-coupon-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
}

/* Responsive - Mobile First Approach */
/* DEFAULT: Side-by-Side for ALL devices */
/* Only override to stacked on actual mobile phones */

/* ONLY Mobile phones (iPhone, small Android) - Stacked */
@media (max-width: 767px) {
    .sbn-cart-content {
        grid-template-columns: 1fr !important;
    }
    
    .sbn-cart-totals {
        position: static !important;
    }
    
    .sbn-cart-item {
        flex-wrap: wrap;
    }
    
    .sbn-cart-item-image {
        width: 80px;
        height: 80px;
    }
    
    .sbn-cart-item-remove {
        position: static;
        margin-left: auto;
    }
    
    .sbn-cart-wrapper {
        padding: 20px 15px;
    }
}

/* iPad and larger - Keep default side-by-side (no changes needed) */
/* iPad Portrait (768px+): Uses default 1fr 400px */
/* iPad Landscape (1024px+): Uses default 1fr 400px */
/* Desktop (1200px+): Uses default 1fr 400px */
</style>

<div class="sbn-cart-wrapper">
    <div class="sbn-cart-header">
        <h1>Shopping Cart</h1>
        <p><?php echo WC()->cart->get_cart_contents_count(); ?> item<?php echo WC()->cart->get_cart_contents_count() !== 1 ? 's' : ''; ?> in your cart</p>
    </div>

    <?php if ( WC()->cart->is_empty() ) : ?>
        <div class="sbn-empty-cart">
            <div class="empty-cart-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="21" r="1"/>
                    <circle cx="20" cy="21" r="1"/>
                    <path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
            </div>
            <h2>Your Cart is Empty</h2>
            <p>Start exploring our collection of beautiful sheet music and guitar transcriptions.</p>
            <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="continue-shopping-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                Continue Shopping
            </a>
        </div>
    <?php else : ?>
        <div class="sbn-cart-content">
            <!-- Cart Items - Mega Menu Card Style -->
            <div class="sbn-cart-items-wrapper">
                <form action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
                    <ul class="sbn-cart-items">
                        <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                            $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                            if ( ! $_product || ! $_product->exists() ) continue;
                        ?>
                        <li class="sbn-cart-item">
                            <!-- Product Image -->
                            <div class="sbn-cart-item-image">
                                <a href="<?php echo esc_url( $_product->get_permalink() ); ?>">
                                    <?php echo $_product->get_image('thumbnail'); ?>
                                </a>
                            </div>
                            
                            <!-- Product Details -->
                            <div class="sbn-cart-item-details">
                                <a href="<?php echo esc_url( $_product->get_permalink() ); ?>" class="sbn-cart-item-name">
                                    <?php echo $_product->get_name(); ?>
                                </a>
                                
                                <!-- Price directly under title -->
                                <div class="sbn-cart-item-price">
                                    <?php echo WC()->cart->get_product_price( $_product ); ?>
                                </div>
                                
                                <!-- Quantity Controls -->
                                <?php if ( $_product->is_sold_individually() ) : ?>
                                    <div style="font-size: 0.9em; color: #718096;">Qty: <?php echo esc_html( $cart_item['quantity'] ); ?></div>
                                <?php else : ?>
                                    <div class="sbn-cart-quantity">
                                        <button type="button" class="qty-minus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">−</button>
                                        <input type="number" 
                                               name="cart[<?php echo $cart_item_key; ?>][qty]" 
                                               value="<?php echo esc_attr( $cart_item['quantity'] ); ?>" 
                                               min="0" 
                                               class="qty-input"
                                               data-key="<?php echo esc_attr( $cart_item_key ); ?>" />
                                        <button type="button" class="qty-plus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">+</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Remove Button -->
                            <a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" 
                               class="sbn-cart-item-remove" 
                               title="Remove">×</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <!-- Update Cart Button (Hidden, triggered by JS) -->
                    <input type="hidden" name="update_cart" value="1" />
                    <?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
                </form>
                
                <!-- Coupon Section -->
                <?php if ( wc_coupons_enabled() ) : ?>
                <div class="sbn-coupon-wrapper">
                    <form method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
                        <div class="sbn-coupon-form">
                            <input type="text" 
                                   name="coupon_code" 
                                   class="sbn-coupon-input" 
                                   placeholder="Coupon code" 
                                   value="" />
                            <button type="submit" 
                                    class="sbn-coupon-button" 
                                    name="apply_coupon" 
                                    value="Apply coupon">Apply coupon</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Cart Totals - Right Side -->
            <div class="sbn-cart-totals">
                <h2>Cart Totals</h2>
                
                <div class="sbn-totals-row">
                    <span class="sbn-totals-label">Subtotal</span>
                    <span class="sbn-totals-value"><?php wc_cart_totals_subtotal_html(); ?></span>
                </div>
                
                <?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
                    <div class="sbn-totals-row">
                        <span class="sbn-totals-label">Coupon: <?php echo esc_html( $code ); ?></span>
                        <span class="sbn-totals-value">-<?php wc_cart_totals_coupon_html( $coupon ); ?></span>
                    </div>
                <?php endforeach; ?>
                
                <?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
                    <div class="sbn-totals-row">
                        <span class="sbn-totals-label">Shipping</span>
                        <span class="sbn-totals-value"><?php wc_cart_totals_shipping_html(); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
                    <div class="sbn-totals-row">
                        <span class="sbn-totals-label">Tax</span>
                        <span class="sbn-totals-value"><?php wc_cart_totals_taxes_total_html(); ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="sbn-totals-row total">
                    <span class="sbn-totals-label">Total</span>
                    <span class="sbn-totals-value"><?php wc_cart_totals_order_total_html(); ?></span>
                </div>
                
                <button type="button" 
                        onclick="window.location.href='<?php echo esc_url( wc_get_checkout_url() ); ?>'" 
                        class="sbn-checkout-btn">
                    Proceed to Checkout
                </button>
                
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" 
                   class="sbn-continue-shopping">← Continue Shopping</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quantity Plus/Minus Buttons
    const updateQuantity = function(input, delta) {
        const currentVal = parseInt(input.value) || 0;
        const newVal = Math.max(0, currentVal + delta);
        input.value = newVal;
        
        // Auto-submit form to update cart
        const form = input.closest('form');
        if (form && newVal >= 0) {
            form.submit();
        }
    };
    
    document.querySelectorAll('.qty-minus').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const key = this.dataset.key;
            const input = document.querySelector('input[data-key="' + key + '"]');
            if (input) updateQuantity(input, -1);
        });
    });
    
    document.querySelectorAll('.qty-plus').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const key = this.dataset.key;
            const input = document.querySelector('input[data-key="' + key + '"]');
            if (input) updateQuantity(input, 1);
        });
    });
    
    // Auto-submit on manual input change
    document.querySelectorAll('.qty-input').forEach(function(input) {
        input.addEventListener('change', function() {
            const form = this.closest('form');
            if (form) form.submit();
        });
    });
});
</script>

<?php get_footer(); ?>
