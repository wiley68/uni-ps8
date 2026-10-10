# Testing

UniPayment CLI tests run directly against the module checkout in the PrestaShop test shop filesystem. Because the repository lives inside a **live dev installation**, the default test workflow must never purge module data or drop module tables.

## Safe default verification

Use this after normal code changes and as the **canonical release verification** command:

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

## Distribution packaging regression

`tests/Api/ControlPanelDestinationTest.php` changes only the canonical root declaration in memory in isolated CLI processes. It exercises the three deployment hosts and a future host through actual module factories/configuration services, all eight CP endpoints, legacy/cross-origin token/cache rejection, invalid-root rejection, DNS policy and cURL pinning. DNS, cURL and platform boundaries are test doubles; no live CP or database is contacted by this regression. Existing order/replay/status/certificate suites cover their unchanged lifecycle semantics.

```bash
composer test:package
composer package
php tools/verify-distribution.php dist/ps8_uni_2.0.3.zip
```

`DistributionPackageTest.php` is also included in `composer test`. It builds twice in a disposable checkout under the system temporary directory, with a locked offline dev-only dependency fixture. The checkout reads existing Git objects without creating commits or changing the live Git index. No PrestaShop bootstrap or DB writes occur; synthetic credential/certificate exclusion fixtures never touch local shop secrets.

Coverage includes exact PS8 filename/version and root layout, complete runtime inventory and byte parity, executable production autoload, absence of dev dependencies/test autoload/repository content, omission of unused PS9 environment/key files, identical ZIP bytes for repeated builds, source/vendor/lockfile preservation, missing/unreadable/symlink runtime source failures, ignored-runtime rejection, archive tampering/layout rejection, staging cleanup after failed verification and preservation of the previous successful ZIP.

The build CLI requires Git, Composer, PHP SimpleXML and PHP zip with `ZipArchive::setMtimeName`; this does not change the module's PHP ≥ 7.4 runtime requirement. `dist/` and generated archives remain ignored and uncommitted. See [`RELEASE.md`](RELEASE.md) for artifact sensitivity and reproducibility limits.
