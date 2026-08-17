# UniPayment

Native PrestaShop 8 module skeleton for the UniCredit Credit Calculator / purchases on credit integration.

The repository root is the module root and the module technical name is `unipayment`.

## Current scope

Phase 0 provides only the installable module foundation. It intentionally contains no payment option, calculator, Control Panel communication, SmartUCF integration, frontend behavior, or business database tables.

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
