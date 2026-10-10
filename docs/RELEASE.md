# Release procedure

Release and packaging checklist for the UniPayment PrestaShop module.

---

## 1. Current release state

| Item           | Value                                      |
| -------------- | ------------------------------------------ |
| Module version | **2.0.3** (`unipayment.php`, `config.xml`) |
| Project status | **Satrudnik failure notification**         |
| Release notes  | [`../CHANGELOG.md`](../CHANGELOG.md)       |

`2.0.0` remains the first production version. `2.0.1` is the canonical-amount and order-status lifecycle remediation. `2.0.2` is the scheme presentation, Cart representative, and Checkout parity release. `2.0.3` adds Satrudnik operational failure email for canonical bank-send failures.

---

## 2. Version policy

- Module version is **`2.0.3`** for this release
- Version metadata must stay consistent in `unipayment.php` and `config.xml`
- **No historical upgrade scripts** for development-only schema iterations
- After this release, future schema/configuration changes **must** use versioned PrestaShop upgrade files (`upgrade/upgrade-x.y.z.php`)

See [`INSTALLATION.md`](INSTALLATION.md) §8.

---

## 3. Production release verification

### Version and packaging

- [x] Current module version is **2.0.3**
- [x] Version in `unipayment.php` and `config.xml`
- [ ] `composer package` installs production Composer dependencies in a private staging tree
- [ ] Package includes production `vendor/`, all runtime source, module assets, mail templates and translations
- [ ] Artifact verifier (§6) — no shop credentials, local `keys/`, tests, repository or IDE files

### Quality gates

- [ ] Canonical safe suite: `composer test`
- [ ] Dev-state preservation: `composer test:verify-dev-state` (when run on the live checkout)
- [ ] PHP syntax check: `php -l`
- [ ] `composer validate`
- [ ] `git diff --check`
- [ ] Search release tree for accidental secret/EGN/private-key leakage

Do **not** use `find tests -name '*Test.php' -exec php {} \;`. That legacy pattern can run destructive tests against the live shop database. See [`TESTING.md`](TESTING.md).

### Functional acceptance

Manual storefront acceptance for 2.0.0 is recorded as passed (product/cart/checkout, Process 1/2, popup flows, guest and logged customers, CP and SmartUCF failure UX, asynchronous bank-status sync). Re-run only when a release-blocking defect is fixed.

---

## 4. Cross-project compatibility

The PrestaShop module shares the **signed CP → module request protocol** with:

- Control Panel (`wiley68/uni.avalonbg.com`)
- WooCommerce reference module (`wiley68/uni-woo`)

A PS module release that changes inbound signature rules, header names, canonical string, or replay semantics requires **coordinated** CP (and Woo, if applicable) updates.

Outbound CP API contract (`/api/v1/auth/*`, `/shop`, `/orders`, `/orders/status`, SSL certificate endpoints) must remain compatible with the deployed Control Panel.

Canonical **standard bank status** labels and rules (exactly four initial public statuses; Process 1/2 CP failure consistency; raw later SmartUCF statuses; internal vs public) are documented in [`docs/ARCHITECTURE.md`](ARCHITECTURE.md) §3.1. Machine `status_id` values must stay Woo/CP-compatible. Do not invent PS8-only public status labels or a fifth initial public status.

Do not treat unrelated repository SHAs as permanent compatibility pins in documentation or code comments.

---

## 5. Upgrade procedure after first production release

**Future rule:**

> After the first production release, schema and configuration changes **must** use explicit versioned upgrade procedures (`upgrade/upgrade-x.y.z.php` or equivalent PrestaShop mechanism) and **must not** rely on reinstall in production.

This 2.0.0 tree contains **no** upgrade scripts. That is intentional: development used uninstall/reinstall, and there is no released prior version to migrate from.

---

## 6. Release artifact review

Build from the module's Git checkout:

```bash
composer package
```

Distributable archive name (automatically derived from the single literal `$this->version` declaration in `unipayment.php`):

```text
dist/ps8_uni_<MODULE_VERSION>.zip
```

For the current release: `dist/ps8_uni_2.0.3.zip`. Missing, computed or ambiguous version declarations fail the build; packaged module and XML versions must agree with the filename.

Archive root must be:

```text
unipayment/
  unipayment.php
  ...
```

Exclude from distributable ZIP:

- `.git/`, `.github/`, `.cursor/`, `.vscode/`, `.idea/`
- `tests/`, development fixtures (including `tests/fixtures/ssl/`)
- `.env`, shop credentials, all runtime `keys/` and `secrets/` material
- Log files, temporary files, coverage, `dist/`
- `AGENTS.md`, `tools/`, `README.md`, `CHANGELOG.md`, `docs/` and other repository-only content
- PS9-only `config/environment.php`, environment templates and secret-loading files

Include:

- `vendor/` (production dependencies/autoload from staged `composer install --no-dev --prefer-dist --optimize-autoloader --no-plugins --no-scripts`)
- `unipayment.php`, `index.php`, `logo.png`, `src/`, `controllers/`, `views/`, `mails/`, `config/`, `config.xml`, `upgrade/`, `translations/`
- Production-only `composer.json` and a `package-manifest.json` with file inventory/checksums, source Git SHA, dirty flag and fixed ZIP timestamp

`composer.lock` must exist locally and is copied into staging to reproduce dependency installation. It remains unchanged in source; it is removed from the ZIP after normalizing packaged Composer metadata. The source `vendor/` is never used as package input or modified.

The verifier checks archive layout, paths, symlinks, required files, module/XML version, production autoload/dependency metadata, manifest checksums and byte parity against the current source. Unknown packaged source files are rejected even if the manifest is recomputed. An ignored runtime file in the source directories causes failure rather than silent omission. New non-ignored runtime files are included before they are committed; review the working tree before building. The manifest is an inventory, not a cryptographic signature.

```bash
php tools/verify-distribution.php dist/ps8_uni_2.0.3.zip
```

Verification is against the **current checkout**, including its current Git SHA and local runtime edits; keep matching source when re-verifying an older artifact. A verified archive replaces the previous output only after successful validation. Failed builds remove staging and preserve any previous successful ZIP.

Entries are sorted and use fixed timestamps, permissions and compression. The default timestamp is the HEAD commit timestamp; `SOURCE_DATE_EPOCH` can supply an explicit ZIP-range timestamp. Identical inputs with the same Composer/PHP/zip toolchain produce identical ZIP bytes. Toolchain changes may change generated Composer files or compression output.

`dist/` is local, ignored and must never be added to Git. It receives an Apache `.htaccess` denial file and fresh build directories/ZIPs have private filesystem permissions. PS8 does not use an environment file or separate SmartUCF key file. The ZIP nevertheless contains the existing certificate passphrase constant as part of required runtime source; treat it as a sensitive deployment artifact and do not publish it indiscriminately. See [`INSTALLATION.md`](INSTALLATION.md) §3 for configuration/credential provenance.

`composer.json` declares **no third-party runtime packages**. `vendor/` is still required because the module loads `vendor/autoload.php` for the PSR-4 `src/` autoloader.

---

## 7. Tag / release checklist

1. Working tree contains only intentional release files
2. Safe tests pass
3. Create **one** release-preparation commit
4. Create annotated local tag for the reviewed module version, only when explicitly requested
5. Do **not** push, publish a GitHub Release, or upload the package unless explicitly requested
6. Attach `ps8_uni_<MODULE_VERSION>.zip` when distribution is approved; generated artifacts remain outside Git

---

## Related documents

- [`../CHANGELOG.md`](../CHANGELOG.md) — operator-facing release notes
- [`INSTALLATION.md`](INSTALLATION.md) — deploy and verify
- [`TESTING.md`](TESTING.md) — safe vs destructive suites
- [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md) — security checklist context
- [`../README.md`](../README.md) — entry point
