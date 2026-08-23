# Installation and deployment

Guide for installing the UniPayment PrestaShop module in development and preparing a distributable artifact.

Related:

- [`../README.md`](../README.md)
- [`ARCHITECTURE.md`](ARCHITECTURE.md)
- [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md)

---

## 1. Prerequisites

| Requirement               | Notes                                                        |
| ------------------------- | ------------------------------------------------------------ |
| PrestaShop                | **8.0.0+** (`unipayment.php` `ps_versions_compliancy`)       |
| PHP                       | **≥ 7.4** (`composer.json`)                                  |
| PHP curl                  | Required (`CurlHttpTransport` throws if missing)             |
| PHP openssl               | Used for SmartUCF certificate validation                     |
| Composer                  | Required for development checkout and building `vendor/`     |
| HTTPS                     | Module API controllers require SSL; SmartUCF uses TLS        |
| Database                  | Standard PrestaShop MySQL/MariaDB                            |
| Control Panel             | Shop registered in CP with matching UNICID and shared secret |
| Writable module directory | Certificate sync writes to `{module}/keys/`                  |

The module default CP API base URL is `https://uni.avalonbg.com/api/v1` (`ControlPanelClient::DEFAULT_BASE_URL`). Override only if your deployment uses a different CP endpoint (environment-specific).

---

## 2. Development install

Repository root **is** the module root:

```text
{prestashop}/modules/unipayment/
```

Steps:

1. Clone or copy the repository into `modules/unipayment`.
2. Install PHP dependencies:

    ```bash
    cd modules/unipayment
    composer install
    ```

3. In PrestaShop Back Office → **Modules → Module Manager**, find **UniPayment** (technical name `unipayment`) and **Install**.
4. Open module **Configure** and set UNICID, shared secret, and other merchant settings.
5. Verify CP connectivity (shop configuration loads; see §7).

Do not commit `vendor/` secrets, local `keys/` private material, or environment-specific credentials.

---

## 3. Packaged installation

A distributable module ZIP must extract as `unipayment/` (not loose files at archive root). Minimum contents:

```text
unipayment/
  unipayment.php
  composer.json
  composer.lock
  vendor/                 ← required (composer install --no-dev --optimize-autoloader)
  src/
  controllers/
  views/
  mails/
  translations/
  config.xml
  index.php
```

Before packaging:

```bash
composer install --no-dev --optimize-autoloader
```

Review [`RELEASE.md`](RELEASE.md) for artifact hygiene (exclude IDE files, tests, secrets).

Install the ZIP via PrestaShop module upload or by extracting into `modules/unipayment/`.

---

## 4. Module installation (what install does)

`unipayment.php::install()`:

- Registers hooks (product, cart, checkout, admin order, mail, footer, etc.)
- Creates module-owned database tables (8 tables — see [`ARCHITECTURE.md`](ARCHITECTURE.md) §5)
- Installs default configuration keys
- Installs custom PrestaShop order states (awaiting / failed / rejected)
- Ensures certificate directory protection files (non-fatal on failure)

Uninstall runs `ModuleDataPurger` to remove module tables, configuration keys, tokens, certificate files, and custom order states. **Existing PrestaShop orders are not deleted.**

---

## 5. Initial configuration

Back Office → Modules → UniPayment → Configure:

| Field               | Description                                                   |
| ------------------- | ------------------------------------------------------------- |
| Enable              | Master switch                                                 |
| UNICID              | Control Panel shop identifier                                 |
| Shared secret       | Must match Control Panel; stored encrypted (`enc:v1:` prefix) |
| Advertising         | Homepage promotional block                                    |
| Debug               | Module debug flag                                             |
| Product button      | Add to cart vs buy                                            |
| Button spacing      | Pixel offset for product button                               |
| Sync bank rejection | Optional PS order-state sync on bank rejection                |

After saving credentials, the shop configuration cache is cleared and will refresh on next use.

**Do not** enter production secrets into documentation or tickets.

---

## 6. Control Panel setup

Prerequisites on the Control Panel side (operational assumption — verify in your CP deployment):

1. Shop record exists with the same **UNICID** and **shared secret** as the module.
2. Shop configuration (KOP, Process 1/2, SmartUCF URLs, consents, etc.) is complete in CP.
3. CP can reach the shop's signed module URLs:
    - `/module/unipayment/shopcache`
    - `/module/unipayment/orderbankstatus`
    - `/module/unipayment/smartucfdebuglog`
4. Module can reach CP API over HTTPS.

### SmartUCF certificate

When shop configuration requires a client certificate (`ShopConfigurationFlags::usesSmartUcfCertificate`), the module **automatically synchronizes** the certificate pair from Control Panel into `{module}/keys/` via `CertificateSynchronizer`. Manual copying is not required under normal operation.

Protection files (`.htaccess`, `index.php`) are created in `keys/` on install and sync.

---

## 7. Post-install verification

Checklist for a development or staging shop:

- [ ] Module enabled; UNICID and secret saved
- [ ] Shop configuration loads (product calculator shows financing options)
- [ ] Product page calculator works
- [ ] Cart calculator shows common schemes
- [ ] UniPayment visible at checkout
- [ ] **Process 1** test order: CP order created; SmartUCF redirect (if configured)
- [ ] **Process 2** test order: EGN required; order confirmation; admin email contains EGN; customer email does not
- [ ] Signed CP callback accepted (shop-cache push or bank status test from CP)
- [ ] Admin order view shows financing details
- [ ] Uninstall on a **test** instance only if validating purge behavior

Use test data only. Do not use real customer EGN in non-production logs.

---

## 8. Upgrade policy

`2.0.0` is the **first production release** of this development line.

There are **no** historical `upgrade/upgrade-*.php` scripts. Development used uninstall/reinstall; those iterations were never published.

After this release, schema and configuration changes must use explicit versioned upgrade procedures (see [`RELEASE.md`](RELEASE.md)). Do not rely on reinstall in production.
