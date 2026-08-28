# Luxury Gift Certificate

WooCommerce plugin that turns any product into a gift certificate. The customer
picks an amount and the recipient's details on the product page; once the order
is paid, the plugin issues a single-use WooCommerce coupon with an unguessable
code that can be spent on the site.

- **Version:** 2.0.0
- **Requires:** WordPress with WooCommerce, PHP 8.0+
- **Tested against:** WooCommerce 10.9, HPOS enabled
- **Branch:** `stage` — deployed to staging

Admin and customer-facing strings are Ukrainian, hardcoded (no translation files).

> `main` still carries **1.0.0**, the version running on production, which writes
> certificates to a standalone database instead. Merging `stage` into `main` is
> what releases this version.

## Installation

Clone into the WordPress plugins directory — the directory name is what
WordPress activates, so keep it as `luxury-gift-certificate`:

```bash
cd wp-content/plugins
git clone git@github.com:luxuryaromacomua-png/luxury-certificates-plugin.git luxury-gift-certificate
```

Activate under Plugins, then deploy updates with:

```bash
git fetch origin && git reset --hard origin/stage   # production: origin/main
```

`reset --hard` rather than `pull`, so the checkout always matches the branch
exactly and a stray edit on the server cannot block a deploy.

> **Block web access to `.git`.** A `.git` directory under `wp-content/plugins/`
> is publicly readable by default and exposes the full source and history. Add
> `RedirectMatch 404 /\.git` to the site's root `.htaccess`, then confirm
> `curl -I https://<site>/wp-content/plugins/luxury-gift-certificate/.git/config`
> returns 404.

## Setup

1. **Mark a product as a certificate.** Edit the product → Product data →
   General → tick **Подарунковий сертифікат**. The product is forced virtual and
   needs no price; the customer enters the amount.
2. **Configure limits.** WooCommerce → Settings → **Подарунковий сертифікат**.

## How it works

### Buying

The product page shows an amount field plus optional recipient name, email and
phone. The entered amount becomes the line price.

Certificates are ordered on their own: several certificates may share a cart,
but never alongside a regular product. Only the payment gateways listed in the
settings are offered while a certificate is in the cart.

### Issuing

When the order reaches a paid status the plugin creates **one coupon per
purchased certificate unit**:

| Property | Value |
| --- | --- |
| Code | `lx-xxxx-xxxx-xxxx` |
| Discount type | `fixed_cart`, for the amount the customer entered |
| Usage limit | 1 |
| Individual use | yes — cannot be combined with other coupons |
| Expiry | none |

The code is 12 characters drawn with `random_int()` from the alphabet
`23456789abcdefghjkmnpqrstuvwxyz` (~59 bits). `0`, `1`, `i`, `l` and `o` are
excluded so codes are not misread. Lowercase is required: WooCommerce runs every
coupon code through `wc_strtolower()`, so anything else would not round-trip.

Issuance happens on `woocommerce_order_status_processing` **and**
`woocommerce_order_status_completed`. Both are needed — a paid order can reach
`completed` without passing through `processing` (virtual downloadable items, a
`woocommerce_payment_complete_order_status` filter, or an on-hold order completed
by hand). A guard on the line item makes the second call a no-op, and a per-order
MySQL advisory lock means a payment webhook delivered twice cannot mint duplicates.

### Redeeming

Certificates are entered in the normal coupon field. On top of WooCommerce's own
checks, the plugin refuses a certificate when:

- the cart's goods subtotal does not cover it in full — a certificate is spent in
  one order and leaves no remaining balance;
- the cart contains a certificate product — a certificate cannot buy another one;
- the certificate has been revoked.

The refusal message deliberately never states the certificate's value, so its
balance cannot be read off an error by someone holding only the code.

If the cart later drops below the certificate's value, WooCommerce's own
`check_cart_coupons()` removes it and tells the customer.

### Refunds and revocation

Revocation is derived from order state and reconciled on every relevant event,
rather than accumulated — so it survives later status changes.

| Event | Result |
| --- | --- |
| Order refunded, cancelled or failed | all certificates on the order revoked |
| Partial refund naming a quantity | exactly that many revoked |
| Partial refund of a bare amount | the whole line revoked |
| Refund deleted | revocation lifted, certificate usable again |

A revoked coupon keeps a `_lgc_revoked` meta flag and gains a **«— АНУЛЬОВАНО»**
suffix in its description, so it is obvious in Marketing → Купони. The coupon is
never deleted: that keeps the audit trail, preserves `usage_count` for a code that
was already spent, and lets a mistaken refund be undone.

Note that changing a refunded order's status back to `processing` does **not**
restore the certificate — the refund record still stands. Delete the refund itself.

If a certificate was already spent before the refund, an order note flags it: the
value is gone and only a human can resolve it.

### Where the code is visible

Deliberately just two places:

- the **admin order screen**, under the certificate line item, linked to the coupon;
- the customer's **Замовлення обробляється**, **Замовлення виконано** and
  **Деталі замовлення** emails.

It is stored in underscore-prefixed line item meta, hidden from the admin meta
table via `woocommerce_hidden_order_itemmeta`, and stripped from REST order
responses — which otherwise expose raw item meta unfiltered. It does not appear on
the thank-you page, in My Account, in admin notification emails, or in the Store API.

## Settings

WooCommerce → Settings → **Подарунковий сертифікат**

| Option | Default | Purpose |
| --- | --- | --- |
| `lgc_min_amount` | `100` | Minimum certificate amount |
| `lgc_max_amount` | `10000` | Maximum certificate amount |
| `lgc_allowed_gateways` | `morkva-monopay` | Gateways offered while a certificate is in the cart. Empty falls back to the default rather than leaving no way to pay. |
| `lgc_name_enabled` / `_required` | `yes` / `yes` | Recipient name field |
| `lgc_email_enabled` / `_required` | `yes` / `yes` | Recipient email field |
| `lgc_phone_enabled` / `_required` | `yes` / `yes` | Phone field |

Per-product: `_lgc_is_certificate` (`yes`/`no`).

## Data reference

| Key | Stored on | Meaning |
| --- | --- | --- |
| `_lgc_is_certificate` | product | Marks the product as a certificate |
| `_lgc_certificates` | order line item | JSON list of `{code, amount, coupon_id}` |
| `_lgc_certificate` | coupon | Marks a coupon as an issued certificate |
| `_lgc_order_id` | coupon | The order it was bought with |
| `_lgc_revoked` | coupon | Set when the purchase was undone |

Readable line item meta — `Сума сертифіката`, `Ім'я отримувача`,
`Email отримувача`, `Ваш телефон` — is shown to the customer as usual.

## Files

```
luxury-gift-certificate.php      Settings, product flag, cart/order pipeline, display
includes/class-lgc-coupon.php    Code generation, issuance, revocation
includes/class-lgc-redemption.php  Redemption rules
```

## Upgrading from 1.x

Version 1.x wrote certificates to a standalone `luxury_certificates` MySQL
database with sequential numbers, readable only by the Telegram bot. That path is
gone, along with its hardcoded database credentials.

- Existing rows with source `Сайт` stay in that database and remain redeemable
  through the bot. Nothing is migrated.
- The `lgc_issue_on_paid` setting was removed; issuance is always on payment.
  Delete the leftover row with `wp option delete lgc_issue_on_paid`.
- The readable `Номери сертифікатів` line item meta is no longer written. Existing
  orders keep theirs.
