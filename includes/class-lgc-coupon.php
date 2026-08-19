<?php
/**
 * Turns a paid gift-certificate order line into WooCommerce coupons.
 *
 * One coupon per purchased unit: a fixed-cart discount worth the entered
 * amount, single-use, with a random code that cannot be guessed. The codes
 * live only on the coupon itself and in hidden order-item meta — the two
 * places that surface them (admin order screen, customer confirmation email)
 * render them explicitly.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LGC_Coupon
{

    /** Coupon meta: marks a coupon as a gift certificate issued by this plugin. */
    const META_FLAG = '_lgc_certificate';

    /** Coupon meta: the order the certificate was bought with. */
    const META_ORDER = '_lgc_order_id';

    /**
     * Coupon meta: the purchase was refunded, cancelled or failed, so the
     * certificate no longer represents money the shop is holding. Kept as a flag
     * rather than deleting the coupon so the record survives and a reversal can
     * lift it again.
     */
    const META_REVOKED = '_lgc_revoked';

    /**
     * Order-item meta holding the issued codes as JSON. The leading underscore
     * keeps WooCommerce from rendering it in emails, on the thank-you page and
     * in my-account — the code must not appear there.
     */
    const ITEM_META = '_lgc_certificates';

    /** Code alphabet without characters that are easy to misread (0/1/o/i/l). */
    const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    /** Seconds to wait for a concurrent issuance on the same order. */
    const LOCK_TIMEOUT = 10;

    /** Boot the issuance hooks. */
    public static function init()
    {
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'issue_for_order'), 5, 2);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'issue_for_order'), 5, 2);

        // Whatever the shop no longer holds the money for must stop working.
        // Revocation is derived from order state, so every event that can change
        // it runs the same reconciliation.
        foreach (array('refunded', 'cancelled', 'failed') as $status) {
            add_action('woocommerce_order_status_' . $status, array(__CLASS__, 'reconcile_revocations'), 5, 2);
        }

        // Partial refunds never change the order status, so they need their own
        // hook — without it a refunded certificate would keep working.
        add_action('woocommerce_order_partially_refunded', array(__CLASS__, 'reconcile_revocations'), 10, 1);

        // Deleting a refund is how a refund is actually undone; the order status
        // may not move at all, so nothing else would lift the revocation.
        add_action('woocommerce_refund_deleted', array(__CLASS__, 'reconcile_after_refund_deleted'), 10, 2);
    }

    /**
     * woocommerce_refund_deleted passes ($refund_id, $order_id) — the reverse of
     * every other hook here, hence the adapter rather than a direct callback.
     */
    public static function reconcile_after_refund_deleted($refund_id, $order_id): void
    {
        self::reconcile_revocations($order_id);
    }

    /** Has this certificate been revoked because its purchase was undone? */
    public static function is_revoked($coupon): bool
    {
        return $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta(self::META_REVOKED);
    }

    /**
     * Load the coupon behind one issued entry. Falls back to the code when the
     * stored id no longer resolves — a coupon deleted and recreated by hand.
     */
    private static function load_coupon(array $entry): ?WC_Coupon
    {
        foreach (array($entry['coupon_id'] ?? 0, $entry['code'] ?? '') as $key) {
            if (!$key) {
                continue;
            }
            $coupon = new WC_Coupon(is_numeric($key) ? (int)$key : $key);
            if ($coupon->get_id() > 0) {
                return $coupon;
            }
        }
        return null;
    }

    /**
     * Kill every certificate issued by an order whose payment was undone.
     *
     * Revocation is unconditional even when the code has already been spent —
     * a cancelled order releases WooCommerce's usage count, which would
     * otherwise hand the certificate back. Codes that were already redeemed are
     * reported separately: that value is gone and only a human can deal with it.
     *
     * @param int|WC_Order $order_id
     * @param WC_Order|null $order The instance that fired the transition.
     */
    public static function reconcile_revocations($order_id, $order = null): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $unpaid = $order->has_status(array('refunded', 'cancelled', 'failed'));

        $revoked = array();
        $restored = array();
        $already_spent = array();

        foreach ($order->get_items() as $item) {
            $codes = self::codes_for_item($item);
            if (!$codes) {
                continue;
            }

            $dead = $unpaid ? count($codes) : self::refunded_units($order, $item, count($codes));

            foreach (array_values($codes) as $index => $entry) {
                $coupon = self::load_coupon($entry);
                if (!$coupon) {
                    continue;
                }
                $should_die = $index < $dead;
                $was_revoked = self::is_revoked($coupon);
                $description = self::mark_description($coupon->get_description(), $should_die);

                // Write only when something actually differs, so re-running this
                // is free — but do repair a coupon whose flag and description
                // have drifted apart, rather than only acting on transitions.
                if ($was_revoked === $should_die && $description === $coupon->get_description()) {
                    continue;
                }

                if ($should_die) {
                    if (!$was_revoked && $coupon->get_usage_count() > 0) {
                        $already_spent[] = $entry['code'];
                    }
                    $coupon->update_meta_data(self::META_REVOKED, 'yes');
                } else {
                    $coupon->delete_meta_data(self::META_REVOKED);
                }

                $coupon->set_description($description);
                $coupon->save();

                if ($should_die && !$was_revoked) {
                    $revoked[] = $entry['code'];
                } elseif (!$should_die && $was_revoked) {
                    $restored[] = $entry['code'];
                }
            }
        }

        if ($revoked) {
            $order->add_order_note(
                sprintf(
                    'Сертифікати анульовано (%s): %s',
                    $unpaid ? wc_get_order_status_name($order->get_status()) : 'часткове повернення коштів',
                    implode(', ', $revoked)
                )
            );
        }

        if ($restored) {
            $order->add_order_note(sprintf('Сертифікати знову активні: %s', implode(', ', $restored)));
        }

        if ($already_spent) {
            $order->add_order_note(
                sprintf(
                    'УВАГА: сертифікати вже були використані до повернення коштів — %s. Вартість повернути неможливо, потрібне ручне рішення.',
                    implode(', ', $already_spent)
                )
            );
        }
    }

    /** Suffix that makes a dead certificate obvious in Marketing → Купони. */
    const REVOKED_SUFFIX = ' — АНУЛЬОВАНО';

    /**
     * Add or strip the revoked marker on a coupon description. The flag alone is
     * invisible in the coupon list, where a revoked certificate would otherwise
     * look like a perfectly good discount. Idempotent in both directions.
     */
    private static function mark_description($description, bool $revoked): string
    {
        $description = (string)$description;
        $suffix = self::REVOKED_SUFFIX;

        $clean = $description;
        while ('' !== $suffix && substr($clean, -strlen($suffix)) === $suffix) {
            $clean = substr($clean, 0, -strlen($suffix));
        }

        return $revoked ? $clean . $suffix : $clean;
    }

    /**
     * How many of a line's certificates a partial refund should kill.
     *
     * A refund naming a quantity kills exactly that many. A refund of a bare
     * amount cannot be split across codes without leaving a certificate worth
     * more than was paid for it, so it kills the whole line. Derived from the
     * order's cumulative refund totals, which makes this idempotent and correct
     * across several successive partial refunds.
     */
    private static function refunded_units(WC_Order $order, $item, int $total): int
    {
        $qty = abs((int)$order->get_qty_refunded_for_item($item->get_id()));
        if ($qty > 0) {
            return min($qty, $total);
        }

        if (abs((float)$order->get_total_refunded_for_item($item->get_id())) > 0) {
            return $total;
        }

        // A refund recorded against the order rather than any line. Certificate
        // orders hold nothing else, so it can only be against the certificate.
        return self::has_line_refunds($order) || 0.0 === self::total_refunded($order) ? 0 : $total;
    }

    /**
     * Summed from the refund objects rather than taken from
     * WC_Order::get_total_refunded(), which still reports the old figure when
     * woocommerce_refund_deleted fires — leaving a certificate revoked after the
     * refund that killed it had been undone.
     */
    private static function total_refunded(WC_Order $order): float
    {
        $total = 0.0;
        foreach ($order->get_refunds() as $refund) {
            $total += abs((float)$refund->get_amount());
        }
        return $total;
    }

    /** Does any line item carry a recorded refund? */
    private static function has_line_refunds(WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            if (abs((int)$order->get_qty_refunded_for_item($item->get_id())) > 0
                || abs((float)$order->get_total_refunded_for_item($item->get_id())) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * A random, unguessable coupon code: "lx-xxxx-xxxx-xxxx" (~59 bits).
     *
     * Lowercase is mandatory — wc_format_coupon_code() runs the code through
     * wc_strtolower(), so anything else would not round-trip.
     *
     * @return string|false False if no unique code could be found.
     * @throws \Random\RandomException
     */
    public static function generate_code(): bool|string
    {
        $length = strlen(self::ALPHABET) - 1;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $random = '';
            for ($i = 0; $i < 12; $i++) {
                $random .= self::ALPHABET[random_int(0, $length)];
            }

            $code = 'lx-' . implode('-', str_split($random, 4));
            if (!wc_get_coupon_id_by_code($code)) {
                return $code;
            }
        }

        return false;
    }

    /** Was this coupon issued as a gift certificate? */
    public static function is_certificate_coupon($coupon): bool
    {
        return $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta(self::META_FLAG);
    }

    /**
     * Create one coupon per purchased certificate unit. Guarded by the item meta
     * so re-firing the status transition never issues duplicates.
     *
     * Always works on the order instance the status transition handed us. The
     * confirmation email renders from that very object, and its line items are
     * already loaded in memory — writing the codes to a separately loaded copy
     * would persist them but leave the email with nothing to show.
     *
     * @param int|WC_Order $order_id
     * @param WC_Order|null $order The instance that fired the transition.
     */
    public static function issue_for_order($order_id, $order = null): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        // Serialise per order. Payment providers retry webhooks, and two
        // deliveries handled at once would both pass the guard below and mint
        // duplicate certificates — the second one orphaned, spendable, and
        // invisible to revocation.
        $lock = self::lock($order->get_id());
        if (false === $lock) {
            return;
        }

        try {
            self::issue_items($order);
        } finally {
            if (null !== $lock) {
                self::unlock($order->get_id());
            }
        }

        // The order is paid again — lift revocations, but only as far as any
        // partial refund still standing against it allows.
        self::reconcile_revocations($order->get_id(), $order);
    }

    /** MySQL advisory lock name. Server-global, so the database name is included. */
    private static function lock_name($order_id): string
    {
        global $wpdb;
        return substr('lgc_issue_' . $wpdb->dbname . '_' . (int)$order_id, 0, 64);
    }

    /**
     * @return bool|null True when held, false when another request holds it,
     *                   null when the database has no advisory locks — in which
     *                   case we proceed unlocked rather than never issuing.
     */
    private static function lock($order_id)
    {
        global $wpdb;
        $got = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name($order_id), self::LOCK_TIMEOUT)
        );
        return null === $got ? null : ('1' === (string)$got);
    }

    private static function unlock($order_id): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($order_id)));
    }

    /**
     * Has this line already been issued? Read from the database, not the
     * in-memory copy: an instance loaded before a concurrent request saved
     * would still look unissued. When the other request won, copy its result
     * onto our instance so this request's email still shows the codes.
     */
    private static function already_issued($item): bool
    {
        if ($item->get_meta(self::ITEM_META)) {
            return true;
        }

        $stored = $item->get_id() ? wc_get_order_item_meta($item->get_id(), self::ITEM_META, true) : '';
        if (!$stored) {
            return false;
        }

        $item->add_meta_data(self::ITEM_META, $stored, true);
        return true;
    }

    /** The actual per-line issuance. Always called under the order lock. */
    private static function issue_items(WC_Order $order): void
    {
        foreach ($order->get_items() as $item) {
            if (!Luxury_Gift_Certificate::is_certificate($item->get_product())) {
                continue;
            }

            // Already issued for this line item? Skip (idempotency guard).
            // Revocation state is reconciled by the caller afterwards.
            if (self::already_issued($item)) {
                continue;
            }

            // Per-unit amount comes from the line subtotal (the price was set to
            // the amount the customer entered).
            $qty = max(1, (int)$item->get_quantity());
            $amount = round((float)$item->get_subtotal() / $qty, 2);
            if ($amount <= 0) {
                continue;
            }

            $issued = array();
            for ($i = 0; $i < $qty; $i++) {
                $issued[] = self::create_coupon($amount, $order);
            }
            $issued = array_values(array_filter($issued));

            if (!$issued) {
                $order->add_order_note(
                    sprintf('Не вдалося створити сертифікат (%d шт.) для позиції «%s». Потрібне ручне втручання.', $qty, $item->get_name())
                );
                continue;
            }

            $item->add_meta_data(self::ITEM_META, wp_json_encode($issued), true);
            $item->save();

            if (count($issued) < $qty) {
                $order->add_order_note(
                    sprintf(
                        'Створено лише %d із %d сертифікатів для позиції «%s». Решту потрібно видати вручну.',
                        count($issued),
                        $qty,
                        $item->get_name()
                    )
                );
            }
        }
    }

    /**
     * Create a single certificate coupon.
     *
     * @return array|false array{code:string, amount:float, coupon_id:int}
     * @throws \Random\RandomException
     */
    private static function create_coupon($amount, WC_Order $order): bool|array
    {
        $code = self::generate_code();
        if (!$code) {
            error_log('[LGC] Could not generate a unique certificate code for order ' . $order->get_id());
            return false;
        }

        try {
            $coupon = new WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount($amount);
            // A certificate is spent in full, in one go, by one person.
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_free_shipping(false);
            $coupon->set_description(
                sprintf('Подарунковий сертифікат. Замовлення #%s', $order->get_order_number())
            );
            $coupon->update_meta_data(self::META_FLAG, 'yes');
            $coupon->update_meta_data(self::META_ORDER, $order->get_id());
            $coupon_id = $coupon->save();
        } catch (Exception $e) {
            error_log('[LGC] Certificate coupon creation failed: ' . $e->getMessage());
            return false;
        }

        if (!$coupon_id) {
            return false;
        }

        return array(
            'code' => $coupon->get_code(),
            'amount' => (float)$amount,
            'coupon_id' => (int)$coupon_id,
        );
    }

    /**
     * Codes issued for one order line item.
     *
     * @return array List of array{code:string, amount:float, coupon_id:int}.
     */
    public static function codes_for_item($item): array
    {
        $raw = $item ? $item->get_meta(self::ITEM_META) : '';
        if (!$raw) {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Every code issued across an order, flattened.
     *
     * @return array List of array{code:string, amount:float, coupon_id:int}.
     */
    public static function codes_for_order(WC_Abstract_Order $order): array
    {
        $codes = array();
        foreach ($order->get_items() as $item) {
            foreach (self::codes_for_item($item) as $entry) {
                $codes[] = $entry;
            }
        }
        return $codes;
    }
}
