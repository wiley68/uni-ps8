# UniPayment

Native PrestaShop 8 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                        |
| --------------------- | ---------------------------- |
| Module technical name | `unipayment`                 |
| Current version       | `2.0.2`                      |
| Repository root       | Module root (this directory) |
| Current state         | First production release     |

The repository is a working PrestaShop module, not a skeleton. Install, calculators, checkout, order orchestration, configuration cache, SmartUCF, signed inbound APIs, and persistence are implemented.

## Current capabilities

Verified against the current codebase:

- **Homepage / promotional advertising** — optional homepage content when advertising is enabled and shop configuration allows it (`HomepageAdvertisingPresenter`)
- **Product financing calculator** — AJAX product calculator and popup apply flow
- **Cart financing calculator** — cart scheme intersection and checkout selection
- **Checkout payment method** — `PaymentModule` / `paymentOptions` hook
- **Process 1** — SmartUCF online session after successful CP order creation (`uni_proces = 0`)
- **Process 2** — EGN/second phone required; no SmartUCF redirect; order confirmation flow (`uni_proces = 1`)
- **Control Panel integration** — authentication, shop configuration pull, order create/status, SSL certificate sync
- **Shop configuration cache** — local snapshot with TTL and CP push replacement
- **Financing order creation** — PrestaShop order + financing snapshot + CP order submission
- **SmartUCF integration** — direct module → SmartUCF session (Process 1, when configured)
- **Bank status callbacks** — signed CP → module push with bank status persistence independent of native order status
- **Merchant order admin** — financing details on order view and order grid column
- **Financing emails** — audience-specific customer/admin leasing application emails
- **Signed inbound module API** — HMAC-signed CP callbacks with replay protection
- **Certificate synchronization** — CP-managed SmartUCF client certificate pair in module `keys/`
- **PII retention** — opportunistic 180-day redaction of snapshot customer/address/sensitive fields
- **Security hardening** — encrypted secrets/tokens, checkout submit lock, durable order-attempt idempotency, multishop bank-status scoping

## Architecture

High-level design, data model, and integration flows:

→ [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)

## Requirements

From `composer.json` and `unipayment.php`:

| Requirement                | Source                                                              |
| -------------------------- | ------------------------------------------------------------------- |
| PHP                        | `>= 7.4` (`composer.json`)                                          |
| PrestaShop                 | `8.0.0` minimum (`ps_versions_compliancy`)                          |
| PHP **curl**               | Required at runtime (`CurlHttpTransport`)                           |
| PHP **openssl**            | Used for SmartUCF certificate validation (not explicitly checked)   |
| PHP **json**               | Used throughout                                                     |
| HTTPS                      | Required for module API controllers (`$ssl = true`) and SmartUCF    |
| MySQL/MariaDB              | PrestaShop database with module-owned tables on install             |
| Control Panel connectivity | Outbound HTTPS to CP API (default base URL in `ControlPanelClient`) |

Exact production PHP/PrestaShop upper bounds are not formally declared beyond PrestaShop's `_PS_VERSION_` max in the module constructor.

## Installation

Short path:

```bash
cd modules/unipayment
composer install
```

Then install the module from the PrestaShop Back Office (Modules → Module Manager).

Full prerequisites, packaged artifact layout, CP setup, and verification checklist:

→ [`docs/INSTALLATION.md`](docs/INSTALLATION.md)

## Configuration

Merchant-facing module settings (Back Office → Modules → UniPayment → Configure):

| Setting               | Purpose                                           |
| --------------------- | ------------------------------------------------- |
| Enable module         | Master on/off (`UNIPAYMENT_ENABLED`)              |
| UNICID                | Shop identifier for Control Panel authentication  |
| Shared secret         | CP/module shared secret; stored encrypted at rest |
| Advertising enabled   | Homepage promotional content gate                 |
| Debug enabled         | Diagnostic behavior (see code for current scope)  |
| Product button action | Add to cart vs buy (`add_to_cart` / `buy`)        |
| Button top spacing    | Product page button offset (0–200 px)             |

Business financing rules (KOP, coefficients, Process 1/2 flag, SmartUCF endpoints, consents, etc.) come from the **Control Panel shop configuration snapshot**, not from editable duplicate settings in the module.

Do not commit real UNICID, secrets, certificates, or tokens.

## Security and operations

→ [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)

Operational recovery runbook:

→ [`docs/RECOVERY.md`](docs/RECOVERY.md)

## Development / Composer

This repository is a **development checkout**: run `composer install` at the module root to generate `vendor/autoload.php`.

A **packaged production artifact** must include:

- `vendor/` (with optimized autoloader)
- `unipayment.php`, `composer.json`, `src/`, `controllers/`, `views/`, `mails/`, `translations/`, and other module assets

The module entry file loads `vendor/autoload.php` when present. Without it, namespaced classes under `src/` will not load.

See [`docs/RELEASE.md`](docs/RELEASE.md) for release and artifact review.

## Translations

→ [`docs/TRANSLATIONS.md`](docs/TRANSLATIONS.md)

## Documentation index

| Document                                                     | Description                                     |
| ------------------------------------------------------------ | ----------------------------------------------- |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)               | Current system design and module layers         |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md)               | Install, deploy, CP setup, verification         |
| [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md) | Secrets, signed API, PII, logging               |
| [`docs/RECOVERY.md`](docs/RECOVERY.md)                       | Operational troubleshooting runbook             |
| [`CHANGELOG.md`](CHANGELOG.md)                               | 2.0.0 release notes                             |
| [`docs/RELEASE.md`](docs/RELEASE.md)                         | Release packaging and version policy            |
| [`docs/TESTING.md`](docs/TESTING.md)                         | Safe vs destructive test commands               |
| [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md) | Historical development plan (not current scope) |
| [`docs/TRANSLATIONS.md`](docs/TRANSLATIONS.md)               | Translation conventions                         |
