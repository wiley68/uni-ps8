# Release procedure

Release and packaging checklist for the UniPayment PrestaShop module.

---

## 1. Current release state

| Item           | Value                                      |
| -------------- | ------------------------------------------ |
| Module version | **2.0.1** (`unipayment.php`, `config.xml`) |
| Project status | **Post-release remediation**                |
| Release notes  | [`../CHANGELOG.md`](../CHANGELOG.md)       |

`2.0.0` remains the first production version. `2.0.1` is the canonical-amount and order-status lifecycle remediation.

---

## 2. Version policy

- Module version is **`2.0.1`** for this release
- Version metadata must stay consistent in `unipayment.php` and `config.xml`
- **No historical upgrade scripts** for development-only schema iterations
- After this release, future schema/configuration changes **must** use versioned PrestaShop upgrade files (`upgrade/upgrade-x.y.z.php`)

See [`INSTALLATION.md`](INSTALLATION.md) §8.

---

## 3. Production release verification

### Version and packaging

- [x] Remediation version is **2.0.1**
- [x] Version in `unipayment.php` and `config.xml`
- [ ] `composer install --no-dev --optimize-autoloader` in the package staging tree
- [ ] Package includes `vendor/`, module assets, translations, operator docs
- [ ] Artifact review (§6) — no secrets, `keys/`, tests, or IDE files

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

Canonical bank status IDs and labels must stay Woo-compatible. Do not invent PS8-only status IDs.

Do not treat unrelated repository SHAs as permanent compatibility pins in documentation or code comments.

---

## 5. Upgrade procedure after first production release

**Future rule:**

> After the first production release, schema and configuration changes **must** use explicit versioned upgrade procedures (`upgrade/upgrade-x.y.z.php` or equivalent PrestaShop mechanism) and **must not** rely on reinstall in production.

This 2.0.0 tree contains **no** upgrade scripts. That is intentional: development used uninstall/reinstall, and there is no released prior version to migrate from.

---

## 6. Release artifact review

Distributable archive name:

```text
unipayment-2.0.1.zip
```

Archive root must be:

```text
unipayment/
  unipayment.php
  ...
```

Exclude from distributable ZIP:

- `.git/`, `.github/`, `.cursor/`, `.vscode/`, `.idea/`
- `tests/`, development fixtures (including `tests/fixtures/ssl/`)
- `.env`, local secrets, runtime `keys/` private material
- Log files, temporary files, coverage, `dist/`
- `AGENTS.md` (internal agent instructions)

Include:

- `vendor/` (production autoload from `composer install --no-dev --optimize-autoloader`)
- `src/`, `controllers/`, `views/`, `mails/`, `config.xml`, `composer.json`, `composer.lock`
- Operator docs: `README.md`, `CHANGELOG.md`, `docs/` except historical-only files if omitted for size
- `translations/` export if shipping XLIFF

`composer.json` declares **no third-party runtime packages**. `vendor/` is still required because the module loads `vendor/autoload.php` for the PSR-4 `src/` autoloader.

---

## 7. Tag / release checklist

1. Working tree contains only intentional release files
2. Safe tests pass
3. Create **one** release-preparation commit
4. Create annotated local tag: `git tag -a v2.0.1 -m "UniPayment 2.0.1"`
5. Do **not** push, publish a GitHub Release, or upload the package unless explicitly requested
6. Attach packaged `unipayment-2.0.1.zip` when distribution is approved

---

## Related documents

- [`../CHANGELOG.md`](../CHANGELOG.md) — operator-facing release notes
- [`INSTALLATION.md`](INSTALLATION.md) — deploy and verify
- [`TESTING.md`](TESTING.md) — safe vs destructive suites
- [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md) — security checklist context
- [`../README.md`](../README.md) — entry point
