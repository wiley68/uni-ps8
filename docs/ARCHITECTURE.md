# UniPayment — Architecture (current state)

This document describes the **implemented** PrestaShop 8 UniPayment module as it exists in the repository. It is not a phase plan.

For installation and operations, see also:

- [`INSTALLATION.md`](INSTALLATION.md)
- [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md)
- [`RECOVERY.md`](RECOVERY.md)

---

## 1. System context

```text
Customer browser
      ↓
PrestaShop 8 + UniPayment module
      ↔                    ↘
Control Panel              SmartUCF (Process 1, when enabled)
      ↔
Bank / operational workflows (outside this module)
```

| Component                   | Responsibility                                                                                                    |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| **Customer browser**        | Product/cart calculators, checkout, popup customer data (Process 2), payment submission                           |
| **PrestaShop + UniPayment** | Financing UI, validation, PrestaShop order creation, local persistence, emails, admin presentation                |
| **Control Panel**           | Master shop configuration, CP order records, bank status source, certificate distribution, diagnostic aggregation |
| **SmartUCF**                | Online credit application session (Process 1 only); called **directly** by the module, not via Control Panel      |

The module adapts to the existing Control Panel contract. CP internals beyond integration boundaries are out of scope here.

---

## 2. Module layers

Root namespace: `PrestaShop\Module\Unipayment\`

| Area                  | Key classes / locations                                                                                                                                           |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Module entry / hooks  | `unipayment.php`                                                                                                                                                  |
| Configuration         | `Configuration\ConfigurationRepository`, `Configuration\ShopConfigurationService`, `Configuration\ShopConfigurationCache`, `Configuration\ShopConfigurationFlags` |
| Calculators           | `Calculator\`, product/cart front controllers                                                                                                                     |
| Popup / customer data | `Product\ProductPopup*`, `Checkout\CustomerFieldValidator`                                                                                                        |
| Checkout              | `controllers/front/validatecheckout.php`, `Checkout\CheckoutSubmitLock`, `Checkout\ValidatedPaymentRequest`                                                       |
| Order orchestration   | `Order\OrderOrchestrator`, `Order\OrderAttemptRepository`, `Order\FinancingSnapshotRepository`, `Order\FinancingSnapshotFactory`                                  |
| CP outbound client    | `Api\ControlPanelClient`, `Api\TokenRepository`                                                                                                                   |
| SmartUCF              | `SmartUcf\SmartUcfSessionCoordinator`, `SmartUcf\SmartUcfSessionClient`, `SmartUcf\SmartUcfLifecycleRepository`, `SmartUcf\Certificate\*`                         |
| Inbound CP API        | `Controller\ModuleApiController`, `controllers/front/shopcache.php`, `orderbankstatus.php`, `smartucfdebuglog.php`                                                |
| Security              | `Security\ModuleRequestAuthenticator`, `Security\ModuleRequestSignatureProtocol`, `Security\ApiNonceRepository`                                                   |
| Persistence           | Repository classes per table (see §5)                                                                                                                             |
| Email / admin UI      | `Order\LeasingOrderEmailPresenter`, `Order\LeasingEmailNotifier`, `Order\OrderLeasingDetailsPresenter`                                                            |
| Advertising           | `Advertising\HomepageAdvertisingPresenter`                                                                                                                        |
| Uninstall             | `Uninstall\ModuleDataPurger`                                                                                                                                      |
| PII retention         | `Order\FinancingSnapshotRetentionService`                                                                                                                         |

Presentation uses Smarty/Twig templates and module assets; business rules live in `src/`.

---

## 3. Customer journey

### Offer calculation

1. **Product page** — calculator hook (`displayProductAdditionalInfo`) loads scheme/month options via AJAX (`productcalculator`, optional `productpopup`).
2. **Cart** — cart calculator hook (`displayShoppingCart`) resolves **common** financing schemes across cart lines (`cartcalculator`, `cartpopup`).
3. Customer selects scheme, months, and (where applicable) first installment.

### Checkout

1. UniPayment appears as a payment option (`paymentOptions` hook).
2. Customer submits checkout; server-side validation runs in `validatecheckout.php` (never trust browser financing data).
3. **Checkout submit lock** acquired (45 s TTL) before orchestration.
4. **Order orchestration** creates/resumes attempt, PrestaShop order, financing snapshot, CP order.

### Process 1 vs Process 2

Distinction comes from CP shop snapshot field **`uni_proces`**:

|                    | Process 1                               | Process 2                      |
| ------------------ | --------------------------------------- | ------------------------------ |
| `uni_proces`       | `0` (default)                           | `1`                            |
| EGN / second phone | Not required                            | Required at checkout/popup     |
| SmartUCF           | Session started after CP order          | Skipped                        |
| Post-submit UX     | SmartUCF redirect or validated template | Redirect to order confirmation |
| Bank status marker | `bank_sent_process1`                    | `bank_sent_process2`           |

Helper: `ShopConfigurationFlags::isProcess2($shop)`.

### After successful CP order

- **Process 1:** `SmartUcfSessionCoordinator` may create SmartUCF session and redirect; lifecycle tracked on financing snapshot.
- **Process 2:** Customer sees confirmation; admin receives operational email (may include full EGN — see [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md)).
- Leasing emails sent once per attempt (`leasing_email_sent` on snapshot).

---

## 4. Order orchestration

Entry: `OrderOrchestrator::orchestrate()`.

### Attempt states (`unipayment_order_attempt.state`)

| State                 | Meaning                                      |
| --------------------- | -------------------------------------------- |
| `reserved`            | Initial durable reservation                  |
| `ps_order_created`    | PrestaShop order exists                      |
| `cp_submitting`       | CP create-order in flight                    |
| `cp_created`          | Terminal success; idempotent return on retry |
| `cp_failed_retryable` | HTTP ≥500 from CP; retry allowed             |
| `cp_outcome_unknown`  | Connection failure; outcome uncertain        |
| `terminal_failed`     | Non-retryable failure                        |

Idempotency key: `(id_shop, id_cart, cart_fingerprint)`.

### Flow summary

```text
reserve attempt
  → create/load PrestaShop order
  → save financing snapshot (+ opportunistic PII cleanup)
  → build CP payload
  → CP POST /orders
  → cp_created + markAwaiting PS order state
```

Concurrent duplicate submission without a new reservation and without `id_order` → retryable “already being processed”.

### Financing snapshot

Immutable operational record of financing terms and customer submission data at purchase time. Linked 1:1 to attempt via `id_attempt` and to PrestaShop order via `id_order`.

---

## 5. Persistence

Module-owned tables (created in `unipayment.php::install()`):

| Table                           | Purpose                                                                   |
| ------------------------------- | ------------------------------------------------------------------------- |
| `unipayment_shop_cache`         | Cached CP shop configuration snapshot (JSON + expiry)                     |
| `unipayment_smartucf_log`       | Local SmartUCF request/response diagnostic journal (CP can fetch via API) |
| `unipayment_order_bank_status`  | Latest bank status per PrestaShop order                                   |
| `unipayment_api_nonce`          | Replay protection for signed CP → module requests                         |
| `unipayment_checkout_lock`      | Short-lived checkout submit lock per shop+cart                            |
| `unipayment_order_attempt`      | Durable financing submission attempt / idempotency                        |
| `unipayment_financing_snapshot` | Financing terms + customer/address/consent snapshot                       |
| `unipayment_popup_submission`   | Product popup submission deduplication / tracking                         |

Additional PrestaShop data (not module tables):

- `ps_configuration` keys via `ConfigurationRepository` and related services
- Custom **order states** installed by `OrderStateInstaller` (awaiting / failed / rejected)

Relationships:

```text
order_attempt (id_attempt)
      ↓ 1:1
financing_snapshot (id_attempt, id_order)
      ↓
PrestaShop orders (native ps_orders)
      ↓ optional
order_bank_status (id_order)
```

---

## 6. Configuration cache

Service: `ShopConfigurationService` + `ShopConfigurationCache`.

| Behavior                    | Detail                                                                         |
| --------------------------- | ------------------------------------------------------------------------------ |
| TTL                         | 24 hours (`TTL_SECONDS = 86400`)                                               |
| Pull                        | On cache miss or forced refresh → CP `GET /shop`                               |
| Push                        | CP signed POST to `shopcache` → full snapshot replace (immediate, no TTL wait) |
| Validation                  | Snapshot validated on pull; invalid pull keeps stale cache                     |
| Permanent auth/shop failure | Cache purged + CP token invalidated                                            |
| Credential change           | Cache cleared on module config save                                            |
| Manual refresh              | Admin “Refresh bank data” action                                               |

Partial merge of push payload with old snapshot is **not** performed; push replaces the stored snapshot.

---

## 7. Inbound Control Panel API

All endpoints: **POST only**, **HTTPS**, JSON body, **signed headers** (see §8).

PrestaShop front controller URLs (pattern):

```text
/module/unipayment/shopcache
/module/unipayment/orderbankstatus
/module/unipayment/smartucfdebuglog
```

| Controller         | Purpose                                                            |
| ------------------ | ------------------------------------------------------------------ |
| `shopcache`        | Replace local shop configuration snapshot (`payload.data`)         |
| `orderbankstatus`  | Push bank status for a financing order in current shop context     |
| `smartucfdebuglog` | Return latest local SmartUCF diagnostic log for an order reference |

Common security boundary: `ModuleRequestAuthenticator` (enabled module, UNICID match, HMAC signature, nonce claim).

---

## 8. Signed request protocol (summary)

Full operational detail: [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md).

Headers:

```text
X-UniPayment-Timestamp
X-UniPayment-Nonce
X-UniPayment-Signature
```

Canonical string:

```text
{timestamp}\n{nonce}\n{raw_request_body}
```

HMAC-SHA256 with shared secret; signature as lowercase hex. Timestamp window ±300 s. Nonce: 64 hex chars. Replay store retention 900 s.

Unsigned requests are rejected.

---

## 9. Multishop boundary

Bank status callback (`orderbankstatus`) resolves the PrestaShop order in **`$this->context->shop->id`** only.

Incoming payload field **`order_id`** is the **PrestaShop order reference** (e.g. `XKBNTABCD`), **not** native `id_order`.

`OrderBankStatusRepository::findAuthorizedFinancingOrder()` requires a matching financing snapshot for that shop + reference.

Broader multishop support beyond this scoping is not claimed.

---

## 10. Checkout concurrency

Two independent layers:

| Layer         | Mechanism                         | TTL / durability | Problem solved                           |
| ------------- | --------------------------------- | ---------------- | ---------------------------------------- |
| Submit lock   | `unipayment_checkout_lock`        | 45 seconds       | Double-click / parallel browser submits  |
| Order attempt | `unipayment_order_attempt` UNIQUE | Durable          | Idempotent order creation across retries |

Lock is released on validation/retryable errors; **not** released on success (expires naturally). Attempt reservation is the authoritative guard for duplicate orders.

---

## 11. PII model

| Data               | Storage                                                                                                                                              |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Customer / address | Plain JSON columns on financing snapshot                                                                                                             |
| EGN, second phone  | Encrypted in `sensitive_payload` (`SensitiveDataCipher`)                                                                                             |
| Email audience     | `EmailAudience::CUSTOMER` vs `ADMIN` — separate rendering; customer never receives EGN                                                               |
| Retention          | After 180 days (`created_at < cutoff`): redact `customer_json`, `address_json`, `sensitive_payload`; preserve `consents_json` and financing metadata |

Opportunistic cleanup: `FinancingSnapshotRetentionService::maybeRun()` after snapshot save; max once per 24 h; batch 200 rows.

Admin order display degrades safely when PII columns are empty after redaction.
