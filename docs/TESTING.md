# Testing

UniPayment CLI tests run directly against the module checkout in the PrestaShop test shop filesystem. Because the repository lives inside a **live dev installation**, the default test workflow must never purge module data or drop module tables.

## Safe default verification

Use this after normal code changes:

```bash
composer test
```

Equivalent:

```bash
php tests/run.php safe
node tests/Product/ProductCalculatorJsTest.js
```

The safe suite runs unit/contract/static tests and skips:

- runtime DB integration tests
- destructive uninstall/purge tests

## Runtime integration (non-destructive)

Explicit opt-in for tests that bootstrap PrestaShop and mutate scoped temporary records:

```bash
composer test:runtime
```

Examples:

- guest identity runtime
- popup submission repository integration
- SmartUCF lifecycle repository integration
- silent Product Buy runtime integration
- order bank status runtime persistence

These tests must not call `ModuleDataPurger::purge()` and must not uninstall the module.

## Destructive integration

Never run against the normal dev shop database (`presta8`).

Required **both**:

```bash
UNIPAYMENT_ALLOW_DESTRUCTIVE_DB_TESTS=1
UNIPAYMENT_TEST_DATABASE=1
```

And the connected database must be clearly designated as a test database:

- database name contains `_test` or `_testing`, or
- `UNIPAYMENT_TEST_DB_NAME` matches `_DB_NAME_` exactly

Command:

```bash
composer test:destructive
```

Current destructive coverage:

- `tests/Uninstall/Aud006ModuleDataPurgerDbTest.php`

Do **not** run `find tests -name '*Test.php' -exec php {} \;` as the default verification command. That legacy pattern executes destructive tests against the live dev DB.

## Dev-state preservation check

After changing test infrastructure:

```bash
composer test:verify-dev-state
```

This captures a non-sensitive module fingerprint, runs the safe suite, and asserts persistent module configuration/tables are unchanged.

## Optional isolated test database

For destructive integration, prefer a disposable PrestaShop database such as `presta8_test` with its own `parameters.php` / shop bootstrap. Do not clone production or shared dev data automatically.
