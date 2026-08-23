# Security operations

Operational security reference for the UniPayment PrestaShop module. Values below are taken from the current codebase.

Related: [`ARCHITECTURE.md`](ARCHITECTURE.md), [`RECOVERY.md`](RECOVERY.md)

---

## 1. Secrets

| Secret              | Role                                                                                        |
| ------------------- | ------------------------------------------------------------------------------------------- |
| **UNICID**          | Identifies the shop to Control Panel (`ConfigurationRepository::UNICID`)                    |
| **Shared secret**   | HMAC signing (CP → module) and CP API authentication context; stored as `UNIPAYMENT_SECRET` |
| **CP access token** | Bearer token for outbound CP calls; stored encrypted (`TokenRepository`)                    |

### Encryption at rest

- Shared secret and CP tokens use PrestaShop shop encryption with prefix `enc:v1:` (`ConfigurationRepository`).
- EGN and second phone in financing snapshots use `SensitiveDataCipher` (shop key–based).

### Rules

- Never log UNICID+secret together in diagnostic output.
- Never commit secrets, tokens, private keys, or certificate passwords.
- Rotating the shared secret requires updating **both** Control Panel and module configuration; in-flight signed requests with the old secret will fail until aligned.

---

## 2. CP → module signed request protocol

Implementation: `ModuleRequestSignatureProtocol`, `ModuleRequestAuthenticator`.

### Headers (required)

```text
X-UniPayment-Timestamp
X-UniPayment-Nonce
X-UniPayment-Signature
```

### Canonical string

```text
{timestamp}\n{nonce}\n{raw_request_body}
```

- `{raw_request_body}` must be the **exact** HTTP body bytes received (before JSON re-encoding).
- JSON payload must include `unicid` matching the configured shop UNICID.

### Signature

- Algorithm: **HMAC-SHA256**
- Key: decrypted shared secret
- Output: **lowercase hex** (`hash_hmac('sha256', ...)`)

### Timestamp

- Header value must be numeric (`ctype_digit`)
- Accepted window: **±300 seconds** (`TIMESTAMP_TOLERANCE_SECONDS`)

### Nonce

- Format: **64 hexadecimal characters** (`NONCE_HEX_LENGTH`)
- Pattern: `[0-9a-fA-F]{64}`

### Authentication failure

HTTP **401** with message: `Invalid or expired module request.`

### Unsigned protocol

Legacy unsigned CP → module requests are **not** accepted.

### Contract test vector (synthetic)

For cross-project compatibility testing only — **not production credentials**:

| Field              | Value                                                                               |
| ------------------ | ----------------------------------------------------------------------------------- |
| Secret             | `test_shared_secret_123`                                                            |
| Timestamp          | `1787380000`                                                                        |
| Nonce              | `0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`                  |
| Raw body           | `{"unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"10"}` |
| Expected signature | `2f4a55c19a2dd0f2f7f2390a6d720e95dbdff577c096d7ff291ef8f84a53e94f`                  |

---

## 3. Replay protection

Table: `unipayment_api_nonce`

| Behavior    | Detail                                                    |
| ----------- | --------------------------------------------------------- |
| Storage key | `(unicid, nonce_hash)` where `nonce_hash = sha256(nonce)` |
| Claim       | Atomic insert; duplicate → authentication failure         |
| Retention   | **900 seconds** (`NONCE_RETENTION_SECONDS`)               |
| Cleanup     | Probabilistic purge (~1/20) on each successful claim      |

Do not treat nonce table truncation as routine maintenance.

---

## 4. Multishop authorization

Bank status endpoint (`orderbankstatus`):

- Uses **current PrestaShop shop context** (`$this->context->shop->id`).
- Payload `order_id` = **PrestaShop order reference** (string), **not** `id_order`.
- Order must exist with a financing snapshot authorized for that shop.

Wrong shop URL or reference → 404 `The order was not found in the shop.`

---

## 5. Checkout concurrency / idempotency

### Checkout submit lock

| Parameter      | Value                                     |
| -------------- | ----------------------------------------- |
| Table          | `unipayment_checkout_lock`                |
| TTL            | **45 seconds**                            |
| Owner token    | 32-char hex (`bin2hex(random_bytes(16))`) |
| Stale recovery | UPDATE allowed when `expires_at <= now`   |

Released on validation errors and retryable orchestration failures; **not** released on success.

### Order attempt (durable)

Table: `unipayment_order_attempt` — unique `(id_shop, id_cart, cart_fingerprint)`.

Terminal state `cp_created` is idempotent. State `terminal_failed` blocks retry.

---

## 6. Certificate handling

Classes: `SmartUcf\Certificate\CertificateSynchronizer`, `CertificateLocalStore`, `CertificateConsumerLease`.

| Topic        | Behavior                                                             |
| ------------ | -------------------------------------------------------------------- |
| Location     | `{module}/keys/` — `avalon_cert.pem`, `avalon_private_key.pem`       |
| Source       | Control Panel `GET /ssl/certificate` + `GET /ssl/certificate/bundle` |
| Sync trigger | Before SmartUCF session when shop config requires certificate        |
| Locking      | File lock `.sync.lock` (15 s timeout)                                |
| Consumer use | Temporary lease pair per HTTP call; deleted on release               |
| Protection   | `.htaccess`, `index.php` in `keys/`                                  |

**Do not commit** private keys or bundle passwords. **Do not log** certificate material.

Recovery: see [`RECOVERY.md`](RECOVERY.md) §9.

---

## 7. PII handling

### Email policy

| Flow      | Customer email                        | Admin email (`PS_SHOP_EMAIL`)             |
| --------- | ------------------------------------- | ----------------------------------------- |
| Process 1 | No EGN                                | No EGN                                    |
| Process 2 | No EGN (confirmation message allowed) | **Full EGN** + second phone (operational) |

Implementation: `LeasingOrderEmailPresenter::customerRowsFromSnapshot()` / `adminRowsFromSnapshot()`, `LeasingEmailNotifier`.

If customer email equals shop email → **admin variant only**, sent once.

EGN decryption occurs only for Process 2 admin rendering paths.

Process 1 native `order_conf` is deferred until SmartUCF completes and is **discarded** if Control Panel create-order fails after the PrestaShop order exists, so success-bank wording is not sent for that failure.

Process 2 native `order_conf` is sent at PrestaShop order creation and must **not** include leasing extra vars with `Изпратен Банка - Процес 2`. Dedicated customer/admin leasing mail is sent only after a successful Control Panel create.

### Post-order customer boundary

Once a PrestaShop financing order exists, the customer must not submit a fresh financing attempt. Checkout uses native Thank You; Product/Cart popup uses a final informational Step 3. Correction/retry is allowed only for **pre-order** validation errors.

### Snapshot retention (local)

| Parameter       | Value                                                                         |
| --------------- | ----------------------------------------------------------------------------- |
| Retention       | **180 days** from `created_at`                                                |
| Boundary        | `created_at < cutoff` → redact; exactly 180 days **retained**                 |
| Redacted fields | `customer_json` → `{}`, `address_json` → `{}`, `sensitive_payload` → `NULL`   |
| Preserved       | `consents_json`, financing/lifecycle metadata, `lines_json` (order line data) |
| Throttle        | Once per **24 hours** (`UNIPAYMENT_LAST_PRIVACY_CLEANUP`)                     |
| Batch           | **200 rows** per run                                                          |

`consents_json` is **deliberately not** auto-redacted in current scope (separate legal retention decision pending).

External mailbox retention, backups, and CP-held data are **outside** this module purge.

---

## 8. Logging

Must **never** appear in logs or exception messages exposed to operators:

- Shared secret or decrypted CP token
- EGN or decrypted `sensitive_payload`
- Full `customer_json` / `address_json` dumps

Current module API and email paths log **generic** class/messages without sensitive payload values.

Generic exception class names in logs are acceptable.

---

## 9. Access control / infrastructure

In scope for this module:

- HTTPS required for inbound module API (`ModuleApiController::$ssl = true`)
- Signed authentication required for CP callbacks

Organizational controls (outside module code):

- Secure mailbox access for Process 2 admin emails containing EGN
- Backup retention policies
- WAF / network restrictions between CP and shop

These are operational assumptions, not enforced by the module.
