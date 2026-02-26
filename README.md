# Shopwalk AI — WooCommerce Plugin

[![Plugin Version](https://img.shields.io/badge/version-1.1.0-blue)](https://shopwalk.com/woocommerce)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-a46497)](https://woocommerce.com)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](https://php.net)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**AI-enable your WooCommerce store in minutes.**

Shopwalk AI syncs your products to the Shopwalk AI shopping platform and implements the full [Universal Commerce Protocol (UCP)](https://shopwalk.com/ucp) on your store — so AI agents can discover, browse, and buy from you automatically.

---

## What It Does

Modern shoppers increasingly use AI assistants to find and buy products. Shopwalk AI bridges the gap between your WooCommerce store and the AI commerce layer:

- **Product sync** — Your catalog syncs to Shopwalk automatically. New products, price changes, and stock updates propagate in real time as you save them in WooCommerce.
- **AI discovery** — Your products appear in Shopwalk AI searches, surfaced by semantic understanding — not just keyword matching.
- **UCP server** — Your store exposes a full UCP-compliant REST API so any AI agent can browse your catalog, check availability, create checkout sessions, apply coupons, place orders, and track fulfillment.

**It's free. No subscription required. No per-sale fees. Ever.**

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 8.0 |
| PHP | 8.0 |
| SSL | Required (HTTPS) |

---

## Installation

### From the WordPress Plugin Directory (recommended)

1. In your WordPress admin, go to **Plugins → Add New**
2. Search for **Shopwalk AI**
3. Click **Install Now**, then **Activate**
4. Go to **WooCommerce → Settings → Shopwalk**
5. Click **Connect to Shopwalk AI — it's free**

That's it. Your store is connected and syncing.

### Manual Installation

1. Download the latest release zip from the [Releases page](https://github.com/shopwalk-inc/shopwalk-woocommerce-ucp/releases)
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **WooCommerce → Settings → Shopwalk** and connect

---

## How It Works

### 1. Auto-Registration

When you click **Connect to Shopwalk AI**, the plugin calls the Shopwalk API and registers your store. You receive a free API key (`sw_site_...`) that is stored in your WordPress database. No account creation, no credit card, no forms.

```
Store Admin Click → POST /api/v1/plugin/register → {api_key, merchant_id}
```

### 2. Product Sync

Once connected, your products sync to Shopwalk automatically:

- **On save** — when you create or update a product in WooCommerce
- **On delete/trash** — when you remove a product
- **Manual sync** — the settings page has a "Sync Products Now" button to push your full catalog on demand

Sync calls your Shopwalk API key to authenticate. Only your products are written — the key is scoped to your store's domain.

```
Product Save → POST /api/v1/products/ingest  (X-API-Key: sw_site_...)
Product Delete → DELETE /api/v1/products/ingest (X-API-Key: sw_site_...)
```

**Sync is on by default.** You can disable it in the plugin settings under **Sync products to Shopwalk**. Disabling sync means your store's products won't appear in Shopwalk AI search results — but UCP functionality remains fully active.

### 3. UCP Endpoints (on your store)

The plugin exposes a full UCP REST API on your WooCommerce installation. Shopwalk and any other UCP-compatible AI agent can use these endpoints to interact with your store:

| Endpoint | Description |
|---|---|
| `GET /.well-known/ucp` | UCP discovery — announces your store's capabilities |
| `GET /wp-json/shopwalk-wc/v1/products` | Browse catalog (with filters) |
| `GET /wp-json/shopwalk-wc/v1/products/{id}/availability` | Real-time stock & pricing |
| `POST /wp-json/shopwalk-wc/v1/checkout-sessions` | Create checkout session |
| `PUT /wp-json/shopwalk-wc/v1/checkout-sessions/{id}` | Update session (address, coupon, shipping) |
| `POST /wp-json/shopwalk-wc/v1/checkout-sessions/{id}/complete` | Place order |
| `GET /wp-json/shopwalk-wc/v1/orders/{id}` | Order status & tracking |
| `POST /wp-json/shopwalk-wc/v1/orders/{id}/refund` | Initiate refund |

These endpoints live on your server. You control them. Shopwalk never touches your admin or database directly.

### 4. Inbound API Key (optional)

You can set an **Inbound API Key** in the plugin settings to require authentication on the UCP endpoints. This prevents unauthorized agents from browsing or placing orders. Shopwalk uses this key when calling your store.

If left blank, UCP endpoints are open — fine for testing, not recommended for production.

---

## Settings Reference

Navigate to **WooCommerce → Settings → Shopwalk** to configure:

| Setting | Default | Description |
|---|---|---|
| Plugin Key | *(auto-set on connect)* | Your Shopwalk API key. Auto-provisioned on connect — no manual entry needed. |
| Inbound API Key | *(blank)* | Optional key to secure your UCP endpoints. |
| Sync products to Shopwalk | On | Push product changes to Shopwalk in real time. |
| Enable Catalog API | On | Allow AI agents to browse your products. |
| Enable Checkout API | On | Allow AI agents to create sessions and place orders. |
| Enable Webhooks | On | Send order status updates to Shopwalk. |

The **AI Commerce Status** section shows a live health check of all integration components.

---

## API Key Security

Your `sw_site_...` API key:

- Is unique to your store's domain — it cannot be used from a different site
- Authorizes write access only to **your** products in Shopwalk
- Is never visible in the plugin's public-facing UI
- Can be rotated from the Shopwalk settings page at any time
- Is automatically removed from your database if you delete the plugin (via `uninstall.php`)

If you believe your key has been compromised, disconnect and reconnect from the settings page to generate a new one.

---

## Using UCP Without Syncing

You can use the UCP features of this plugin (AI browsing, AI checkout, order tracking) without syncing your products to Shopwalk. To do this:

1. Install and activate the plugin
2. Set an **Inbound API Key** to secure your UCP endpoints
3. Do not click **Connect to Shopwalk AI** (or toggle off **Sync products to Shopwalk**)

In this mode, your store is fully UCP-compliant — any UCP-compatible AI agent can interact with it — but your products will not appear in Shopwalk AI search results. You will need to provide your store's UCP endpoint URL directly to agents or other platforms.

---

## Frequently Asked Questions

**Is this really free?**
Yes. The plugin is free, the Shopwalk integration is free, and there are no per-sale fees.

**Does Shopwalk take a cut of my sales?**
No. Orders are placed directly through WooCommerce. We have no visibility into your revenue.

**What data does the plugin send to Shopwalk?**
Product name, description, price, stock status, images, categories, and permalink. No customer data, no order data, no admin credentials.

**Can I use UCP without connecting to Shopwalk?**
Yes. See [Using UCP Without Syncing](#using-ucp-without-syncing).

**Does it work with WooCommerce HPOS (High-Performance Order Storage)?**
Yes. Full HPOS compatibility is declared.

**Will it work with my theme?**
The plugin is server-side only — it adds no frontend UI to your store. Fully theme-agnostic.

**Do I need an SSL certificate?**
Yes. The plugin requires HTTPS for both the UCP endpoints and the Shopwalk API connection.

---

## Contributing

We welcome bug reports, security disclosures, and pull requests. Please read the contribution guidelines before submitting.

### Quick Start for Contributors

```bash
git clone https://github.com/shopwalk-inc/shopwalk-woocommerce-ucp.git
cd shopwalk-woocommerce-ucp

# Install dev dependencies
composer install

# Run PHPCS (WordPress coding standards)
./vendor/bin/phpcs --standard=WordPress .

# Run QIT tests (requires Woo account)
composer run qit:activation
composer run qit:phpcs
composer run qit:security
```

### Branch Strategy

- **`main`** — stable, released code. This is what WP.org and Woo Marketplace distribute.
- **`feature/your-feature`** — all new work. Branch from `main`, open a PR back to `main`.
- **`fix/issue-number`** — bug fixes. Same flow.

**All changes go through a pull request.** Direct pushes to `main` are not allowed.

### Pull Request Requirements

- [ ] Describe what changed and why
- [ ] Tested on WordPress 6.0+ and WooCommerce 8.0+
- [ ] Follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [ ] No new external API calls introduced without disclosure in `readme.txt`
- [ ] No obfuscated code
- [ ] Strings are translation-ready (wrapped in `__()` / `_e()` with `shopwalk-ai` text domain)
- [ ] CI checks pass

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for the full guide.

### Reporting Security Vulnerabilities

Please **do not** open a public GitHub issue for security vulnerabilities.

Email **security@shopwalk.com** with details. We respond within 48 hours.

See [SECURITY.md](.github/SECURITY.md) for our full disclosure policy.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full version history.

**1.1.0** — Full UCP v1.1.0 implementation: coupon support, availability endpoint, catalog filters, order tracking, refund/cancel API, guest checkout assurance, auto-registration, `/.well-known/ucp` discovery.

**1.0.0** — Initial release: basic product sync, UCP checkout sessions, order webhooks.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.
