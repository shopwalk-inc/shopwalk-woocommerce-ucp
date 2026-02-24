=== Shopwalk AI ===
Contributors: shopwalkinc
Tags: ai shopping, product sync, woocommerce, ai commerce, ai checkout
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-enable your WooCommerce store in minutes. Let AI agents discover, browse, and buy from your store automatically.

== Description ==

**Shopwalk AI** makes your WooCommerce store visible and accessible to AI shopping agents. Install the plugin, enter your license key, and your store is instantly open to AI-powered discovery, browsing, and checkout — no developer required.

= What It Does =

Modern shoppers increasingly use AI to find and buy products. Shopwalk AI bridges the gap between your WooCommerce store and the AI commerce layer, so your products surface in AI-driven search results and AI agents can complete purchases on behalf of shoppers.

= Features =

* **Automatic product sync** — Your entire catalog syncs to Shopwalk automatically. New products, price changes, and inventory updates propagate in real time.
* **AI discovery** — Your products surface in AI-powered searches. Shopwalk AI understands natural language and context to connect the right shoppers with your store.
* **AI browsing** — AI agents can browse your full catalog via a structured REST API, reading product details, categories, pricing, and availability.
* **AI checkout** — AI agents can create checkout sessions and place orders directly through your WooCommerce store using the Universal Commerce Protocol (UCP). No redirects, no middleman, no transaction fees.
* **Order webhooks** — Real-time order status notifications keep AI agents in sync with your fulfillment workflow.
* **Simple setup** — Connect in minutes with your Shopwalk license key. No technical knowledge required.

= Getting Started =

1. Install and activate the plugin
2. Go to **WooCommerce → Settings → Shopwalk**
3. Enter your Shopwalk license key (purchase at [shopwalk.com](https://shopwalk.com))
4. Your products will begin syncing immediately

= Privacy & External Services =

This plugin communicates with the Shopwalk API (`api.shopwalk.com`) for the following purposes:

* **Product sync** — sends product data (name, description, price, images, inventory status) to Shopwalk for AI indexing when products are saved or updated.
* **License activation** — sends your site URL and license key to Shopwalk to validate your license.
* **Update checks** — periodically checks for plugin updates via the Shopwalk update API.
* **Order webhooks** — sends order status data to Shopwalk when orders placed via AI checkout change status.

No customer personal data (names, addresses, payment info) is ever sent to Shopwalk. All payment processing and order management remains entirely within your WooCommerce store.

By using this plugin, you agree to the [Shopwalk Terms of Service](https://shopwalk.com/terms) and [Privacy Policy](https://shopwalk.com/privacy).

== Installation ==

1. Upload the `shopwalk-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Go to **WooCommerce → Settings → Shopwalk** and enter your license key
4. Your products will start syncing to Shopwalk automatically

== Frequently Asked Questions ==

= How do I get a license key? =

Purchase a license key at [shopwalk.com](https://shopwalk.com). Each license covers one WooCommerce store.

= What products get synced? =

All published WooCommerce products including simple, variable, and grouped products. Draft and private products are not synced.

= How often does the catalog sync? =

Products sync in real time when created or updated. You can also trigger a full manual sync from the plugin settings page.

= Does Shopwalk take a commission on sales? =

No. AI agents complete purchases through your own WooCommerce checkout. Shopwalk does not sit in the payment flow and does not charge transaction fees.

= What is the Universal Commerce Protocol (UCP)? =

UCP is an open protocol that lets AI agents interact with e-commerce stores in a structured way — browsing products, creating checkout sessions, and placing orders. This plugin implements the UCP server on your WooCommerce store.

= Is my customer data shared with Shopwalk? =

No. Only product catalog data is sent to Shopwalk. Customer names, addresses, and payment information never leave your WooCommerce store.

= Does this work with WooCommerce High-Performance Order Storage (HPOS)? =

Yes. Shopwalk AI is fully compatible with WooCommerce HPOS (Custom Order Tables).

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic product catalog sync
* Real-time inventory and price updates
* AI discovery integration
* AI browsing REST API
* AI checkout via Universal Commerce Protocol (UCP)
* Order webhook support
* HPOS compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release.
