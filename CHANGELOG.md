# Changelog

All notable changes to Shopwalk AI are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versions follow [Semantic Versioning](https://semver.org/).

---

## [1.1.0] — 2026-02-25

### Added
- Full UCP v1.1.0 spec implementation
- Coupon / promotion code support in checkout sessions
- Product availability endpoint (`/availability`) — real-time stock and pricing per variant
- Catalog filters: `min_price`, `max_price`, `in_stock` on products endpoint
- Order status and tracking endpoint with UCP-standardized status codes
- Dedicated refund endpoint (partial and full refunds)
- Guest checkout assurance — sessions always work as guest even if store requires accounts
- Session expiry — checkout sessions auto-expire after 24 hours
- Auto-registration — one-click store connection with auto-provisioned free API key
- Connect screen shown on first activation (before any key is set)
- Backward-compatible key migration (from `shopwalk_wc_license_key` / `shopwalk_wc_shopwalk_api_key`)
- `/.well-known/ucp` dual-namespace discovery endpoint (`/shopwalk/v1` and `/v1`)
- `X-UCP-Version: 1.0` response header on all UCP endpoints
- AI Commerce Status dashboard in plugin settings
- Test Connection and Sync Products Now buttons in settings

### Changed
- Plugin renamed to **Shopwalk AI** (slug: `shopwalk-ai`)
- Unified all key options under `shopwalk_wc_plugin_key`
- API key header changed from `Authorization: Bearer` to `X-API-Key` on ingest calls

### Security
- Pre-submission audit: nonce validation on all AJAX handlers, `sanitize_text_field` on all inputs, `esc_html` / `esc_attr` on all outputs, `gmdate()` instead of `date()`

---

## [1.0.0] — 2025-12-01

### Added
- Initial release
- Basic product sync (create, update, delete)
- UCP checkout sessions (create, fill, complete, cancel)
- Order webhooks (order status notifications to Shopwalk)
- Plugin settings tab in WooCommerce admin
- Uninstall cleanup (`uninstall.php`)
- HPOS compatibility declaration
