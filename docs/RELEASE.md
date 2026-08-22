# Release procedure

Release and packaging checklist for the UniPayment PrestaShop module.

---

## 1. Current release state

| Item               | Value                            |
| ------------------ | -------------------------------- |
| Module version     | **2.0.0** (`unipayment.php`)     |
| Project status     | **Development / pre-production** |
| Production release | **None yet**                     |

There is no published changelog for production releases in this repository.

---

## 2. Development version policy

During active development:

- Module version remains **`2.0.0`**
- **No upgrade scripts** are maintained
- Schema changes on the controlled test environment are applied via **module reinstall**

See [`INSTALLATION.md`](INSTALLATION.md) §8.

---

## 3. First production release preparation

Before tagging the first production release:

### Version and packaging

- [ ] Finalize semantic version (bump from `2.0.0`)
- [ ] Update version in `unipayment.php` and any version constants
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] Package includes `vendor/`, all module assets, translations
- [ ] Artifact review (§6) — no secrets, tests, or IDE files

### Clean install test

- [ ] Fresh PrestaShop install → module install
- [ ] Configure UNICID/secret
- [ ] All 8 module tables created
- [ ] Custom order states installed

### Functional checklist

- [ ] Process 1 order (SmartUCF if configured)
- [ ] Process 2 order (EGN validation, confirmation flow)
- [ ] Customer email: no EGN (both processes)
- [ ] Process 2 admin email: full EGN present
- [ ] CP create order
- [ ] Shop-cache push/pull
- [ ] Signed callbacks (shop-cache, bank status, SmartUCF debug log)
- [ ] Replay rejection for duplicate nonce
- [ ] SmartUCF session + certificate sync
- [ ] Bank status display + optional rejection sync
- [ ] Multishop bank-status scoping (if applicable)
- [ ] Checkout double-submit / lock behavior
- [ ] PII retention redaction (180-day policy)
- [ ] Uninstall purge (`ModuleDataPurger`)

### Quality gates

- [ ] PHP syntax check on changed files: `php -l`
- [ ] Test suite (module `tests/` scripts)
- [ ] `git diff --check`
- [ ] Search diff for accidental secret/EGN leakage

---

## 4. Cross-project compatibility

The PrestaShop module shares the **signed CP → module request protocol** with:

- Control Panel (`wiley68/uni.avalonbg.com`)
- WooCommerce reference module (`wiley68/uni-woo`)

A PS module release that changes inbound signature rules, header names, canonical string, or replay semantics requires **coordinated** CP (and Woo, if applicable) updates.

Outbound CP API contract (`/api/v1/auth/*`, `/shop`, `/orders`, `/orders/status`, SSL certificate endpoints) must remain compatible with the deployed Control Panel.

Do not treat unrelated repository SHAs as permanent compatibility pins in documentation or code comments.

---

## 5. Upgrade procedure after first production release

**Future rule (not yet implemented):**

> After the first production release, schema and configuration changes **must** use explicit versioned upgrade procedures (`upgrade/upgrade-x.y.z.php` or equivalent PrestaShop mechanism) and **must not** rely on reinstall in production.

Current repository contains **no** upgrade scripts by design (development-only).

---

## 6. Release artifact review

Exclude from distributable ZIP:

- `.git/`, `.github/`, `.vscode/`, `.idea/`
- `tests/`, development fixtures
- `.env`, local secrets, real `keys/*.pem` private material
- Log files, temporary files
- `AGENTS.md` or internal-only docs (optional — include if desired for partner handover)

Include:

- `vendor/` (production autoload)
- `translations/` export if shipping XLIFF
- `mails/` templates

---

## 7. Tag / release checklist

Generic Git release steps (execute manually when ready):

1. Ensure working tree clean and tests pass
2. Bump version in `unipayment.php`
3. Commit with release message
4. Create annotated tag: `git tag -a vX.Y.Z -m "Release X.Y.Z"`
5. Push tag to remote
6. Attach packaged `unipayment-X.Y.Z.zip` to release artifact storage
7. Document CP minimum version compatibility in release notes (operational, not hardcoded SHA)

**AUD-015 does not execute tagging.**

---

## Related documents

- [`INSTALLATION.md`](INSTALLATION.md) — deploy and verify
- [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md) — security checklist context
- [`../README.md`](../README.md) — entry point
