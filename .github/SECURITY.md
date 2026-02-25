# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| 1.1.x | ✅ Active |
| < 1.1 | ❌ No longer supported |

We strongly recommend running the latest version.

---

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Email **security@shopwalk.com** with:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested remediation

We will acknowledge your report within **48 hours** and aim to release a patch within **7 days** for critical issues.

---

## Responsible Disclosure

We follow responsible disclosure:

1. You report to us privately
2. We confirm and investigate
3. We develop and test a fix
4. We release the fix and credit you (unless you prefer anonymity)
5. Details may be published after 90 days or after a fix is widely deployed

We do not pursue legal action against researchers who follow this policy.

---

## Scope

**In scope:**

- Authentication or authorization bypass in UCP endpoints
- Remote code execution
- Arbitrary file read/write
- SQL injection
- Cross-site scripting (XSS) in admin settings UI
- Sensitive data exposure (API keys, customer data)
- Insecure direct object reference on orders or checkout sessions

**Out of scope:**

- Denial of service attacks
- Social engineering
- Vulnerabilities in third-party plugins or WooCommerce itself
- Bugs in features not yet released

---

## Security Design Notes

For contributors and security researchers:

- The plugin's outbound API key (`sw_site_...`) is scoped to a single domain. It cannot be used to access other merchants' data.
- The inbound API key protects UCP endpoints on the merchant's store. It is stored in `wp_options` — the same security model as WooCommerce's own payment credentials.
- All inbound requests are validated with WordPress nonces where applicable.
- No customer PII is sent to Shopwalk's API — only product catalog data.
- All strings are sanitized on input (`sanitize_text_field`, `wp_kses`, etc.) and escaped on output (`esc_html`, `esc_attr`, etc.).
