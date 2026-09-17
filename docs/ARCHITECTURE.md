# UniPayment — Architecture (current state)

This document describes the **implemented** PrestaShop 8 UniPayment module as it exists in the repository. It is not a phase plan.

For installation and operations, see also:

- [`INSTALLATION.md`](INSTALLATION.md)
- [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md)
- [`RECOVERY.md`](RECOVERY.md)

**Authoritative bank-status and leasing presentation rules:** §3.1 in this document.

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

|                    | Process 1                                | Process 2                  |
| ------------------ | ---------------------------------------- | -------------------------- |
| `uni_proces`       | `0` (default)                            | `1`                        |
| EGN / second phone | Not required                             | Required at checkout/popup |
| SmartUCF           | Session started after successful CP order | Skipped                    |
| Post-submit UX     | SmartUCF redirect or native confirmation | Native order confirmation  |
| CP create          | Same canonical create schema             | Same canonical create schema |

**Authoritative public bank-status rules** (labels and when each applies): see **§3.1** below. Do not treat internal lifecycle / sync / retry states as public bank statuses.

Helper: `ShopConfigurationFlags::isProcess2($shop)`.

### After successful CP order

- **Process 1:** `SmartUcfSessionCoordinator` may create SmartUCF session and redirect. Proven SmartUCF success sets local standard bank status **Изпратен Банка - Процес 1** (`bank_sent_process1`) and persists a **pending** CP status sync; PATCH confirmation marks sync **confirmed**. PATCH transport/echo ambiguity leaves sync **pending** and must not start another SmartUCF session.
- **Process 2:** After successful CP create handoff, local standard bank status **Изпратен Банка - Процес 2** (`bank_sent_process2`) is business handoff proven; CP PATCH confirmation is tracked separately as pending → confirmed. Pending sync is retried on subsequent lifecycle invocation without repeating the P2 handoff. Process 2 success does **not** require proof that the order was created/sent in SmartUCF.
- Create-time payloads never send `status` / `status_id` / `egn` / `phone2` to CP.
- Leasing emails sent once per attempt (`leasing_email_sent` on snapshot).
- Durable sync fields on `unipayment_financing_snapshot`: `cp_status_sync_state`, `cp_status_sync_status_id`, `cp_status_sync_status`, `cp_status_sync_error_class`, `cp_status_sync_updated_at`.
- CP status-sync transitions are compare-and-set on `(state, status_id, status)`; terminal failure requires an explicit machine-code allowlist (not generic HTTP 4xx).
- `bank_sent_process1` and `bank_sent_process2` are mutually incompatible terminal status **ids** for CP status-sync admission (alternative process outcomes, not sequential stages). Local CP-sync admission rejects either direction without PATCH.

---

## 3.1. Bank status and leasing presentation (authoritative business rules)

These rules are the **business-owner contract** for manual verification and subsequent runtime remediation. They apply equally to PS8 and Control Panel presentation of **standard bank status**. Wire `status_id` values remain Woo/CP-compatible machine identifiers; the **Bulgarian labels below are authoritative display strings** and must not be renamed.

### Two classes of status

| Class | Purpose | Shown as “банков статус”? |
| ----- | ------- | ------------------------- |
| **Standard bank status** | Customer/business-facing bank outcome | **Yes** — only on officially allowed surfaces |
| **Internal / service lifecycle state** | Retry, transport, sync, diagnostic progression | **No** — never as bank status on normal UI |

Do not call an internal lifecycle state a bank status unless explicitly contrasting the two classes.

### Initial standard bank statuses (exactly four)

Until a later status is returned from SmartUCF (via CP), only these four standard bank statuses are allowed. Strings are identical across PS8, Woo, CP, and other shop modules.

| # | Display label (AUTHORITATIVE) | Machine `status_id` (compat) | When it applies |
| - | ----------------------------- | ---------------------------- | --------------- |
| A | **Неуспешно изпратен Банка - КП** | `bank_send_failed_cp` | Shop order exists; order was **not** successfully created/visible in CP; SmartUCF was **not** successfully created/sent. Definitive failure **before** successful CP create. |
| B | **Неуспешно изпратен Банка - SmartUCF** | `bank_send_failed_smartucf` | Shop order exists; CP order exists and is visible; SmartUCF create/send **definitively** failed/rejected. |
| C | **Изпратен Банка - Процес 1** | `bank_sent_process1` | Shop + CP success; SmartUCF successfully created/sent; Process 1. |
| D | **Изпратен Банка - Процес 2** | `bank_sent_process2` | Shop + CP success; Process 2. SmartUCF create/send proof is **not** required for this status. |

**Forbidden as a public bank status:** generic `Неуспешно изпратен Банка` / `bank_send_failed`. There is no fifth initial public status.

### Failure semantics

```text
Definitive CP failure (Process 1 OR Process 2):
  Shop order exists
  CP order definitively does NOT exist
  SmartUCF not successfully created/sent
  → public status: Неуспешно изпратен Банка - КП
```

```text
Definitive SmartUCF rejection/failure:
  Shop order exists
  CP order exists
  SmartUCF definitively rejects/fails creation/submission
  → public status: Неуспешно изпратен Банка - SmartUCF
```

```text
Ambiguous technical outcome (timeout / interrupted transport / outcome unknown):
  May have an INTERNAL lifecycle state only
  Does NOT invent a new public bank status
  Does NOT auto-promote ambiguity to Неуспешно изпратен Банка - КП / SmartUCF
```

CP create failure semantics **do not** depend on Process 1 vs Process 2: both use **Неуспешно изпратен Банка - КП**.

### Later statuses from SmartUCF

After the initial status, Control Panel may refresh the bank status from SmartUCF (manual request or scheduled CP check). SmartUCF may return a new status.

**Authoritative rule:** store and display the status **exactly as returned by SmartUCF**.

- Do **not** rename it.
- Do **not** normalize it to a fixed enum.
- Do **not** invent a mapping table for all possible values.

This applies equally to PS8 and CP wherever a bank-status field is shown.

### Internal / service lifecycle states

Examples of **non-public** states (illustrative; PS8 may use equivalent machine names):

```text
pending, created, submitting, retryable, timeout, outcome unknown,
definitive_failed, sent_unknown, sync pending, sync failed,
transport failure, internal progression / recovery states
```

Also includes: attempt states (`reserved`, `cp_submitting`, `cp_outcome_unknown`, …), SmartUCF lifecycle (`not_started`, `submitting`, `created`, `failed`, …), and CP status-sync states (`pending` / `confirmed` / `terminal_failed` on the snapshot).

These must **not** appear as bank status to:

- the customer;
- standard PrestaShop admin order UI bank-status fields;
- standard emails’ bank-status row;
- CP order list bank-status column;
- other normal customer/business-facing screens.

They may appear **only** on explicitly diagnostic surfaces (SmartUCF debug in CP, debug pulled from the shop, developer/support panels, application logs, other pre-approved diagnostic places).

### Where standard bank status may be shown (PS8)

1. PrestaShop admin **orders list** UniCredit bank-status column (if present).
2. PrestaShop admin **order view** UniCredit / leasing panel.
3. Customer order / confirmation UI **only** when that display is explicitly specified.
4. Thank You / order confirmation when bank status is part of the agreed content.
5. Standard emails when they are designed to include bank status.
6. Control Panel order list / order table.
7. Other **pre-agreed** bank-status surfaces only.

If a UI field is labelled as bank status, it may show only:

- one of the four initial standard labels above; or
- a later **raw** SmartUCF-returned status string.

Never internal lifecycle / sync / debug values.

### Standard customer/business-facing leasing information

Used on pre-defined surfaces (admin UniCredit panel, Thank You when applicable, standard emails, agreed reports). Structure (example values illustrative only):

```text
Статус към банката    <standard bank status or later SmartUCF raw status>
КП поръчка (ID)       <Control Panel order id when available>
КП shop order_id      <shop order reference sent to CP>
Срок (месеци)         <months>
КОП                    <KOP code>
Първоначална вноска   <amount>
Сума на заема         <amount>
Месечна вноска        <amount>
Обща дължима сума     <amount>
ГЛП / ГПР             <glp>% / <gpr>%
```

**Authoritative** is the field set / structure, not the sample numbers. If a field has no value at a given lifecycle moment, follow the **existing** presentation convention (omit empty row or show empty) — do not invent a new rule in implementation docs without business approval.

**Allowed Process 2 adaptations** (only where privacy/business rules already permit):

- admin surfaces may add **ЕГН** and/or **Втори телефон**;
- customer Process 2 confirmation may add the agreed confirmation **Съобщение**;
- never add diagnostic/lifecycle fields to this block.

### Forbidden in the standard leasing / bank-status block

Not allowed on normal customer/business UI (admin leasing panel, emails, Thank You, etc.):

```text
КП създаване, SmartUCF резултат, SmartUCF lifecycle, SmartUCF сесия,
Автоматично повторно изпращане, Препоръчано действие,
Последна грешка (категория), Подсистема, Час на грешката, Корелация,
CP synchronization state, lifecycle state, retry state,
HTTP/transport classification, timeout/network details,
internal error class, internal CP/SmartUCF stage
```

or any other content that exposes Shop → CP → SmartUCF internals, retry/recovery, timeout/outcome-unknown machinery, state machines, correlation/error classification, or transport architecture.

### Privacy / business-model principle

```text
Customer-facing and normal business-facing UI must contain only information
needed for the order, financing terms, and the agreed bank status.
```

Diagnostic detail belongs only on pre-approved diagnostic surfaces (§3.1 Internal / service lifecycle states).

---

## 4. Order orchestration

Entry: `OrderOrchestrator::orchestrate()`.

### After successful CP order

`PostControlPanelLifecycleService` centralizes post-CP behavior for product popup, cart popup, and checkout:

- financing snapshot load;
- Process 2 bank status + leasing email (no SmartUCF);
- Process 1 SmartUCF run/resume via `SmartUcfSessionCoordinator`;
- trusted redirect validation;
- leasing email timing/status;
- normalized `PostControlPanelLifecycleResult` for transport adapters.

Controllers map the result to JSON, Smarty, or redirects. SmartUCF session creation remains inside `SmartUcfSessionCoordinator`.

### Attempt states (`unipayment_order_attempt.state`) — internal only

These are **internal / service lifecycle states**, not standard bank statuses. They must not be shown in customer/business bank-status UI (see §3.1).

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
  → cp_created (native PS order state remains merchant-controlled)
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

| Controller         | Expected `operation` | Purpose                                                            |
| ------------------ | -------------------- | ------------------------------------------------------------------ |
| `shopcache`        | `shop-cache`         | Replace local shop configuration snapshot (`payload.data`)         |
| `orderbankstatus`  | `order-bank-status`  | Push bank status for a financing order in current shop context     |
| `smartucfdebuglog` | `smartucf-debug-log` | Return latest local SmartUCF diagnostic log for an order reference |

Common security boundary: `ModuleRequestAuthenticator` (enabled module, HMAC over exact raw body, UNICID match, nonce claim) then endpoint operation binding.

Canonical inbound responses always use `{success, error, message, data}` with `data` as a JSON object.

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

HMAC-SHA256 with shared secret; signature as lowercase hex. Timestamp window ±300 s. Nonce: **64 lowercase hex** chars. Replay store retention 900 s. Max body **1 MiB**, enforced by a bounded stream read of at most `MAX+1` bytes (`BoundedRawBodyReader`) before HMAC/JSON/nonce processing.

Canonical operations inside the signed JSON body: `shop-cache`, `order-bank-status`, `smartucf-debug-log`.

Unsigned requests are rejected.

Module → CP responses are also parsed as the canonical envelope (`success === true`, `error === null`, `data` object). Login/refresh tokens live under `data`. HTTP 2xx alone is not treated as success.

---

## 9. Multishop boundary

Bank status callback (`orderbankstatus`) resolves the PrestaShop order in **`$this->context->shop->id`** only.

Incoming payload field **`order_id`** is the **PrestaShop order reference** (e.g. `XKBNTABCD`, wire max **13**), **not** native `id_order`.

`OrderBankStatusRepository::resolveAuthorizedFinancingOrder()` requires a matching financing snapshot for that shop + reference (fail-closed if ambiguous).

SmartUCF debug uses the same shop + reference + financing ownership gate, plus diagnostic `id_order` ownership.

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
