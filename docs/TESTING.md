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
composer test:js
```

The safe suite runs unit/contract/static tests and skips:

- runtime DB integration tests
- destructive uninstall/purge tests

The runner reports `passed`, `skipped`, `partial`, and `failed` separately. `skipped`
means the runner ended with a SKIP result; `partial` means its unit assertions ran
but an optional runtime section was skipped. Skip reasons and filenames are printed.
Runtime sections remain disabled in the default suite. The JavaScript script runs
both the product helper checks and the consent lifecycle/race harness.

### 2.0.4 release verification — 2026-11-19

UNI-PS8-ADS-001 removes the merchant advertisement switch and ignores legacy local advertising values at runtime. CP visibility, cache TTL, push replacement, manual refresh and fallback remain authoritative and unchanged. No schema migration or financial/payment flow change is included. Owner browser acceptance on PrestaShop 8.2.5: **PS01–PS08, 8/8 PASS**; UNI-PS8-ADS-002 audit: **READY**.

- Advertising hooks: **26/26 isolated scenarios PASS**, with in-memory DB/configuration and intercepted DNS/cURL only.
- Release safe suite: **92 passed, 7 skipped, 2 partial, 0 failed**, including `DistributionPackageTest.php` with disposable packaging fixtures.
- Both JavaScript tests PASS, including **76 consent assertions**; changed/new PHP syntax, Composer validation and `git diff --check` PASS.
- ADS-001/ADS-002 baseline: **91 passed, 8 skipped, 2 partial, 0 failed**. The eighth skip was ZIP generation excluded by those tasks; release verification now executes and passes that test.

The seven remaining skips are the runtime suites for CP status-sync CAS empty strings, order bank-status persistence, Product Buy checkout preselection, guest identity, popup submission repository, SmartUCF lifecycle repository, and destructive uninstall/purge. The two partial results are the checkout preference and popup preselect operation-guard tests: unit assertions pass; live runtime sections are skipped. No installed-shop DB integration or bank operation is executed.

The restored production `vendor/` lacks the `Tests\Support` dev autoload mapping. Verification uses a temporary `auto_prepend_file` autoloader through `PHP_INI_SCAN_DIR`; it leaves installed `vendor/` unchanged. Test fixtures and the bootstrap live under a private system temporary directory and are removed afterward. `composer test:verify-dev-state` is not run because it bootstraps the installed shop.

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
php tools/verify-distribution.php dist/ps8_uni_2.0.5.zip
```

`DistributionPackageTest.php` is also included in `composer test`. It builds in a private disposable checkout under the system temporary directory with a locked offline dev-only dependency fixture. The checkout reads existing Git objects without creating commits or changing the live Git index. No PrestaShop bootstrap or DB writes occur. The exclusion cases use synthetic credential and certificate fixtures.

The suite checks a common current UTC timestamp at DOS two-second precision in every raw local and central header, the matching manifest epoch, and CRC-32 against decompressed bytes. Successive builds use different PHP bytes in separate DOS intervals and compare their ordered inventories and manifest hashes. Historical and invalid `SOURCE_DATE_EPOCH`, Git commit dates and source mtimes do not determine the archive times. The tests cover process timezone restoration with a different PHP default timezone, including on timestamp assignment, native ZIP close and post-generation verification failures.

Info-ZIP independently tests and extracts the fixture archives under UTC with `UNZIP` and `UNZIPOPT` removed. The suite checks the extracted PHP bytes and mtimes, timestamp-preserving placement and ordinary copying. It also covers exact PS8 filename/version and root layout, runtime inventory and byte parity, executable production autoload, exclusions, source/lockfile preservation and existing generated vendor preservation in the disposable checkout. It checks rejection of missing, unreadable, symlinked and ignored runtime sources and invalid archives, as well as staging cleanup and byte-identical preservation of the previous successful ZIP.

The build CLI requires Git, Composer, PHP SimpleXML and PHP zip with `ZipArchive::setMtimeName`. The packaging regression also uses Info-ZIP `unzip` and `cp`. The module's runtime requirement remains PHP ≥ 7.4. `dist/` and generated archives remain ignored and uncommitted. See [`RELEASE.md`](RELEASE.md) for artifact handling and destination mtime and OPcache checks.
