# Luxury Gift Certificate

WooCommerce plugin that turns any product into a gift certificate. The customer
enters a custom amount plus the recipient's details on the product page; the
amount becomes the line price, and once the order is paid a certificate record is
written to a standalone certificates database.

- **Version:** 1.0.0
- **Requires:** WordPress with WooCommerce
- **Branch:** `main` — the version running on production

> Certificates issued by this version are **not redeemable on the website**.
> WordPress only writes to the certificates database; it never reads from it.
> Redemption happens offline through the Telegram bot.
>
> The `stage` branch carries **2.0.0**, which replaces this mechanism with
> single-use WooCommerce coupons. See that branch's README before deploying it.

## Installation

Clone into the WordPress plugins directory — the directory name is what
WordPress activates, so keep it as `luxury-gift-certificate`:

```bash
cd wp-content/plugins
git clone git@github.com:luxuryaromacomua-png/luxury-certificates-plugin.git luxury-gift-certificate
```

Activate under Plugins, then deploy updates with:

```bash
git fetch origin && git reset --hard origin/main
```

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
3. **Point the plugin at the certificates database** (see below).

## How it works

### Buying

The product page shows an amount field, plus optional recipient name, email and
phone. `woocommerce_before_calculate_totals` overrides the line price with the
entered amount, and the values are saved to the order line item under readable
keys, so they appear in the admin order screen and in order emails.

### Issuing

When the order reaches `processing` or `completed`, one certificate record is
created per purchased unit in the external database. The assigned number is
written back to the line item as **`Номери сертифікатів`**, which also acts as
the idempotency guard — a line that already has it is skipped.

Numbers are assigned as `MAX(number) + 1` inside a transaction. They are plain
sequential integers with no prefix or check digit.

If `lgc_issue_on_paid` is disabled, records are created as soon as the order is
placed, before payment, via `woocommerce_checkout_order_processed` and
`woocommerce_store_api_checkout_order_processed`.

### Redemption

Not handled here. This plugin only ever *creates* certificates — there is no read
path from WordPress into the certificates database, so a certificate cannot be
checked, displayed or redeemed on the site. That is done through the Telegram bot,
which shares the same database.

## Certificates database

A standalone MySQL database (`luxury_certificates` by default), separate from
WordPress and shared with the Telegram bot. Accessed with plain PDO so the schema
is trivial to mirror on the bot side.

Override the connection in `wp-config.php`:

| Constant | Default |
| --- | --- |
| `LGC_DB_HOST` | `127.0.0.1` |
| `LGC_DB_PORT` | `3306` |
| `LGC_DB_NAME` | `luxury_certificates` |
| `LGC_DB_USER` | `root` |
| `LGC_DB_PASS` | `root` |

**Define these.** The fallbacks are `root`/`root`, which should never be what a
production site actually uses.

Rows written to the `certificates` table:

| Column | Value |
| --- | --- |
| `number` | `MAX(number) + 1` |
| `amount` | Per-unit amount from the line subtotal |
| `status` | `active` |
| `recipient_name`, `email`, `phone` | From the product page fields |
| `wc_order_id` | The WooCommerce order |
| `source` | `Сайт` |
| `created_at` | `NOW()` |

`used_at` and `used_location` are written by the bot when a certificate is redeemed.

If the database is unreachable, the insert fails, `Номери сертифікатів` is never
written, and the only trace is an `[LGC]` line in the PHP error log. The customer
has paid and received nothing, so watch that log.

## Settings

WooCommerce → Settings → **Подарунковий сертифікат**

| Option | Default | Purpose |
| --- | --- | --- |
| `lgc_min_amount` | `100` | Minimum certificate amount |
| `lgc_max_amount` | `10000` | Maximum certificate amount |
| `lgc_issue_on_paid` | `yes` | Create the record only after payment. Disabled = created at checkout, before payment. |
| `lgc_name_enabled` / `_required` | `yes` / `yes` | Recipient name field |
| `lgc_email_enabled` / `_required` | `yes` / `yes` | Recipient email field |
| `lgc_phone_enabled` / `_required` | `yes` / `yes` | Phone field |

Per-product: `_lgc_is_certificate` (`yes`/`no`).

## Line item meta

All keys are readable Ukrainian strings, so WooCommerce renders them everywhere a
line item is shown — admin, all order emails, the thank-you page and My Account.

| Key | Meaning |
| --- | --- |
| `Сума сертифіката` | Amount |
| `Ім'я отримувача` | Recipient name |
| `Email отримувача` | Recipient email |
| `Ваш телефон` | Phone |
| `Номери сертифікатів` | Issued numbers, comma separated |

The recipient's email is collected and stored but never emailed — the number only
reaches the buyer, through the standard WooCommerce order emails.

## Files

```
luxury-gift-certificate.php                Settings, product flag, cart/order pipeline, issuance
includes/class-lgc-certificate-store.php   PDO connection and insert
```

## Known limitations

Addressed in 2.0.0 on the `stage` branch:

- Certificate numbers are sequential integers and therefore guessable.
- Certificates cannot be redeemed on the website at all.
- A certificate is not revoked when its order is refunded or cancelled.
- Database credentials fall back to `root`/`root` when the constants are unset.
- `woocommerce_store_api_checkout_order_processed` passes an order object rather
  than an ID, so block checkout with `lgc_issue_on_paid` disabled records
  `wc_order_id = 1`.
