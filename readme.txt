=== Shopwalk for WooCommerce ===
Contributors: shopwalkinc
Tags: woocommerce, ai, ucp, ai-commerce, product-sync
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.13.0
WC requires at least: 8.0
WC tested up to: 10.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WooCommerce store ready for AI agent commerce. Implements the Universal Commerce Protocol (UCP) — the open standard co-developed by Google, Shopify, Etsy, Wayfair, Target, and Walmart.

== Description ==

**Shopwalk for WooCommerce** is the official WooCommerce implementation of the [Universal Commerce Protocol (UCP)](https://ucp.dev) — an open industry standard for AI agent commerce, co-developed and adopted by **Google, Shopify, Etsy, Wayfair, Target, and Walmart**.

UCP defines how AI agents safely discover stores, browse catalogs, create checkout sessions, and place orders — built on REST, OAuth 2.0, and the Agent Payments Protocol (AP2). It's the common language the world's largest commerce platforms agreed on for the agentic commerce era.

Install this plugin and your WooCommerce store speaks that language.

= Why This Matters =

AI assistants — built on Claude, GPT, Gemini, and every other major platform — are starting to shop on behalf of users. When they need to buy something, they use UCP to find stores, check products, and complete purchases. Without UCP, your store is invisible to them.

This plugin adds full UCP compliance to your WooCommerce store in one click.

= The Shopwalk Trust Layer =

Many hosting providers (Bluehost, WP Engine, Kinsta, and others) block bot traffic at the WAF level — which includes AI agent requests. Shopwalk acts as a trusted intermediary:

1. Your plugin maintains a trusted, authenticated channel to Shopwalk
2. AI agents interact with your store through the Shopwalk API
3. Shopwalk forwards requests as a trusted partner your host already allows through
4. Your store processes orders through WooCommerce as normal

No firewall changes. No IP whitelisting. Just works.

= Features =

* **One-click setup** — Activate the plugin and your store registers automatically. No account creation, no license key entry required.
* **Real-time product sync** — Your catalog syncs to Shopwalk automatically. New products, price changes, and inventory updates propagate instantly.
* **AI discovery** — Your products appear in Shopwalk AI searches across the network.
* **UCP Catalog API** — AI agents can browse your full catalog via structured endpoints with filters for category, price, and stock.
* **UCP Availability API** — Real-time stock and pricing for every product and variant.
* **UCP Checkout** — AI agents create checkout sessions and place orders through your WooCommerce store — no redirects, no transaction fees, no middleman.
* **Coupon support** — AI agents can apply and remove WooCommerce coupon codes during checkout.
* **Order status & tracking** — Full order status with UCP-standardized statuses and shipment tracking.
* **Refund API** — AI agents can initiate partial or full refunds.
* **UCP discovery** — `/.well-known/ucp` announces your store's capabilities to any UCP-compatible agent.
* **Guest checkout assurance** — UCP sessions always work as guest checkouts.
* **Admin dashboard** — See sync status, AI request count, UCP health, and manage settings from WP Admin.
* **No per-sale fees** — Ever.

= Getting Started =

1. Install and activate the plugin
2. Your store registers with Shopwalk automatically
3. Products begin syncing immediately
4. View status at **WooCommerce → Shopwalk**

= UCP Endpoints =

All endpoints follow the [UCP specification](https://ucp.dev/latest/specification/). Available at `/wp-json/shopwalk/v1/`:

* `GET /products` — Paginated catalog with filters
* `GET /products/{id}` — Single product detail
* `GET /products/{id}/availability` — Real-time stock & pricing
* `GET /categories` — Product categories
* `POST /checkout-sessions` — Create UCP checkout session
* `GET|PUT /checkout-sessions/{id}` — Get or update session
* `POST /checkout-sessions/{id}/complete` — Place order
* `GET /orders/{id}` — Order status & tracking
* `POST /orders/{id}/refund` — Initiate refund
* `GET /.well-known/ucp` — UCP discovery manifest

= About UCP =

The Universal Commerce Protocol is an open standard licensed under Apache 2.0. Learn more at [ucp.dev](https://ucp.dev) or view the source at [github.com/Universal-Commerce-Protocol/ucp](https://github.com/Universal-Commerce-Protocol/ucp).

Shopwalk is an independent implementation of UCP — not affiliated with the UCP Authors or its co-developers.

== Installation ==

= From the WordPress Plugin Directory =

1. Go to **Plugins → Add New** in your WordPress admin
2. Search for **Shopwalk**
3. Click **Install Now**, then **Activate**
4. Go to **WooCommerce → Shopwalk** — setup is automatic

= Manual Installation =

1. Download the latest zip from the [GitHub releases page](https://github.com/shopwalk-inc/shopwalk-woocommerce-ucp/releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **WooCommerce → Shopwalk** to verify

== Frequently Asked Questions ==

= Is this really free? =
Yes. The plugin is free, the Shopwalk integration is free, and there are no per-sale fees — ever.

= Does Shopwalk take a cut of my sales? =
No. Orders are placed through your WooCommerce store. Shopwalk has no visibility into your revenue.

= What is UCP? =
The Universal Commerce Protocol (UCP) is an open standard for AI agent commerce, co-developed by Google, Shopify, Etsy, Wayfair, Target, and Walmart. It defines how AI agents discover stores, browse products, and complete purchases. See [ucp.dev](https://ucp.dev).

= My hosting provider blocks bot traffic. Will AI agents be able to reach my store? =
Yes. The Shopwalk trust layer handles this automatically. AI agents transact through Shopwalk's trusted channel — your host sees a known Shopwalk request, not an anonymous bot. No configuration required on your end.

= What data does this plugin send to Shopwalk? =
Product name, description, price, stock status, images, categories, and permalink. No customer data — names, addresses, and payment information never leave your WooCommerce store. See the External Services section below.

= Can I use the UCP endpoints without syncing to Shopwalk? =
Yes. Set an Inbound API Key in the plugin settings and disable sync. Your store becomes a standalone UCP server — any UCP-compatible agent can transact directly, but your products won't appear in Shopwalk AI search results.

= Does it work with WooCommerce HPOS? =
Yes. Full High-Performance Order Storage compatibility is declared.

= Will it affect my store's frontend or performance? =
No. The plugin is entirely server-side — no scripts, no styles, no frontend changes. Minimal performance impact.

= Does it work with my theme? =
Yes. The plugin adds no frontend UI to your store. Fully theme-agnostic.

== External Services ==

This plugin communicates with the **Shopwalk API** (https://api.shopwalk.com) for the following purposes:

**On activation:**
Registers your store to obtain a free partner ID.
Data sent: `site_url`, `wp_version`, `wc_version`

**On product save/delete:**
Syncs product catalog data to Shopwalk.
Data sent: product name, description, price, stock status, images, categories, permalink.

**Periodically (via WP-Cron):**
Checks license status and plugin update availability.
Data sent: site URL, current plugin version.

**On plugin deletion:**
Notifies Shopwalk to purge your store's data.
Data sent: `plugin_key`, `site_url`

**No customer personal data is ever sent to Shopwalk.** Customer names, addresses, and payment information never leave your WooCommerce store. All payment processing and order management remains entirely within WooCommerce.

* [Shopwalk Privacy Policy](https://shopwalk.com/privacy)
* [Shopwalk Terms of Service](https://shopwalk.com/terms)

== Screenshots ==

1. **WooCommerce → Shopwalk dashboard** — sync status, AI request count, and UCP health at a glance.
2. **Settings page** — configure sync, catalog API, checkout API, and inbound API key.
3. **UCP status panel** — live check of all UCP endpoints on your store.

== Changelog ==

= 1.13.0 =
* Updated all documentation to reflect UCP as the open industry standard (co-developed by Google, Shopify, Etsy, Wayfair, Target, Walmart)
* Clarified Shopwalk's role as a trusted implementation and hosting trust layer
* Minor cleanup

= 1.12.0 =
* CDN Store Boost improvements
* Heartbeat reliability fixes

= 1.1.0 =
* Full UCP v1.1.0 implementation: coupon support, availability endpoint, catalog filters, order tracking, refund/cancel API, guest checkout assurance, auto-registration, `/.well-known/ucp` discovery

= 1.0.0 =
* Initial release: product sync, UCP checkout sessions, order webhooks

== Upgrade Notice ==

= 1.13.0 =
Documentation update only — no functional changes.
