# UniPayment

Native PrestaShop 8 module skeleton for the UniCredit Credit Calculator / purchases on credit integration.

The repository root is the module root and the module technical name is `unipayment`.

## Current scope

Phase 1 provides the installable module foundation and local configuration for
the operational enabled flag, UNICID, and encrypted secret. The status panel is
present, but no Control Panel request is performed before Phase 2.

The current scope intentionally contains no payment option, calculator,
Control Panel API client, SmartUCF integration, frontend behavior, or business
database tables.

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
