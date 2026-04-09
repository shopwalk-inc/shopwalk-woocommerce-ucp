# Shopwalk for WooCommerce

[![Plugin Version](https://img.shields.io/badge/version-1.13.0-blue)](https://shopwalk.com/woocommerce)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-a46497)](https://woocommerce.com)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](https://php.net)
[![UCP](https://img.shields.io/badge/UCP-1.0-0ea5e9)](https://ucp.dev)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Make your WooCommerce store ready for AI commerce — in one click.**

---

## The Standard Behind This Plugin

The [Universal Commerce Protocol (UCP)](https://ucp.dev) is an open industry standard for AI agent commerce — co-developed and adopted by **Google, Shopify, Etsy, Wayfair, Target, and Walmart**. Licensed under Apache 2.0.

UCP defines how AI agents discover stores, browse catalogs, create checkout sessions, and place orders — safely, securely, and without proprietary lock-in. It's built on proven foundations: REST, OAuth 2.0, and the [Agent Payments Protocol (AP2)](https://ucp.dev).

**Shopwalk is the WooCommerce implementation of UCP.** Install this plugin and your store speaks the language the world's largest retailers and AI companies agreed on.

---

## What It Does

AI agents — built on Claude, GPT, Gemini, and every major platform — need a standard way to interact with online stores. UCP is that standard. This plugin adds UCP to your WooCommerce store automatically.

- **Product sync** — Your catalog syncs to Shopwalk. New products, price changes, and stock updates propagate in real time as you save them in WooCommerce.
- **AI discovery** — Your products appear in Shopwalk AI searches across the network.
- **UCP server** — Your store exposes a full UCP-compliant REST API. AI agents can browse your catalog, check availability, apply coupons, place orders, and track fulfillment — without leaving your WooCommerce store.
- **Shopwalk trust layer** — Many hosting providers (Bluehost, WP Engine, and others) block direct bot traffic at the WAF level. Shopwalk acts as a trusted intermediary, so AI agent transactions reach your store reliably — without you having to touch your server configuration.

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
2. Search for **Shopwalk**
3. Click **Install Now**, then **Activate**
4. Go to **WooCommerce → Shopwalk** — your store is already registered and syncing

### Manual Installation

1. Download the latest release zip from the [Releases page](https://github.com/shopwalk-inc/woocommerce-ucp/releases)
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **WooCommerce → Shopwalk** to verify status

---

## How It Works

### 1. Auto-Registration

Activating the plugin registers your store with Shopwalk automatically. You receive a free API key (`sw_site_...`) stored in your WordPress database. No account creation, no credit card, no forms to fill.

### 2. Product Sync

Your products sync to Shopwalk in real time:

- **On save** — when you create or update a product
- **On delete/trash** — when you remove a product
- **Manual sync** — use the "Sync Products Now" button in WooCommerce → Shopwalk

### 3. UCP Endpoints

The plugin installs a full UCP REST API on your store. Both Shopwalk and any UCP-compatible AI agent can use these endpoints:

| Endpoint | Method | Description |
|---|---|---|
| `/.well-known/ucp` | GET | UCP discovery — announces store capabilities |
| `/wp-json/shopwalk/v1/products` | GET | Paginated catalog with filters |
| `/wp-json/shopwalk/v1/products/{id}` | GET | Single product detail |
| `/wp-json/shopwalk/v1/products/{id}/availability` | GET | Real-time stock & pricing |
| `/wp-json/shopwalk/v1/categories` | GET | Product categories |
| `/wp-json/shopwalk/v1/checkout-sessions` | POST | Create UCP checkout session |
| `/wp-json/shopwalk/v1/checkout-sessions/{id}` | GET/PUT | Get or update session |
| `/wp-json/shopwalk/v1/checkout-sessions/{id}/complete` | POST | Place order |
| `/wp-json/shopwalk/v1/orders/{id}` | GET | Order status & tracking |
| `/wp-json/shopwalk/v1/orders/{id}/refund` | POST | Initiate refund |

All endpoints follow the [UCP specification](https://ucp.dev/latest/specification/).

### 4. The Shopwalk Trust Layer

Major hosting providers use WAFs and bot-detection that block direct AI agent traffic to WooCommerce REST endpoints. Shopwalk solves this transparently:

1. Your plugin maintains a trusted, authenticated connection to Shopwalk
2. AI agents interact with your store through the Shopwalk API
3. Shopwalk forwards requests via its trusted channel — your hosting provider sees a known, signed Shopwalk request, not an anonymous bot
4. Your store processes the order through WooCommerce as normal

You don't need to whitelist IPs, change firewall rules, or configure anything. It works automatically.

---

## Settings Reference

Navigate to **WooCommerce → Shopwalk** to configure:

| Setting | Default | Description |
|---|---|---|
| Plugin Key | *(auto-set)* | Your Shopwalk API key. Auto-provisioned on activation. |
| Inbound API Key | *(blank)* | Optional key to require authentication on UCP endpoints. |
| Sync products to Shopwalk | On | Push product changes to Shopwalk in real time. |
| Enable Catalog API | On | Allow AI agents to browse your products via UCP. |
| Enable Checkout API | On | Allow AI agents to place orders via UCP. |
| Enable Webhooks | On | Send order status updates to Shopwalk. |

---

## Privacy & Data

This plugin communicates with the **Shopwalk API** (`https://api.shopwalk.com`) for the following purposes:

**On activation:** Registers your store to obtain a free partner ID.
Data sent: `site_url`, `wp_version`, `wc_version`

**On product save/delete:** Syncs product data to Shopwalk.
Data sent: product name, description, price, stock status, images, categories, permalink.

**Periodically:** License status check and plugin update check.
Data sent: site URL, plugin version.

**No customer data is ever sent to Shopwalk.** Names, addresses, and payment information never leave your WooCommerce store. All payment processing remains entirely within WooCommerce.

- [Shopwalk Privacy Policy](https://shopwalk.com/privacy)
- [Shopwalk Terms of Service](https://shopwalk.com/terms)

---

## UCP Without Syncing

You can run UCP endpoints without syncing to Shopwalk:

1. Set an **Inbound API Key** in settings to secure your UCP endpoints
2. Toggle off **Sync products to Shopwalk**

Your store becomes a standalone UCP server — any UCP-compatible agent can transact directly. Products won't appear in Shopwalk AI search results, but the full UCP checkout API remains active.

---

## Frequently Asked Questions

**Is this really free?**
Yes. The plugin is free, the Shopwalk integration is free, and there are no per-sale fees.

**Does Shopwalk take a cut of my sales?**
No. Orders go through your WooCommerce store. Shopwalk has no visibility into your revenue.

**What is UCP?**
The [Universal Commerce Protocol](https://ucp.dev) is an open standard for AI agent commerce, co-developed by Google, Shopify, Etsy, Wayfair, Target, and Walmart. It defines how AI agents discover stores, browse products, and complete purchases. This plugin adds UCP compliance to your WooCommerce store.

**Do I need to trust Shopwalk's protocol?**
No. UCP is an open industry standard — not Shopwalk's. Shopwalk is an implementation and a trust layer. You're implementing the same protocol Google, Shopify, and Walmart are building on.

**My hosting provider blocks bot traffic. Will this work?**
Yes. The Shopwalk trust layer specifically solves this. AI agents transact through Shopwalk's trusted channel, not as raw bots hitting your endpoints directly.

**Does it work with WooCommerce HPOS?**
Yes. Full HPOS compatibility is declared.

**Will it affect my store's frontend?**
No. The plugin is server-side only — no scripts, no styles, no frontend changes.

---

## Contributing

We welcome bug reports, security disclosures, and pull requests.

```bash
git clone https://github.com/shopwalk-inc/woocommerce-ucp.git
cd woocommerce-ucp
composer install

# Run coding standards check
./vendor/bin/phpcs --standard=WordPress .
```

Report security vulnerabilities to **security@shopwalk.com** — not as public GitHub issues.

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

**1.13.0** — Updated UCP framing to reflect open standard (Google, Shopify, Etsy, Wayfair, Target, Walmart). Shopwalk trust layer documentation.

**1.12.0** — CDN Store Boost improvements, heartbeat reliability.

**1.1.0** — Full UCP v1.1.0: coupon support, availability endpoint, catalog filters, order tracking, refund/cancel API, guest checkout assurance, auto-registration, `/.well-known/ucp` discovery.

**1.0.0** — Initial release: product sync, UCP checkout sessions, order webhooks.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
