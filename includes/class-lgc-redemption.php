<?php
/**
 * Rules for spending a gift-certificate coupon at checkout.
 *
 * WooCommerce already enforces single use (usage_limit = 1) and drops a coupon
 * that stops being valid (WC_Cart::check_cart_coupons()). What it does not know
 * is that a certificate is never partially spent, and that it must not be used
 * to buy another certificate — that is what this class adds.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LGC_Redemption
{

    public static function init()
    {
        add_filter('woocommerce_coupon_is_valid', array(__CLASS__, 'validate'), 10, 3);
    }

    /**
     * WC_Discounts::is_coupon_valid() applies this filter inside its own try
     * block and passes a non-numeric exception message straight through to the
     * customer, so throwing here is how we get a specific message instead of
     * the generic "coupon is not valid".
     *
     * @param bool $valid
     * @param WC_Coupon $coupon
     * @param WC_Discounts $discounts
     * @return bool
     * @throws Exception With the customer-facing reason.
     */
    public static function validate($valid, $coupon, $discounts): bool
    {
        if (!LGC_Coupon::is_certificate_coupon($coupon)) {
            return $valid;
        }

        // Checked before the cart guard below: a revoked certificate must not be
        // applied anywhere, including an order edited in wp-admin.
        if (LGC_Coupon::is_revoked($coupon)) {
            throw new Exception('Цей подарунковий сертифікат більше не дійсний. Якщо ви вважаєте це помилкою, зв’яжіться з нами.');
        }

        $cart = $discounts->get_object();
        if (!$cart instanceof WC_Cart) {
            return $valid;
        }

        if (Luxury_Gift_Certificate::cart_has_certificate()) {
            throw new Exception('Подарунковим сертифікатом не можна оплатити інший подарунковий сертифікат.');
        }

        // The certificate is spent in full on a single order, so it can only be
        // applied when the goods cover it — this is the same subtotal a
        // fixed-cart discount is allowed to consume.
        $subtotal = (float)$cart->get_displayed_subtotal();
        $amount = (float)$coupon->get_amount();

        if ($amount > $subtotal + 0.001) {
            // Deliberately says nothing about the certificate's value: whoever
            // enters a code must not be able to read its balance off the error.
            throw new Exception(
                'Суми товарів у кошику недостатньо для використання цього сертифіката — він застосовується повністю за одне замовлення. Додайте товарів і спробуйте ще раз.'
            );
        }

        return $valid;
    }
}
