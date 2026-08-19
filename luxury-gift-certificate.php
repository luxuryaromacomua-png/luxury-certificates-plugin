<?php
/**
 * Plugin Name: Luxury Gift Certificate
 * Description: Turn any WooCommerce product into a gift certificate: the customer enters a custom amount (within a configurable min/max) plus recipient name & email on the product page. The amount sets the price and all values are saved to the order.
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-lgc-certificate-store.php';

class Luxury_Gift_Certificate
{

    /** Product meta flag marking a product as a gift certificate. */
    const FLAG = '_lgc_is_certificate';

    /** Readable order-item meta keys (single source of truth for save + read). */
    const META_NAME = 'Ім’я отримувача';
    const META_EMAIL = 'Email отримувача';
    const META_PHONE = 'Ваш телефон';
    const META_AMOUNT = 'Сума сертифіката';
    const META_NUMBERS = 'Номери сертифікатів';

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
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'set_price'), 20);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_order_item'), 10, 4);

        // Issue certificate records once the order is paid (default). The
        // idempotency guard makes it safe even if several of these fire.
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'maybe_issue_certificates'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_issue_certificates'));

        // Optionally issue as soon as the customer places the order, before payment
        // (classic checkout + block checkout). The guard still prevents duplicates.
        if ('yes' !== self::opt('lgc_issue_on_paid')) {
            add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'maybe_issue_certificates'));
            add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'maybe_issue_certificates'));
        }
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
                'lgc_issue_on_paid' => 'yes',
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
                        'title' => 'Момент створення сертифіката',
                        'id' => 'lgc_issue_on_paid',
                        'type' => 'checkbox',
                        'default' => 'yes',
                        'desc' => 'Створювати сертифікат лише після оплати замовлення (статус «Опрацьовується» або «Виконано»). Якщо вимкнено — сертифікат створюється одразу після оформлення замовлення, ще до оплати.',
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
     * When an order becomes paid (processing/completed), create one certificate
     * record per purchased gift-certificate unit in the external certificates DB.
     * Guarded so re-firing the status transition never duplicates records.
     */
    public static function maybe_issue_certificates($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!self::is_certificate($product)) {
                continue;
            }

            // Already issued for this line item? Skip (idempotency guard).
            if ($item->get_meta(self::META_NUMBERS)) {
                continue;
            }

            // Per-unit amount comes from the line subtotal (price was set to the
            // entered amount); recipient details from the readable order meta.
            $qty = max(1, (int)$item->get_quantity());
            $amount = round((float)$item->get_subtotal() / $qty, 2);
            if ($amount <= 0) {
                continue;
            }
            $name = $item->get_meta(self::META_NAME);
            $email = $item->get_meta(self::META_EMAIL);
            $phone = $item->get_meta(self::META_PHONE);

            $numbers = array();
            for ($i = 0; $i < $qty; $i++) {
                $number = LGC_Certificate_Store::create(array(
                        'amount' => $amount,
                        'recipient_name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'wc_order_id' => $order_id,
                        'source' => 'Сайт',
                ));
                if ($number) {
                    $numbers[] = $number;
                }
            }

            if ($numbers) {
                // Record the issued numbers on the order for traceability + idempotency.
                $item->add_meta_data(self::META_NUMBERS, implode(', ', $numbers), true);
                $item->save();
            }
        }
    }
}

add_action('plugins_loaded', array('Luxury_Gift_Certificate', 'init'));
