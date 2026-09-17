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
- JSON payload must include canonical `operation` matching the endpoint (covered by HMAC because it is inside the raw body).

### Operations and endpoint binding

| Operation            | PrestaShop front controller |
| -------------------- | --------------------------- |
| `shop-cache`         | `shopcache`                 |
| `order-bank-status`  | `orderbankstatus`           |
| `smartucf-debug-log` | `smartucfdebuglog`          |

A valid signed body for one operation must not execute on another endpoint (`unsupported_operation`).

### Signature

- Algorithm: **HMAC-SHA256**
- Key: decrypted shared secret
- Output: **lowercase hex** (`hash_hmac('sha256', ...)`)

### Timestamp

- Header value must be numeric (`ctype_digit`)
- Accepted window: **±300 seconds** (`TIMESTAMP_TOLERANCE_SECONDS`)

### Nonce

- Format: **64 lowercase hexadecimal characters** (`^[0-9a-f]{64}$`)
- Uppercase hex is rejected
- Retention: **900 seconds**

### Body size

- Maximum inbound JSON body: **1 MiB** (`1048576` bytes)
- Read via `BoundedRawBodyReader` with hard cap `MAX+1` bytes (no unbounded `file_get_contents('php://input')`)
- Declared `Content-Length` > 1 MiB may fail early, but the stream bound remains authoritative
- Oversized requests fail before JSON parsing / HMAC / nonce claim (`payload_too_large`, HTTP 413)

### Local bank status vs CP status sync

**Standard bank status** (customer/business-facing labels) is defined in [`ARCHITECTURE.md` §3.1](ARCHITECTURE.md). Summary:

- Exactly four initial public labels: `Неуспешно изпратен Банка - КП`, `Неуспешно изпратен Банка - SmartUCF`, `Изпратен Банка - Процес 1`, `Изпратен Банка - Процес 2`.
- Definitive CP create failure (Process 1 **and** Process 2) → `Неуспешно изпратен Банка - КП` only. Generic `Неуспешно изпратен Банка` is **not** a public bank status.
- Later SmartUCF-returned statuses are stored/displayed **raw** (no invented mapping).
- Attempt / SmartUCF / CP-sync machine states are **internal** and must not appear as bank status on normal UI.

Local `bank_sent_process1` / `bank_sent_process2` mean **business handoff proven** for the corresponding standard success label (admin/email/thank-you).

Outbound CP `PATCH /orders/status` confirmation is tracked separately on the financing snapshot (`cp_status_sync_*`) as an **internal sync state**:

| State             | Meaning                                                                                                  |
| ----------------- | -------------------------------------------------------------------------------------------------------- |
| `pending`         | Desired status persisted; PATCH not yet canonically confirmed                                            |
| `confirmed`       | Canonical CP success + identity/status echo validated                                                    |
| `terminal_failed` | Positive allowlist only: `invalid_payload`, `semantic_conflict`, `unsupported_status`, `order_not_found` |

Pending sync is retried idempotently from existing lifecycle/resume paths without repeating SmartUCF create or P2 business handoff. Transitions use status-aware compare-and-set so a stale retry cannot overwrite a newer pending/confirmed target. Unknown/transient 4xx/5xx (`rate_limited`, auth/token errors, `internal_error`, etc.) remain `pending`.

`bank_sent_process1` and `bank_sent_process2` are mutually incompatible terminal statuses for CP status-sync admission. They represent alternative process outcomes, not sequential stages — neither may replace the other in pending/confirmed/`terminal_failed` sync state.

### Canonical response envelope

Every inbound module API response uses:

```json
{
    "success": true,
    "error": null,
    "message": "...",
    "data": {}
}
```

Failures set `success` to `false` and `error` to a stable snake_case machine code. `data` is always a JSON object.

### Authentication failure

HTTP **401** with machine code `invalid_signature` and message: `Invalid or expired module request.`

### Unsigned protocol

Legacy unsigned CP → module requests are **not** accepted.

### Contract test vector (synthetic)

For cross-project compatibility testing only — **not production credentials**:

| Field              | Value                                                                                                               |
| ------------------ | ------------------------------------------------------------------------------------------------------------------- |
| Secret             | `test_shared_secret_123`                                                                                            |
| Timestamp          | `1787380000`                                                                                                        |
| Nonce              | `0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`                                                  |
| Raw body           | `{"operation":"order-bank-status","unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"10"}` |
| Expected signature | `012e8545e84e43b45ae05a828bb487932454313ace1729d846cc3bd05a41c6a0`                                                  |

Authority: Control Panel frozen baseline `0facb6721c0b9199078ce4b6404ed89c00de680c` (`docs/MODULE_PROTOCOL.md`).

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

Bank status endpoint (`orderbankstatus`) and SmartUCF debug endpoint (`smartucfdebuglog`):

- Use **current PrestaShop shop context** (`$this->context->shop->id`).
- Payload `order_id` = immutable **shop-side financing order reference** (`ps_orders.reference`, max **13** on the wire), **not** `id_order` and not CP `orders.id`.
- Order must exist with a UniCredit financing snapshot authorized for that shop.
- Debug diagnostics are further scoped by matching `ps_order_id` / `id_order`.
- Foreign-shop same-reference and missing diagnostics both return opaque **404** `order_not_found`.

Wrong shop URL or reference → 404 `order_not_found`.

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

Emails that include UniCredit leasing information must follow the **standard leasing field set** and **standard bank status** rules in [`ARCHITECTURE.md` §3.1](ARCHITECTURE.md): no internal lifecycle/sync/diagnostic rows in the normal leasing block.

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
