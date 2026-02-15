# Shopwalk UCP for WooCommerce

A WordPress plugin that implements the [Universal Commerce Protocol (UCP)](https://ucp.dev) for WooCommerce stores, enabling AI shopping agents to discover, browse, checkout, and manage orders.

## What is UCP?

The Universal Commerce Protocol is an open standard that lets AI agents interact with online stores through a standardized API. When a store installs this plugin, any UCP-compatible platform (like [Shopwalk](https://shopwalk.com)) can:

- **Discover** the store via `/.well-known/ucp`
- **Browse** the product catalog
- **Create checkout sessions** and place orders
- **Track orders** and manage returns
- **Receive webhooks** for order status changes

## Installation

1. Upload the `shopwalk-ucp` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Go to **WooCommerce → Settings → Shopwalk UCP** to configure

## Configuration

| Setting | Description |
|---------|-------------|
| **API Key** | Secures checkout/order endpoints. Set this in production. |
| **Enable Catalog** | Allow product browsing via UCP API |
| **Enable Checkout** | Allow checkout session creation |
| **Enable Webhooks** | Send order notifications to platforms |

## API Endpoints

### Discovery
- `GET /.well-known/ucp` — UCP Business Profile (public)

### Catalog (public)
- `GET /wp-json/shopwalk-ucp/v1/products` — List products
- `GET /wp-json/shopwalk-ucp/v1/products/{id}` — Product details
- `GET /wp-json/shopwalk-ucp/v1/categories` — Product categories

### Checkout (authenticated)
- `POST /wp-json/shopwalk-ucp/v1/checkout-sessions` — Create session
- `GET /wp-json/shopwalk-ucp/v1/checkout-sessions/{id}` — Get session
- `PUT /wp-json/shopwalk-ucp/v1/checkout-sessions/{id}` — Update session
- `POST /wp-json/shopwalk-ucp/v1/checkout-sessions/{id}/complete` — Place order
- `POST /wp-json/shopwalk-ucp/v1/checkout-sessions/{id}/cancel` — Cancel
- `GET /wp-json/shopwalk-ucp/v1/checkout-sessions/{id}/shipping-options` — Shipping rates

### Orders (authenticated)
- `GET /wp-json/shopwalk-ucp/v1/orders` — List orders (by email)
- `GET /wp-json/shopwalk-ucp/v1/orders/{id}` — Order details
- `POST /wp-json/shopwalk-ucp/v1/orders/{id}/adjustments` — Request refund

### Webhooks (authenticated)
- `POST /wp-json/shopwalk-ucp/v1/webhooks` — Register webhook
- `GET /wp-json/shopwalk-ucp/v1/webhooks` — List webhooks
- `DELETE /wp-json/shopwalk-ucp/v1/webhooks/{id}` — Remove webhook

## Payment Handlers

The plugin automatically detects active WooCommerce payment gateways and maps them to UCP payment handler IDs:

| WooCommerce Gateway | UCP Handler ID |
|---|---|
| Stripe | `com.stripe` |
| PayPal | `com.paypal` |
| Apple Pay | `com.apple.pay` |
| Google Pay | `com.google.pay` |
| Square | `com.squareup` |

## UCP Spec Version

This plugin implements UCP spec version `2026-01-23`.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 8.0+

## License

GPL-2.0-or-later
