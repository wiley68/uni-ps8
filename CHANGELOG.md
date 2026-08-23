# Changelog

All notable production releases of the UniPayment PrestaShop 8 module are documented here.

## 2.0.0 — 2026-08-23

First production release of this development line. There are no historical upgrade scripts from earlier development builds.

### Highlights

- Native PrestaShop 8 UniCredit financing module (`unipayment`)
- Product and cart credit calculators
- Checkout payment method with server-side validation of financing terms
- Product and cart popup direct-financing flows
- Silent Product Buy (add to cart without native modal, selected scheme preserved)
- Process 1: Control Panel order + SmartUCF session + bank redirect
- Process 2: native PrestaShop confirmation (no SmartUCF)
- Control Panel shop-configuration cache, pull, and signed push replacement
- Admin UniCredit status column and order leasing details
- Customer-safe failure handling after a PrestaShop order exists (no resubmit)
- Process 2 native `order_conf` does not claim bank-sent status before Control Panel create succeeds

### Security

- Signed Control Panel → module callbacks (`X-UniPayment-Timestamp`, `X-UniPayment-Nonce`, `X-UniPayment-Signature`)
- HMAC-SHA256 replay protection
- Multishop bank-status authorization by order reference + `id_shop`
- Database-backed atomic checkout submit lock
- Encrypted secrets/tokens and 180-day financing-snapshot PII retention
- Process 1: EGN is not sent to customer or admin financing email
- Process 2: admin may receive EGN; customer does not

### Operational notes

- Requires Control Panel UNICID, shared secret, and HTTPS reachability
- SmartUCF client certificates are synchronized from Control Panel into `keys/` (not shipped in the package)
- Bank status after redirect is updated asynchronously by Control Panel (daily check → shop push)
- Packaged ZIP must include Composer `vendor/` autoload (`composer install --no-dev --optimize-autoloader`)
- Default safe verification: `composer test` (never the legacy recursive PHP runner)

### Upgrade note

This is the first production release. Install on a clean shop or replace a development checkout. Do not expect `upgrade/upgrade-*.php` scripts for pre-2.0.0 development schema iterations.
