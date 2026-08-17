# UniPayment

Native PrestaShop 8 module skeleton for the UniCredit Credit Calculator / purchases on credit integration.

The repository root is the module root and the module technical name is `unipayment`.

## Current scope

Phase 2 provides the installable module foundation, local configuration, and a
native Control Panel API client. It supports login, token refresh, logout, shop
retrieval, order creation, and order-status updates. Credentials and Bearer
tokens are encrypted at rest with the PrestaShop shop encryption key.

The current scope intentionally contains no payment option, calculator,
configuration snapshot cache, SmartUCF integration, frontend behavior, or
business database tables.

## Development

Install the Composer autoloader from the module root:

```bash
composer install
```

The production artifact must include the generated `vendor/autoload.php`.

## Translations

The module uses the PrestaShop 8 translation system and can be translated from
**International > Translations > Installed modules translations**.

All new customer-facing and administrator-facing wording must follow the rules
in [`docs/TRANSLATIONS.md`](docs/TRANSLATIONS.md).
