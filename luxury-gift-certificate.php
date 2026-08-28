<?php
/**
 * Plugin Name: Luxury Gift Certificate
 * Description: Turn any WooCommerce product into a gift certificate: the customer enters a custom amount (within a configurable min/max) plus recipient name & email on the product page. The amount sets the price and all values are saved to the order. Paid orders issue a single-use WooCommerce coupon as the certificate code.
 * Version: 2.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-lgc-coupon.php';
require_once __DIR__ . '/includes/class-lgc-redemption.php';

class Luxury_Gift_Certificate
{

    /** Product meta flag marking a product as a gift certificate. */
    const FLAG = '_lgc_is_certificate';

    /** Readable order-item meta keys (single source of truth for save + read). */
    const META_NAME = 'Ім’я отримувача';
    const META_EMAIL = 'Email отримувача';
    const META_PHONE = 'Ваш телефон';
    const META_AMOUNT = 'Сума сертифіката';

    /** Boot all hooks. */
    public static function init()
    {
        // Admin: WooCommerce settings tab.
        add_filter('woocommerce_settings_tabs_array', array(__CLASS__, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_lgc', array(__CLASS__, 'settings_output'));
        add_action('woocommerce_update_options_lgc', array(__CLASS__, 'settings_save'));

        // Admin: per-product "Gift certificate" checkbox on the General tab.
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'product_checkbox'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'save_product_flag'));

        // Front-end: input fields + purchasability + price display.
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_fields'));
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'is_purchasable'), 10, 2);
        add_filter('woocommerce_get_price_html', array(__CLASS__, 'price_html'), 10, 2);
        add_filter('woocommerce_loop_add_to_cart_link', array(__CLASS__, 'loop_button'), 10, 2);

        // Cart / order pipeline.
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'enforce_exclusive_cart'), 5, 3);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'validate_cart_contents'));
        add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'restrict_payment_gateways'));
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'set_price'), 20);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_order_item'), 10, 4);

        LGC_Coupon::init();
        LGC_Redemption::init();

        // The issued codes are shown in exactly two places and nowhere else.
        add_action('woocommerce_after_order_itemmeta', array(__CLASS__, 'render_admin_codes'), 10, 2);
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'render_email_codes'), 10, 4);
        add_filter('woocommerce_hidden_order_itemmeta', array(__CLASS__, 'hide_raw_item_meta'));
        add_filter('woocommerce_rest_prepare_shop_order_object', array(__CLASS__, 'hide_rest_item_meta'), 10, 3);

        // Certificates are redeemed through the coupon field, so the prompt has
        // to name them — customers do not read "купон" as "сертифікат".
        add_filter('woocommerce_checkout_coupon_message', array(__CLASS__, 'coupon_toggle_message'));
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    /** Default option values (also the fallback used by opt()). */
    public static function defaults()
    {
        return array(
                'lgc_min_amount' => 100,
                'lgc_max_amount' => 10000,
                'lgc_allowed_gateways' => array('morkva-monopay'),
                'lgc_name_enabled' => 'yes',
                'lgc_name_required' => 'yes',
                'lgc_email_enabled' => 'yes',
                'lgc_email_required' => 'yes',
                'lgc_phone_enabled' => 'yes',
                'lgc_phone_required' => 'yes',
        );
    }

    /** Read a single option with its documented default. */
    public static function opt($key)
    {
        $defaults = self::defaults();
        return get_option($key, isset($defaults[$key]) ? $defaults[$key] : '');
    }

    public static function add_settings_tab($tabs)
    {
        $tabs['lgc'] = 'Подарунковий сертифікат';
        return $tabs;
    }

    public static function get_settings()
    {
        return array(
                array(
                        'title' => 'Налаштування подарункового сертифіката',
                        'type' => 'title',
                        'desc' => 'Застосовується до будь-якого товару з увімкненою позначкою «Подарунковий сертифікат» (Дані товару → Загальні).',
                        'id' => 'lgc_section',
                ),
                array(
                        'title' => 'Мінімальна сума',
                        'id' => 'lgc_min_amount',
                        'type' => 'number',
                        'default' => 100,
                        'custom_attributes' => array('min' => '0', 'step' => '0.01'),
                ),
                array(
                        'title' => 'Максимальна сума',
                        'id' => 'lgc_max_amount',
                        'type' => 'number',
                        'default' => 10000,
                        'custom_attributes' => array('min' => '0', 'step' => '0.01'),
                ),
                array(
                        'title' => 'Способи оплати сертифіката',
                        'id' => 'lgc_allowed_gateways',
                        'type' => 'multiselect',
                        'class' => 'wc-enhanced-select',
                        'default' => array('morkva-monopay'),
                        'options' => self::gateway_options(),
                        'desc' => 'Замовлення із сертифікатом можна оплатити лише вибраними способами — решта не показуються на сторінці оформлення. Якщо не вибрано жодного, застосовується типовий список (Оплата картою).',
                        'desc_tip' => true,
                ),
                array(
                        'title' => 'Ім’я отримувача',
                        'id' => 'lgc_name_enabled',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Показувати поле імені',
                ),
                array(
                        'id' => 'lgc_name_required',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Зробити ім’я обов’язковим',
                        'checkboxgroup' => 'end',
                ),
                array(
                        'title' => 'Email отримувача',
                        'id' => 'lgc_email_enabled',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Показувати поле email',
                ),
                array(
                        'id' => 'lgc_email_required',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Зробити email обов’язковим',
                        'checkboxgroup' => 'end',
                ),
                array(
                        'title' => 'Ваш телефон',
                        'id' => 'lgc_phone_enabled',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Показувати поле телефону',
                ),
                array(
                        'id' => 'lgc_phone_required',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Зробити телефон обов’язковим',
                        'checkboxgroup' => 'end',
                ),
                array(
                        'type' => 'sectionend',
                        'id' => 'lgc_section',
                ),
        );
    }

    /** All registered gateways as id => "Title (id)", for the settings multiselect. */
    private static function gateway_options(): array
    {
        $options = array();
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return $options;
        }
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            $options[$id] = sprintf('%s (%s)', $gateway->get_method_title(), $id);
        }
        return $options;
    }

    /**
     * Gateway IDs a certificate order may be paid with. An empty setting means the
     * restriction was never configured (or was blanked by accident), so fall back to
     * the default rather than leaving the customer with no way to pay at all.
     */
    private static function allowed_gateways()
    {
        $allowed = self::opt('lgc_allowed_gateways');
        if (!is_array($allowed) || !$allowed) {
            $defaults = self::defaults();
            $allowed = $defaults['lgc_allowed_gateways'];
        }
        return array_map('strval', $allowed);
    }

    public static function settings_output()
    {
        woocommerce_admin_fields(self::get_settings());
    }

    public static function settings_save()
    {
        woocommerce_update_options(self::get_settings());
    }

    /* ---------------------------------------------------------------------
     * Product flag
     * ------------------------------------------------------------------- */

    public static function product_checkbox()
    {
        woocommerce_wp_checkbox(
                array(
                        'id' => self::FLAG,
                        'label' => 'Подарунковий сертифікат',
                        'description' => 'Клієнт вказує суму та дані отримувача на сторінці товару. Автоматично зберігається як віртуальний товар.',
                )
        );
    }

    /** Save the flag; force virtual when enabled (quantity of several is allowed). */
    public static function save_product_flag($product)
    {
        $enabled = isset($_POST[self::FLAG]) ? 'yes' : 'no';
        $product->update_meta_data(self::FLAG, $enabled);
        if ('yes' === $enabled) {
            $product->set_virtual(true);
        }
    }

    /** Is the given product (object or ID) a gift certificate? */
    public static function is_certificate($product)
    {
        if (!$product instanceof WC_Product) {
            $product = wc_get_product($product);
        }
        return $product && 'yes' === $product->get_meta(self::FLAG);
    }

    /** Does the cart already hold at least one gift-certificate line? */
    public static function cart_has_certificate(): bool
    {
        return self::cart_holds(true);
    }

    /** Does the cart hold at least one line that is not a gift certificate? */
    public static function cart_has_regular_items(): bool
    {
        return self::cart_holds(false);
    }

    /**
     * Shared cart scan: true as soon as a line matching $certificate is found.
     * The cart is not always available (REST bootstrap, cron), hence the guard.
     */
    private static function cart_holds($certificate): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (self::is_certificate($item['product_id']) === $certificate) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------------
     * Front-end fields & price display
     * ------------------------------------------------------------------- */

    public static function render_fields()
    {
        global $product;
        if (!self::is_certificate($product)) {
            return;
        }

        $min = (float)self::opt('lgc_min_amount');
        $max = (float)self::opt('lgc_max_amount');

        echo '<div class="lgc-fields" style="margin-bottom:1.2em;">';

        printf(
                '<p class="form-row form-row-wide"><label for="lgc_amount">%s <abbr class="required" title="обов’язкове">*</abbr></label>
			<input type="number" id="lgc_amount" name="lgc_amount" min="%s" max="%s" step="0.01" required value="%s" style="max-width:220px;" /></p>',
                sprintf(
                /* translators: 1: min price, 2: max price */
                        esc_html__('Сума сертифіката (%1$s – %2$s)', 'luxury-gc'),
                        wp_strip_all_tags(wc_price($min)),
                        wp_strip_all_tags(wc_price($max))
                ),
                esc_attr($min),
                esc_attr($max),
                isset($_POST['lgc_amount']) ? esc_attr(wc_clean(wp_unslash($_POST['lgc_amount']))) : ''
        );

        if ('yes' === self::opt('lgc_name_enabled')) {
            $req = 'yes' === self::opt('lgc_name_required');
            printf(
                    '<p class="form-row form-row-wide"><label for="lgc_name">%s %s</label>
				<input type="text" id="lgc_name" name="lgc_name" %s value="%s" style="max-width:320px;" /></p>',
                    'Ім’я отримувача',
                    $req ? '<abbr class="required" title="обов’язкове">*</abbr>' : '',
                    $req ? 'required' : '',
                    isset($_POST['lgc_name']) ? esc_attr(wc_clean(wp_unslash($_POST['lgc_name']))) : ''
            );
        }

        if ('yes' === self::opt('lgc_email_enabled')) {
            $req = 'yes' === self::opt('lgc_email_required');
            printf(
                    '<p class="form-row form-row-wide"><label for="lgc_email">%s %s</label>
				<input type="email" id="lgc_email" name="lgc_email" %s value="%s" style="max-width:320px;" /></p>',
                    'Email отримувача',
                    $req ? '<abbr class="required" title="обов’язкове">*</abbr>' : '',
                    $req ? 'required' : '',
                    isset($_POST['lgc_email']) ? esc_attr(wc_clean(wp_unslash($_POST['lgc_email']))) : ''
            );
        }

        if ('yes' === self::opt('lgc_phone_enabled')) {
            $req = 'yes' === self::opt('lgc_phone_required');
            printf(
                    '<p class="form-row form-row-wide"><label for="lgc_phone">%s %s</label>
				<input type="tel" id="lgc_phone" name="lgc_phone" inputmode="tel" placeholder="+380XXXXXXXXX" %s value="%s" style="max-width:320px;" /></p>',
                    'Ваш телефон',
                    $req ? '<abbr class="required" title="обов’язкове">*</abbr>' : '',
                    $req ? 'required' : '',
                    isset($_POST['lgc_phone']) ? esc_attr(wc_clean(wp_unslash($_POST['lgc_phone']))) : ''
            );
            self::phone_mask_script();
        }

        echo '</div>';
    }

    /**
     * Inline JS: allow an optional leading "+" and digits only, capped at 12
     * digits (fits "+380" plus the 9-digit number). No grouping, so typing and
     * backspace stay completely natural.
     */
    public static function phone_mask_script()
    {
        ?>
        <script>
            (function () {
                const el = document.getElementById('lgc_phone');
                if (!el) {
                    return;
                }

                function clean(value) {
                    const plus = value.charAt(0) === '+' ? '+' : '';
                    return plus + value.replace(/\D/g, '').slice(0, 12);
                }

                el.addEventListener('input', function () {
                    const d = clean(el.value);
                    if (el.value !== d) {
                        el.value = d;
                    }
                });
                if (el.value) {
                    el.value = clean(el.value);
                }
            })();
        </script>
        <?php
    }

    /** Gift certificates have no fixed price, so force them purchasable. */
    public static function is_purchasable($purchasable, $product)
    {
        return self::is_certificate($product) ? true : $purchasable;
    }

    /** Hide the price for certificates everywhere (the amount is chosen on the product page). */
    public static function price_html($html, $product)
    {
        if (self::is_certificate($product)) {
            return '';
        }
        return $html;
    }

    /** In archives, send customers to the product page (fields can't be filled via AJAX). */
    public static function loop_button($html, $product)
    {
        if (self::is_certificate($product)) {
            return sprintf(
                    '<a href="%s" class="button">%s</a>',
                    esc_url($product->get_permalink()),
                    'Обрати суму'
            );
        }
        return $html;
    }

    /* ---------------------------------------------------------------------
     * Cart / order pipeline
     * ------------------------------------------------------------------- */

    /**
     * A certificate is always ordered on its own: several certificates may share
     * a cart, but never alongside a regular product. Runs ahead of validate() so
     * the customer gets this reason rather than a complaint about the amount field.
     */
    public static function enforce_exclusive_cart($passed, $product_id, $quantity)
    {
        if (!$passed) {
            return $passed;
        }

        if (self::is_certificate($product_id)) {
            if (self::cart_has_regular_items()) {
                wc_add_notice(
                        'Подарунковий сертифікат оформлюється окремим замовленням. Будь ласка, оформіть поточне замовлення або спорожніть кошик.',
                        'error'
                );
                return false;
            }
        } elseif (self::cart_has_certificate()) {
            wc_add_notice(
                    'У кошику вже є подарунковий сертифікат — його потрібно оформити окремим замовленням, без інших товарів.',
                    'error'
            );
            return false;
        }

        return $passed;
    }

    /**
     * Safety net for mixed carts that never went through add-to-cart validation.
     * WC_Cart::add_to_cart() does not apply woocommerce_add_to_cart_validation —
     * its callers do — so anything adding to the cart directly bypasses the check
     * above. Fires on the cart and checkout pages; the error notice also stops
     * WC_Checkout::process_checkout() from creating the order.
     */
    public static function validate_cart_contents(): void
    {
        if (self::cart_has_certificate() && self::cart_has_regular_items()) {
            wc_add_notice(
                    'У кошику є подарунковий сертифікат разом з іншими товарами. Сертифікат потрібно оформити окремим замовленням.',
                    'error'
            );
        }
    }

    /**
     * Certificates are paid online only — an unpaid order means no certificate gets
     * issued, so offline methods (bank transfer, cash on delivery) are hidden.
     * Filtering the available gateways is enforcement, not just display: checkout
     * rejects any posted method missing from this list (WC_Checkout, "Invalid
     * payment method"), as does the guard before process_payment().
     */
    public static function restrict_payment_gateways($gateways)
    {
        if (!is_array($gateways) || !self::context_has_certificate()) {
            return $gateways;
        }

        $allowed = self::allowed_gateways();
        foreach (array_keys($gateways) as $id) {
            if (!in_array((string)$id, $allowed, true)) {
                unset($gateways[$id]);
            }
        }
        return $gateways;
    }

    /**
     * Is a certificate part of what is being paid for right now? Normally that is
     * the cart, but the order-pay endpoint (payment link for an existing order) has
     * an empty cart, so the order itself has to be inspected there.
     */
    private static function context_has_certificate(): bool
    {
        if (self::cart_has_certificate()) {
            return true;
        }

        global $wp;
        if (empty($wp->query_vars['order-pay'])) {
            return false;
        }
        $order = wc_get_order(absint($wp->query_vars['order-pay']));
        if (!$order) {
            return false;
        }
        foreach ($order->get_items() as $item) {
            if (self::is_certificate($item->get_product())) {
                return true;
            }
        }
        return false;
    }

    public static function validate($passed, $product_id, $quantity)
    {
        if (!self::is_certificate($product_id)) {
            return $passed;
        }

        $min = (float)self::opt('lgc_min_amount');
        $max = (float)self::opt('lgc_max_amount');
        $amount = isset($_POST['lgc_amount']) ? (float)wc_clean(wp_unslash($_POST['lgc_amount'])) : 0;

        if ($amount <= 0) {
            wc_add_notice('Будь ласка, вкажіть суму сертифіката.', 'error');
            return false;
        }
        if ($amount < $min || $amount > $max) {
            wc_add_notice(
                    sprintf(
                    /* translators: 1: min price, 2: max price */
                            'Сума сертифіката має бути від %1$s до %2$s.',
                            wp_strip_all_tags(wc_price($min)),
                            wp_strip_all_tags(wc_price($max))
                    ),
                    'error'
            );
            return false;
        }

        if ('yes' === self::opt('lgc_name_enabled') && 'yes' === self::opt('lgc_name_required')) {
            $name = isset($_POST['lgc_name']) ? wc_clean(wp_unslash($_POST['lgc_name'])) : '';
            if ('' === $name) {
                wc_add_notice('Будь ласка, вкажіть ім’я отримувача.', 'error');
                return false;
            }
        }

        if ('yes' === self::opt('lgc_email_enabled')) {
            $email = isset($_POST['lgc_email']) ? sanitize_email(wp_unslash($_POST['lgc_email'])) : '';
            $required = 'yes' === self::opt('lgc_email_required');

            if ($required && '' === $email) {
                wc_add_notice('Будь ласка, вкажіть email отримувача.', 'error');
                return false;
            }
            // Always validate the format when an email is provided.
            if ('' !== $email && !is_email($email)) {
                wc_add_notice('Будь ласка, вкажіть коректний email отримувача.', 'error');
                return false;
            }
        }

        if ('yes' === self::opt('lgc_phone_enabled') && 'yes' === self::opt('lgc_phone_required')) {
            $phone = isset($_POST['lgc_phone']) ? wc_clean(wp_unslash($_POST['lgc_phone'])) : '';
            if ('' === $phone) {
                wc_add_notice('Будь ласка, вкажіть номер телефону отримувача.', 'error');
                return false;
            }
        }

        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id)
    {
        if (!self::is_certificate($product_id)) {
            return $cart_item_data;
        }

        $data = array(
                'amount' => isset($_POST['lgc_amount']) ? (float)wc_clean(wp_unslash($_POST['lgc_amount'])) : 0,
        );
        if ('yes' === self::opt('lgc_name_enabled') && isset($_POST['lgc_name'])) {
            $data['name'] = wc_clean(wp_unslash($_POST['lgc_name']));
        }
        if ('yes' === self::opt('lgc_email_enabled') && isset($_POST['lgc_email'])) {
            $data['email'] = sanitize_email(wp_unslash($_POST['lgc_email']));
        }
        if ('yes' === self::opt('lgc_phone_enabled') && isset($_POST['lgc_phone'])) {
            $data['phone'] = wc_clean(wp_unslash($_POST['lgc_phone']));
        }

        // Unique key so certificates with different amounts/recipients stay separate lines.
        $data['_unique'] = md5(microtime() . wp_rand());
        $cart_item_data['lgc'] = $data;

        return $cart_item_data;
    }

    public static function display_cart_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['lgc'])) {
            return $item_data;
        }
        $lgc = $cart_item['lgc'];
        if (!empty($lgc['amount'])) {
            $item_data[] = array(
                    'key' => self::META_AMOUNT,
                    'value' => wp_strip_all_tags(wc_price($lgc['amount'])),
            );
        }
        if (!empty($lgc['name'])) {
            $item_data[] = array('key' => self::META_NAME, 'value' => esc_html($lgc['name']));
        }
        if (!empty($lgc['email'])) {
            $item_data[] = array('key' => self::META_EMAIL, 'value' => esc_html($lgc['email']));
        }
        if (!empty($lgc['phone'])) {
            $item_data[] = array('key' => self::META_PHONE, 'value' => esc_html($lgc['phone']));
        }
        return $item_data;
    }

    /** Override the line price with the customer-entered amount. */
    public static function set_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['lgc']['amount'])) {
                $cart_item['data']->set_price((float)$cart_item['lgc']['amount']);
            }
        }
    }

    /** Persist the entered values onto the order line item (readable keys show in admin & emails). */
    public static function save_order_item($item, $cart_item_key, $values, $order)
    {
        if (empty($values['lgc'])) {
            return;
        }
        $lgc = $values['lgc'];
        if (!empty($lgc['amount'])) {
            $item->add_meta_data(self::META_AMOUNT, wc_format_localized_price($lgc['amount']), true);
        }
        if (!empty($lgc['name'])) {
            $item->add_meta_data(self::META_NAME, $lgc['name'], true);
        }
        if (!empty($lgc['email'])) {
            $item->add_meta_data(self::META_EMAIL, $lgc['email'], true);
        }
        if (!empty($lgc['phone'])) {
            $item->add_meta_data(self::META_PHONE, $lgc['phone'], true);
        }
    }

    /**
     * The checkout "Have a coupon?" prompt, extended to invite certificate
     * codes as well. Rebuilt rather than string-patched so the link keeps the
     * `showcoupon` class and the ARIA wiring that the toggle script and screen
     * readers rely on (see woocommerce/templates/checkout/form-coupon.php).
     */
    public static function coupon_toggle_message($message)
    {
        return 'Маєте купон або подарунковий сертифікат? '
                . '<a href="#" role="button"'
                . ' aria-label="' . esc_attr('Ввести код купона або сертифіката') . '"'
                . ' aria-controls="woocommerce-checkout-form-coupon" aria-expanded="false"'
                . ' class="showcoupon">Натисніть тут, щоб ввести код</a>';
    }

    /* ---------------------------------------------------------------------
     * Certificate codes — shown in admin and in the customer email only
     * ------------------------------------------------------------------- */

    /**
     * Keep the raw JSON out of the admin order item meta table. That view calls
     * get_all_formatted_meta_data('') — an empty hide-prefix — so the leading
     * underscore alone does not hide it there; only this filter does.
     * render_admin_codes() presents the same data readably instead.
     */
    public static function hide_raw_item_meta($keys)
    {
        $keys[] = LGC_Coupon::ITEM_META;
        return $keys;
    }

    /**
     * Strip the raw JSON from REST order responses. WC_REST_Orders_V2_Controller
     * ::get_order_item_data() builds line items from WC_Order_Item::get_data(),
     * which returns every meta row unfiltered — the underscore prefix means
     * nothing there. The analytics controller inherits the same preparation, so
     * this one filter covers /wc/v3/orders and /wc-analytics/orders alike.
     */
    public static function hide_rest_item_meta($response, $order, $request)
    {
        $data = $response->get_data();
        if (empty($data['line_items']) || !is_array($data['line_items'])) {
            return $response;
        }

        foreach ($data['line_items'] as $index => $line) {
            if (empty($line['meta_data']) || !is_array($line['meta_data'])) {
                continue;
            }
            $data['line_items'][$index]['meta_data'] = array_values(
                    array_filter($line['meta_data'], function ($meta) {
                        $key = is_object($meta) ? $meta->key : (isset($meta['key']) ? $meta['key'] : '');
                        return LGC_Coupon::ITEM_META !== $key;
                    })
            );
        }

        $response->set_data($data);
        return $response;
    }

    /**
     * Under the line item on the admin order screen. This hook only ever fires
     * inside wp-admin (includes/admin/meta-boxes/views/html-order-item.php).
     */
    public static function render_admin_codes($item_id, $item)
    {
        $codes = LGC_Coupon::codes_for_item($item);
        if (!$codes) {
            return;
        }

        echo '<div class="lgc-codes" style="margin-top:.5em;">';
        foreach ($codes as $entry) {
            $code = isset($entry['code']) ? $entry['code'] : '';
            $link = !empty($entry['coupon_id']) ? get_edit_post_link($entry['coupon_id']) : '';
            printf(
                    '<p style="margin:0;"><strong>Код сертифіката:</strong> %s</p>',
                    $link
                            ? sprintf('<a href="%s"><code>%s</code></a>', esc_url($link), esc_html($code))
                            : sprintf('<code>%s</code>', esc_html($code))
            );
        }
        echo '</div>';
    }

    /**
     * After the order table in the customer's own emails. Admin notifications
     * never show the code. «Виконано» is included because an order moved
     * straight from «В очікуванні» to «Виконано» never triggers the processing
     * email, and «Подробиці замовлення» because that is what support resends
     * when a customer loses their code — it carries nothing on an unpaid order,
     * where no certificate has been issued yet.
     */
    public static function render_email_codes($order, $sent_to_admin, $plain_text, $email): void
    {
        $allowed = array(
                'customer_processing_order',
                'customer_completed_order',
                'customer_invoice',
        );
        if ($sent_to_admin || !isset($email->id) || !in_array($email->id, $allowed, true)) {
            return;
        }

        $codes = LGC_Coupon::codes_for_order($order);
        if (!$codes) {
            return;
        }

        $heading = _n('Ваш подарунковий сертифікат', 'Ваші подарункові сертифікати', count($codes), 'luxury-gc');
        $note = 'Введіть код у полі «Промокод» у кошику. Сертифікат діє один раз і використовується повністю за одне замовлення, тому сума покупки має бути не меншою за його номінал.';

        if ($plain_text) {
            // No escaping here: this is plain text, not markup.
            echo "\n\n" . $heading . "\n";
            foreach ($codes as $entry) {
                printf(
                        "%s — %s\n",
                        isset($entry['code']) ? $entry['code'] : '',
                        self::plain_price(isset($entry['amount']) ? $entry['amount'] : 0)
                );
            }
            echo $note . "\n";
            return;
        }

        echo '<h2>' . esc_html($heading) . '</h2><ul>';
        foreach ($codes as $entry) {
            printf(
                    '<li><strong style="font-size:1.1em;letter-spacing:1px;">%s</strong> — %s</li>',
                    esc_html(isset($entry['code']) ? $entry['code'] : ''),
                    wp_kses_post(wc_price(isset($entry['amount']) ? $entry['amount'] : 0))
            );
        }
        echo '</ul><p>' . esc_html($note) . '</p>';
    }

    /** wc_price() with the markup and the entities resolved, for plain-text mail. */
    private static function plain_price($amount)
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
    }
}

add_action('plugins_loaded', array('Luxury_Gift_Certificate', 'init'));

// HPOS: the plugin only ever touches orders through the CRUD API.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// The "when to issue" setting is gone — certificates are always issued on payment.
register_activation_hook(__FILE__, function () {
    delete_option('lgc_issue_on_paid');
});
