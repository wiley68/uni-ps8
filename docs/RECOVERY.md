# Recovery runbook

Concise operational guide for common UniPayment failure modes. Each scenario follows:

```text
Symptoms → Checks → Safe actions → Do not do → Escalation data
```

Related: [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md), [`ARCHITECTURE.md`](ARCHITECTURE.md)

---

## 1. Control Panel unavailable

**Symptoms**

- Checkout or popup fails after PrestaShop order creation
- Attempt state `cp_outcome_unknown`, `cp_failed_retryable`, or `terminal_failed`
- Customer sees native Thank You (checkout) or final popup Step 3 — not a resubmit form

**Checks**

- CP API reachability from shop server (HTTPS to configured base URL)
- Module enabled; UNICID/secret configured
- `unipayment_order_attempt` row: `state`, `last_error_class`, `id_order`, `order_reference`
- PrestaShop order exists and state (awaiting/failed)

**Safe actions**

- Do **not** ask the customer to submit a new financing attempt once a PrestaShop order exists
- Same-attempt server retry may be used by operators/developers only when the attempt is `cp_failed_retryable` or `cp_outcome_unknown` and CP confirms it is safe
- Verify CP status; use admin order view for attempt/snapshot linkage and UniCredit status

**Do not do**

- Manually create a second PrestaShop order without inspecting attempt state
- Disable module signing or bypass validation

**Escalation data**

- `id_attempt`, PrestaShop `id_order`, `order_reference`, attempt `state`, timestamp, `last_error_class`

---

## 2. Shop configuration unavailable or stale

**Symptoms**

- Calculators empty or financing unavailable
- Checkout validation errors for scheme/months

**Checks**

- `unipayment_shop_cache` row for UNICID; `expires_at`
- Module config: enabled, UNICID, secret
- CP shop snapshot valid in Control Panel
- Recent CP shop-cache push or manual “Refresh bank data”

**Safe actions**

- Trigger manual refresh from module configuration (if available)
- Request CP shop-cache push after CP-side config change
- Verify CP authentication succeeds (token refresh)

**Do not do**

- Edit KOP/coefficients locally in module (CP is source of truth)

**Escalation data**

- UNICID (not secret), cache `fetched_at`/`expires_at`, validation error if logged generically

---

## 3. Module authentication / signature 401

**Symptoms**

- CP callbacks return 401 `Invalid or expired module request.`

**Checks**

- Clock skew between CP and shop (±300 s window)
- Shared secret matches on both sides
- All three headers present and correctly cased
- CP signs **raw body** exactly as sent
- `payload.unicid` matches module UNICID
- Module enabled

**Safe actions**

- Align secret in CP and module configuration
- Synchronize server time (NTP)
- Re-send request with fresh timestamp and nonce

**Do not do**

- Disable signed authentication
- Revert to unsigned callback protocol

**Escalation data**

- Timestamp header value, shop server time (UTC), UNICID, endpoint URL (no secret, no signature)

---

## 4. Replay rejection

**Symptoms**

- Valid-looking signed request rejected as invalid/expired (duplicate nonce)

**Checks**

- CP retry logic reusing same nonce
- Request duplicated by proxy/load balancer
- `unipayment_api_nonce` normal operation (900 s retention)

**Safe actions**

- Ensure CP generates a **new nonce** per delivery attempt
- Retry with new timestamp + nonce + signature

**Do not do**

- Truncate `unipayment_api_nonce` as routine troubleshooting

**Escalation data**

- Approximate request time, endpoint, whether retry reused nonce

---

## 5. Bank status callback not applied

**Symptoms**

- CP shows bank status; PrestaShop admin order does not

**Checks**

- Callback hit correct **shop URL** (multishop context)
- Payload `order_id` is **PrestaShop order reference**, not `id_order`
- Financing snapshot exists for that order in the same shop
- `unipayment_order_bank_status` row
- Optional: `UNIPAYMENT_SYNC_BANK_REJECTION_STATE` for PS order-state change

**Safe actions**

- Re-push status from CP with correct reference and shop base URL
- Verify signed request succeeds (200)

**Do not do**

- Manually edit bank status in DB without understanding CP as source of truth

**Escalation data**

- Order reference, `id_shop`, `id_order`, CP status_id, callback HTTP status

---

## 6. SmartUCF failure / processing / outcome unknown

**Symptoms**

- Process 1 customer not redirected to SmartUCF
- Snapshot `smartucf_state`: `failed`, `outcome_unknown`, or stuck `submitting`

**Checks**

- Shop config: Process 1 (`uni_proces = 0`), SmartUCF URLs/endpoints
- Certificate sync if required (`usesSmartUcfCertificate`)
- Snapshot SmartUCF fields: `smartucf_error_class`, `smartucf_retryable`, `smartucf_http_code`
- Local debug log (`unipayment_smartucf_log`); CP can fetch via `smartucfdebuglog` API
- Stale `submitting` > 45 s escalates to `outcome_unknown` automatically

**Safe actions**

- Retry SmartUCF coordination if state is `failed` and `smartucf_retryable = 1`
- Fix certificate/sync or SmartUCF connectivity; then retry from supported entry point
- Use CP diagnostic view for SmartUCF log (not merchant-facing debug UI)

**Do not do**

- Bypass SmartUCF redirect validation for external URLs

**Escalation data**

- `id_order`, order reference, `smartucf_state`, `smartucf_session_id`, error class, HTTP code

---

## 7. CP order outcome unknown

**Symptoms**

- PrestaShop order exists; attempt `cp_outcome_unknown`
- Customer sees outcome-unknown wording and is told not to resubmit

**Checks**

- `unipayment_order_attempt`: `state`, `cp_payload`, `control_panel_order_id`
- Whether CP actually created order (check CP admin by reference/UNICID)

**Safe actions**

- If CP confirms order exists: update operational records via supported paths (may require dev/CP reconciliation — not automated in module)
- If CP confirms no order: reconcile from admin/CP; do not instruct the customer to place a second financing order

**Do not do**

- Submit a fresh unrelated order before inspecting attempt + CP

**Escalation data**

- `id_attempt`, `order_reference`, `cp_payload` hash presence (not full PII payload), CP order ID if known

---

## 8. Checkout “already being processed”

**Symptoms**

- Retryable message: financing attempt already in progress
- Recent double submit

**Checks**

- `unipayment_checkout_lock` for `(id_shop, id_cart)` — expires in **45 s**
- `unipayment_order_attempt` state and `id_order`
- Whether prior submit succeeded (`cp_created`)

**Safe actions**

- Wait for lock TTL expiry and retry if attempt is retryable
- If attempt already `cp_created`, use existing order confirmation path

**Do not do**

- Delete lock row as first response
- Force new cart fingerprint to bypass attempt guard

**Escalation data**

- `id_cart`, `id_shop`, attempt `state`, lock `expires_at` if visible

---

## 9. Certificate synchronization failure

**Symptoms**

- SmartUCF fails before session start
- `CertificateSyncException` in logs (generic)

**Checks**

- CP SSL certificate endpoints reachable
- Module `keys/` writable; protection files present
- Hash mismatch triggers bundle download
- File lock not stuck beyond timeout (15 s)

**Safe actions**

- Verify CP authentication and `/ssl/certificate` responses
- Ensure `keys/` permissions allow module write
- Retry SmartUCF after CP cert update propagates

**Do not do**

- Commit or email private key material
- Manually paste production keys into repo

**Escalation data**

- Sync failure reason class (if generic), CP cert metadata availability, module version

---

## 10. Financing email problems

**Symptoms**

- Customer or admin did not receive leasing email
- Wrong content variant

**Checks**

- Snapshot `leasing_email_sent` flag
- Recipient: customer email from snapshot; admin from `PS_SHOP_EMAIL`
- Process 2: admin should include EGN; customer must not
- Duplicate recipient: only one admin-variant send
- PrestaShop mail configuration / SMTP logs (generic)

**Safe actions**

- Verify mail transport at PrestaShop level
- Re-send only through supported module paths (once-per-attempt guard prevents duplicates)

**Do not do**

- Copy EGN into support tickets or logs

**Escalation data**

- `id_attempt`, order reference, `leasing_email_sent`, process type, mail template name

---

## 11. PII retention cleanup failure

**Symptoms**

- Generic log: `UniPayment financing snapshot privacy cleanup failed.`
- Old snapshots still contain customer JSON

**Checks**

- `UNIPAYMENT_LAST_PRIVACY_CLEANUP` timestamp (24 h throttle)
- Snapshots older than 180 days with non-empty PII fields
- DB permissions for UPDATE on `unipayment_financing_snapshot`

**Safe actions**

- Fix DB issue; cleanup runs opportunistically on next financing snapshot save after throttle
- Verify redaction on **test** snapshot rows only

**Do not do**

- Assume cleanup failure blocks checkout (it does not)

**Escalation data**

- Last cleanup timestamp, count of eligible rows (aggregate), no customer JSON content

External backups may still retain redacted data — outside module scope.

---

## 12. Safe support data

**Collect**

- PrestaShop `id_order` and **order reference**
- `id_attempt`
- Control Panel order ID (`control_panel_order_id`)
- Attempt / lifecycle / SmartUCF state
- Timestamps (UTC)
- Generic error class names

**Do not collect**

- Shared secret
- CP access token
- EGN or decrypted sensitive payload
- Full customer/address JSON from snapshots
