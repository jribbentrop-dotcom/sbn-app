<?php
/**
 * Checkout Page
 */

defined( 'ABSPATH' ) || exit;

get_header(); ?>

<style>
.sbn-checkout-wrapper { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
.sbn-checkout-header { background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%); padding: 30px 40px; border-radius: 16px; color: white; margin-bottom: 30px; }
.sbn-checkout-header h1 { font-size: 2em; margin: 0 0 8px 0; }
.sbn-checkout-header p { margin: 0; opacity: 0.9; }

.sbn-checkout-content { display: grid; grid-template-columns: 1fr 450px; gap: 30px; }

.sbn-checkout-form { background: white; border-radius: 16px; padding: 35px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }

/* Hide WooCommerce's default billing/shipping headings - we add our own */
.woocommerce-billing-fields h3,
.woocommerce-shipping-fields h3,
.woocommerce-additional-fields h3 { display: none !important; }

/* Our custom section headings */
.sbn-checkout-form > h3 { font-size: 1.3em; color: #2d3748; margin: 0 0 20px 0; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }

.woocommerce-billing-fields, .woocommerce-shipping-fields { margin-bottom: 30px; }
.woocommerce-additional-fields { display: none !important; }
.form-row { margin-bottom: 20px; }
.form-row label { display: block; font-weight: 600; color: #2d3748; margin-bottom: 8px; font-size: 0.95em; }
.form-row label .required { color: #e53e3e; }
.form-row input, .form-row select, .form-row textarea { width: 100%; padding: 12px 15px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 1em; transition: all 0.3s ease; }
.form-row input:focus, .form-row select:focus, .form-row textarea:focus { outline: none; border-color: #f39c12; box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.1); }
.form-row textarea { min-height: 100px; resize: vertical; }

.woocommerce-shipping-fields { background: #f7fafc; padding: 20px; border-radius: 12px; margin-top: 20px; }
#ship-to-different-address { margin-bottom: 20px; }
#ship-to-different-address label { cursor: pointer; display: flex; align-items: center; gap: 10px; font-weight: 600; color: #2d3748; }

.sbn-checkout-sidebar { position: sticky; top: 20px; }
.sbn-order-review { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); margin-bottom: 20px; }
.sbn-order-review h3 { font-size: 1.3em; color: #2d3748; margin: 0 0 20px 0; }

.sbn-order-item { display: flex; gap: 15px; padding: 15px 0; border-bottom: 1px solid #e2e8f0; }
.sbn-order-item:last-child { border-bottom: none; }
.sbn-order-item-image { width: 60px; height: 60px; border-radius: 8px; object-fit: contain; background: #f7fafc; padding: 5px; flex-shrink: 0; }
.sbn-order-item-details { flex: 1; }
.sbn-order-item-name { font-weight: 600; color: #2d3748; font-size: 0.95em; margin-bottom: 3px; }
.sbn-order-item-meta { font-size: 0.85em; color: #718096; }
.sbn-order-item-price { font-weight: 700; color: #2d3748; white-space: nowrap; }

.sbn-order-totals { margin-top: 20px; padding-top: 20px; border-top: 2px solid #e2e8f0; }
.sbn-totals-row { display: flex; justify-content: space-between; padding: 12px 0; }
.sbn-totals-row.total { border-top: 2px solid #2d3748; margin-top: 12px; padding-top: 20px; font-size: 1.2em; font-weight: 700; color: #2d3748; }
.sbn-totals-label { color: #718096; }
.sbn-totals-value { font-weight: 600; color: #2d3748; }

/* Hide WooCommerce's default order review table */
.woocommerce-checkout-review-order-table { display: none !important; }

.sbn-payment-methods { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }
.sbn-payment-methods h3 { font-size: 1.3em; color: #2d3748; margin: 0 0 20px 0; }
.woocommerce-checkout-payment { margin: 0; }
.wc_payment_methods { list-style: none; padding: 0; margin: 0 0 20px 0; }
.wc_payment_method { background: #f7fafc; border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px 20px; margin-bottom: 12px; cursor: pointer; transition: all 0.3s ease; }
.wc_payment_method:hover { border-color: #f39c12; }
.wc_payment_method.checked { border-color: #f39c12; background: rgba(243, 156, 18, 0.05); }
.wc_payment_method label { display: flex; align-items: center; gap: 12px; cursor: pointer; font-weight: 600; color: #2d3748; margin: 0; }
.wc_payment_method input[type="radio"] { margin: 0; flex-shrink: 0; }
.payment_box { background: white; border: 2px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-top: 12px; font-size: 0.9em; color: #718096; }

.sbn-place-order-btn { width: 100%; background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%); color: white; border: none; padding: 18px; border-radius: 12px; font-size: 1.1em; font-weight: 600; cursor: pointer; transition: all 0.3s ease; margin-top: 20px; }
.sbn-place-order-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(243, 156, 18, 0.3); }

.woocommerce-privacy-policy-text { font-size: 0.85em; color: #718096; margin-top: 15px; text-align: center; }
.woocommerce-terms-and-conditions-wrapper { margin-top: 15px; }
.woocommerce-form__label-for-checkbox { display: flex; align-items: flex-start; gap: 10px; font-size: 0.9em; color: #718096; }

@media (max-width: 1024px) { .sbn-checkout-content { grid-template-columns: 1fr; } .sbn-checkout-sidebar { position: static; } }
</style>

<div class="sbn-checkout-wrapper">
    <div class="sbn-checkout-header">
        <h1>Checkout</h1>
        <p>Complete your purchase securely</p>
    </div>

    <?php if ( WC()->cart->is_empty() ) : ?>
        <div class="sbn-empty-cart">
            <h2>Your cart is empty</h2>
            <p>Add items to your cart before proceeding to checkout.</p>
            <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>">Continue Shopping</a>
        </div>
    <?php else : ?>
        <form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">

            <div class="sbn-checkout-content">
                <div class="sbn-checkout-form">
                    <?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

                    <h3>Billing Details</h3>
                    <?php do_action( 'woocommerce_checkout_billing' ); ?>

                    <?php do_action( 'woocommerce_checkout_shipping' ); ?>

                    <?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
                </div>

                <div class="sbn-checkout-sidebar">
                    <div class="sbn-order-review">
                        <h3>Your Order</h3>
                        <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                            $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                            if ( ! $_product || ! $_product->exists() ) continue;
                        ?>
                        <div class="sbn-order-item">
                            <?php echo $_product->get_image( array( 60, 60 ), array( 'class' => 'sbn-order-item-image' ) ); ?>
                            <div class="sbn-order-item-details">
                                <div class="sbn-order-item-name"><?php echo $_product->get_name(); ?></div>
                                <div class="sbn-order-item-meta">Qty: <?php echo $cart_item['quantity']; ?></div>
                            </div>
                            <div class="sbn-order-item-price"><?php echo WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ); ?></div>
                        </div>
                        <?php endforeach; ?>

                        <div class="sbn-order-totals">
                            <div class="sbn-totals-row">
                                <span class="sbn-totals-label">Subtotal</span>
                                <span class="sbn-totals-value"><?php wc_cart_totals_subtotal_html(); ?></span>
                            </div>
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
                        </div>
                    </div>

                    <div class="sbn-payment-methods">
                        <h3>Payment Method</h3>
                        <?php do_action( 'woocommerce_review_order_before_payment' ); ?>
                        <?php woocommerce_checkout_payment(); ?>
                        <?php do_action( 'woocommerce_review_order_after_payment' ); ?>
                    </div>
                </div>
            </div>

        </form>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Make entire payment method box clickable
    $('.wc_payment_method').on('click', function(e) {
        // Don't trigger if clicking on the radio button itself or label
        if (!$(e.target).is('input[type="radio"]') && !$(e.target).is('label')) {
            var $radio = $(this).find('input[type="radio"]');
            $radio.prop('checked', true).trigger('change');
        }
    });
    
    // Add checked class to selected payment method
    $('.wc_payment_methods input[type="radio"]').on('change', function() {
        $('.wc_payment_method').removeClass('checked');
        $(this).closest('.wc_payment_method').addClass('checked');
    });
    
    // Set initial checked state
    $('.wc_payment_methods input[type="radio"]:checked').closest('.wc_payment_method').addClass('checked');
});
</script>

<?php get_footer(); ?>
