# UniCredit OpenCart 3.x — frozen contracts (Phase 0)

This document is the canonical implementation reference for later phases.

It records **verified** contracts extracted from:

| Role                 | Path                                         | Authority                                                  |
| -------------------- | -------------------------------------------- | ---------------------------------------------------------- |
| UniCredit behaviour  | `reference-uni-oc4`                          | Calculator, CP, Process 1/2, HMAC, privacy, statuses       |
| OpenCart 3 platform  | `reference-oc3-core` (`VERSION = '3.0.3.9'`) | Payment routes, checkout order lifecycle, OCMOD, settings  |
| Proven OC3 packaging | `reference-jet-oc3`                          | Package layout, Journal asset pattern, payment conventions |

Planning baseline (Phase 0): `67504ea7c7b3aa14dca3dc33ea85919ed605135a`. Phase 0 STOP GATE closure: `9a63d70cb1f1cb9183925880c070950cf8fa5e3a`.

The Master Plan file itself cites an earlier analysis baseline `e7787b091e5258ac0f9d105b5131bf4e58dd9c11`. Phase 0 treats the task commit as the approved planning snapshot and the Master Plan as the authoritative task list.

Tests **must not** contact live CP, test CP, SmartUCF, bank systems, or external customer services. Golden data lives in `tests/fixtures/`.

Later phases cite contract IDs (`CALC-001`, `CP-AUTH-001`, …). Do not rename frozen bank status IDs or CP field names for OC3 convenience.

---

## Compatibility matrix

**Target family:** OpenCart 3.x

**Primary release compatibility targets:**

- OpenCart 3.0.3.6
- OpenCart 3.0.3.8
- OpenCart 3.0.3.9

**Primary local platform reference:** OpenCart 3.0.3.9 (`reference-oc3-core`)

**Module PHP compatibility floor (D1 closed):** **PHP 7.3.0+** — deliberate module support across the practical OC3 matrix above. This is **not** a claim that OpenCart 3.0.3.6 itself universally requires PHP 7.3.

**Current first remote test runtime:** PHP 7.3.33 (unconfirmed in this workspace — see `docs/RUNTIME_VERIFICATION.md`)

| Version | Role                     | Characterized                 | Locally tested     | Remotely tested | Release-supported |
| ------- | ------------------------ | ----------------------------- | ------------------ | --------------- | ----------------- |
| 3.0.3.6 | target                   | no (tree absent)              | no                 | no              | no                |
| 3.0.3.8 | target                   | no (tree absent)              | no                 | no              | no                |
| 3.0.3.9 | local platform reference | yes (source characterization) | no live module run | no              | no                |

A version is **not** “tested” merely because it is a target. Characterization of 3.0.3.9 does not prove 3.0.3.6/3.0.3.8 anchors.

Fixture: `tests/fixtures/compatibility_matrix.json`.

---

## A. Module identity

### MODULE-001 — Extension code and type

- **Code:** `mt_uni_credit` (frozen; matches completed UniCredit modules).
- **Primary OC3 extension types:** `module` (module-wide admin) and `payment` (checkout payment admin/discovery).
- **Catalog payment code:** `mt_uni_credit` (native OC3 `{code}`, not OC4 `{code}.{option}`).
- **Settings groups:** `module_mt_uni_credit` (module-wide) and `payment_mt_uni_credit` (payment-method only).
- **Author:** Авалон ООД.
- **Display name (CP/install metadata):** УниКредит покупки на Кредит.

UniCredit OC3 uses **two separate admin extension surfaces**, mirroring the established UniCredit family (`reference-uni-oc4`):

- **Module** (`extension/module/mt_uni_credit`): module-wide configuration, health, credentials placeholders, operational settings.
- **Payment** (`extension/payment/mt_uni_credit`): native OpenCart checkout/payment settings only.

Homepage advertising may later use the catalog module/layout surface (decision D12); it does not replace the payment code or the admin Module extension.

### MODULE-005 — Dual admin extension surfaces (Phase 1 correction)

> UniCredit OC3 uses two separate admin extension surfaces. The **Module** extension owns module-wide configuration and operations. The **Payment** extension owns only native OpenCart checkout/payment settings.

| Surface | Admin route                       | Permission             | Settings prefix           | Phase 1 owns                                                                                         |
| ------- | --------------------------------- | ---------------------- | ------------------------- | ---------------------------------------------------------------------------------------------------- |
| Module  | `extension/module/mt_uni_credit`  | `modify` on same route | `module_mt_uni_credit_*`  | status, UNICID, advertising, debug, product button, spacing, secret placeholder, operational buttons |
| Payment | `extension/payment/mt_uni_credit` | `modify` on same route | `payment_mt_uni_credit_*` | order status, geo zone, payment status, sort order                                                   |

Install/uninstall ownership:

- **Module** install/uninstall: `module_mt_uni_credit` settings only.
- **Payment** install/uninstall: `payment_mt_uni_credit` settings only.
- Neither uninstall removes financing/order evidence tables (none in Phase 1; preservation policy unchanged).

Do not store module-wide settings under `payment_mt_uni_credit_*`. Do not store payment-method settings under `module_mt_uni_credit_*`.

Phase 1 admin UX (developer-approved final parity):

> OC3 Module administration follows the established UniCredit family settings, ordering, terminology and operational controls as closely as OpenCart 3 permits.

- Visible Bulgarian title: **УниКредит покупки на Кредит** (module and payment admin surfaces).
- Module boolean settings (`status`, `advertising_enabled`, `debug_enabled`) use OC3 checkbox + hidden `0` semantics.
- Module fields (order): status, UNICID, secret, advertising, debug, product button action, button top spacing, operational buttons (refresh bank data, download journal).
- **Environment is not exposed** in Module admin UI (Phase 4 owns CP environment configuration).
- **Health/readiness panel is not exposed** in Module admin UI; internal helpers may remain for later phases/tests.
- Payment listing logo: `admin/view/image/payment/uni_logo.svg` via `text_mt_uni_credit`, rendered at `max-width:200px`.
- Fresh payment install default `payment_mt_uni_credit_order_status_id` resolves store **Processing** by localized name lookup, then documented fallback `2`.

### MODULE-002 — Classic OC3 class / route identity

Characterized from `reference-oc3-core` 3.0.3.9 + JET payment layout:

| Surface                    | Value                                                                                              |
| -------------------------- | -------------------------------------------------------------------------------------------------- |
| Admin module controller    | `admin/controller/extension/module/mt_uni_credit.php` → `ControllerExtensionModuleMtUniCredit`     |
| Admin payment controller   | `admin/controller/extension/payment/mt_uni_credit.php` → `ControllerExtensionPaymentMtUniCredit`   |
| Catalog payment controller | `catalog/controller/extension/payment/mt_uni_credit.php` → `ControllerExtensionPaymentMtUniCredit` |
| Catalog payment model      | `catalog/model/extension/payment/mt_uni_credit.php` → `ModelExtensionPaymentMtUniCredit`           |
| Admin module route         | `extension/module/mt_uni_credit`                                                                   |
| Admin payment route        | `extension/payment/mt_uni_credit`                                                                  |
| Catalog payment route      | `extension/payment/mt_uni_credit`                                                                  |
| Module language            | `{admin}/language/{en-gb\|bg-bg}/extension/module/mt_uni_credit.php`                               |
| Payment language           | `{admin\|catalog}/language/{en-gb\|bg-bg}/extension/payment/mt_uni_credit.php`                     |
| Admin module Twig          | `admin/view/template/extension/module/mt_uni_credit.twig`                                          |
| Admin payment Twig         | `admin/view/template/extension/payment/mt_uni_credit.twig`                                         |
| Catalog Twig               | `catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig`                         |

Do **not** copy OC4 namespaces (`Opencart\Admin\Controller\Extension\MtUniCredit\`). OC3 discovery is classic non-namespaced `Controller` / `Model` + `Registry` / `Loader`.

### MODULE-003 — Package identity

Expected distributable:

```text
module.ocmod.zip
├── install.xml
└── upload/
    ├── admin/
    ├── catalog/
    └── system/
```

No Composer runtime install on the shop. Do not bundle a second mail stack when OC3 Mail can satisfy Process 2.

### MODULE-004 — Version policy (D2 closed)

- **Module code:** `mt_uni_credit` (frozen).
- **Extension type:** `payment` (frozen).
- **Module / CP version:** **`2.0.2`** (frozen for initial OC3 UniCredit release and CP payload identity).
- CP `version` field format: `^\d{1,3}\.\d{1,3}\.\d{1,3}$`.

Use `2.0.2` unless a later coordinated release explicitly changes it.

Fixture: `tests/fixtures/extension_identity.json`.

---

## B. Calculator contract

Parity source: `reference-uni-oc4` golden data (oracle date **2026-08-17**), not a mechanical port of OC4 classes.

Fixture: `tests/fixtures/calculator_golden.json`.

### CALC-001 — Scheme identity

A financing scheme is identified by:

```text
type | kopCode | months
```

Optional metadata: `filterId` (lowest wins in cart intersection; not part of the identity key).

`type` is `standard` or `promo`.

### CALC-002 — Eligible schemes and filter rules

Shop snapshot drives eligibility:

- Shop must be active (`uni_status` yes).
- Amount must be inside inclusive shop bounds `uni_minstojnost` … `uni_maxstojnost`.
- Months in range **3–36**, and enabled via `uni_meseci_{n}`.
- `uni_typekop = 0` → default KOP (`kop.by_default`).
- `uni_typekop = 1` → schema filters (`kop.by_schema.filters`).
- Filter match: category **or** product (not both together), price window, date window (`today` in golden cases is `2026-08-17`), months list (`uni_meseci` as `_`-separated), promo flag.
- Promo `type=promo` requires **zero interest**. Non-zero promo interest is rejected.
- `uni_promo_meseci_znak` is literal `eq` or `greateq` (spelling frozen).

### CALC-003 — Coefficient and months resolution

Coefficient row fields: `onlineProductCode`, `installmentCount`, `coeff`, `interestPercent`.

Preferred months come from `uni_shema_current`. Missing preferred month falls back to the highest eligible promo/standard month per golden case `month_selection`. Invalid preferred promo coefficient does **not** invent a fallback coeff.

### CALC-004 — First installment

- When filter `uni_parva === 1`: first installment is **locked** to `round(price / months, 2)`, visible.
- When shop `uni_first_vnoska` allows user input: requested first installment is applied; financed amount is `price - first`.
- Financed amount is the amount multiplied by the coefficient.

Golden locked case (`first_installment_locked_uni_parva`, price 1000, 24 months, coeff 0.05):

- first = 41.67, financed = 958.33, monthly = 47.92, total = 1150.08, GPR(calculateScheme) = 19.76.

### CALC-005 — Monthly, total, GPR/APR presentation

```text
monthly        = round(financed * coeff, 2)
totalPayable   = round(monthly * months, 2)
glp            = round(abs(interestPercent), 2)
gpr_calculateScheme = raw <= 0.1 ? 0.0 : round(raw, 2)
gpr_offerFactory    = round(raw, 2)   /* no 0.1 floor */
```

**Frozen internal split:** 0% promo preferred offer has `gpr_offerFactory = 0.01` and `gpr_calculateScheme = 0.0`. OC3 must preserve this split until the UniCredit oracle changes it.

Standard preferred example (price 1000, STD, 12 months, coeff 0.095): monthly **95.00**, GLP **18.00**, GPR(offerFactory) **27.96**.

### CALC-006 — Rounding and deterministic ordering

Presentation rank: `standard (0)` → `nonzero_promo (1)` → `zero_promo (2)`, then months ASC, then `filterId` ASC, then `kopCode` strcmp, then `type` strcmp.

Preferred-offer tie-break at preferred months: lowest monthly installment. If preferred months are missing, highest months wins.

### CALC-007 — Cart scheme intersection

- Identity key: `type|kopCode|months`.
- Price basis for every line: **cart total**, not line total.
- Empty intersection → no offer.
- `filter_id` is metadata; lowest wins.
- Cart total 99 empty; 10000 ok; 10000.01 empty (shop bounds in the golden shop).

### CALC-008 — Currency

- Supported ISO: **BGN**, **EUR** only.
- `uni_eur ∈ {0,1}` expects BGN; `uni_eur ∈ {2,3}` expects EUR.
- Display rate **1.95583**.
- Server-authoritative; do not trust browser totals.

### CALC-009 — Invalid / no-offer behaviour

If the shop is inactive, amount is out of bounds, currency is unsupported, coefficients are missing, or intersection is empty: **no offer**. Do not invent schemes, coefficients, or placeholders.

---

## C. CP authentication contract

Fixtures: `tests/fixtures/cp_auth_contract.json`, `tests/fixtures/cp_api_endpoints.json`.

### CP-AUTH-001 — Endpoints and methods

Base prefix: `/api/v1`.

| Method | Path                             | Auth   | Notes                                                                   |
| ------ | -------------------------------- | ------ | ----------------------------------------------------------------------- |
| POST   | `/api/v1/auth/login`             | no     | body `unicid`, `name`, `secret`                                         |
| POST   | `/api/v1/auth/refresh`           | Bearer | rotates token; old forgotten                                            |
| POST   | `/api/v1/auth/logout`            | Bearer | repeat logout → 401                                                     |
| GET    | `/api/v1/shop`                   | Bearer | snapshot + `coeff_list`                                                 |
| GET    | `/api/v1/ssl/certificate`        | Bearer | Process 1 cert metadata                                                 |
| GET    | `/api/v1/ssl/certificate/bundle` | Bearer | cert + key PEM; never passphrase                                        |
| POST   | `/api/v1/orders`                 | Bearer | see CP-ORDER-\*                                                         |
| PATCH  | `/api/v1/orders/status`          | Bearer | `order_id` max 13, `status` required, `status_id` optional; **no** enum |

Login is **not** idempotent. Token length 64, type Bearer, TTL **86400** seconds.

`name` on login is the canonical shop URL (HTTPS, no trailing slash).

### CP-AUTH-002 — Bearer lifecycle and 401 retry

- Store bearer with expiry, store-scoped, encrypted at rest (`enc:v1:`).
- Refresh when within **60** seconds of expiry (`CpHttpConstants::REFRESH_MARGIN_SECONDS`); on refresh auth failure, login.
- On **401**: invalidate → login → **exactly one** replay of the original request. Never loop. Second 401 is permanent auth failure + invalidation.

### CP-AUTH-003 — Transport / JSON / errors

- HTTPS, TLS peer verification ON.
- Connect timeout **5s**, total timeout **15s**, max response **1 MiB**.
- Request `Accept` / `Content-Type`: `application/json`.
- Success JSON must have `success === true`.
- Classify: malformed JSON, 4xx permanent, 5xx/network transient, timeout/transport ambiguous.
- Permanent 4xx/auth/invalid payload → purge scoped cache + tokens.
- Transient 5xx/network → preserve cache + tokens.

### CP-AUTH-004 — Environment selection (D4 closed for Phase 0)

**Frozen for Phase 0:**

- CP API prefix: **`/api/v1`**
- SmartUCF destination allowlist: **`online.ucfin.bg`**, **`onlinetest.ucfin.bg`**
- Trusted paths: `/suos/api/otp/` + `sucfOnlineSessionStart`; `/sucf-online/Request/Start`
- Control Panel and SmartUCF hosts are configuration constants, **not** arbitrary admin URLs. HTTPS required.

**Deferred (not Phase 1 blockers):**

- Exact deployment CP hostname → **`upload/config/environment.php`** (`control_panel_url`); switch at packaging time, not in Module admin UI (Phase 4 implemented)
- Final OC3 inbound callback URLs → Phase 6 CP registration

**Phase 4 implementation notes:**

- CP bearer tokens persist encrypted in `oc_setting` keys `module_mt_uni_credit_cp_access_token`, `_cp_token_type`, `_cp_token_expires_at` (store-scoped). No new Phase 4 DB table.
- Admin **Обнови данните от банката** performs transparent login + `GET /shop` + validated cache replace.

Do not invent deployment hostnames or registered callback URLs in Phase 0.

No live network in Phase 0.

---

## D. Shop configuration / cache contract

Fixture: `tests/fixtures/shop_cache_contract.json`.

### CACHE-001 — Snapshot structure and required fields

Validate before replace (pull or push):

Required / constrained:

- `uni_status` ∈ {0,1}
- `uni_typekop` ∈ {0,1}
- `uni_proces` ∈ {0,1}
- `uni_env` ∈ {0,1} (`0` = test SmartUCF)
- `uni_eur` ∈ {0,1,2,3}
- `uni_minstojnost`, `uni_maxstojnost` finite; min ≤ max
- `uni_meseci_3` … `uni_meseci_36` present and yes-flag compatible
- `uni_shema_current` 0 or 3–36
- `kop.by_default`, `kop.by_schema.filters`
- `coeff_list` array (empty list is valid and **must** be allowed to replace previous data)
- Coefficient: `onlineProductCode` non-empty string, `installmentCount` 3–36, `coeff` and `interestPercent` finite numbers
- Optional `unicid` must match the authenticated shop UNICID when present
- Optional `consents`, `uni_first_vnoska`, `uni_sertificat`

Bearer tokens must **not** be stored inside snapshot JSON.

### CACHE-002 — Scope and replacement

Unique cache key: **`(store_id, unicid)`**.

- Pull: `GET /api/v1/shop` → validate → replace that row.
- Push: inbound `shop_cache` validates and replaces the same row **without** calling CP.
- Invalid snapshot does **not** overwrite a known-good cache.
- Credential/UNICID change invalidates tokens and that store’s cache only.

### CACHE-003 — Fresh / stale / display vs submit (D11 related)

- Cache TTL: **86400** seconds (frozen from completed module `SecurityConstants::SHOP_CACHE_TTL_SECONDS`).
- **Display:** last-known validated snapshot may be shown only while TTL has not expired.
- **Side-effecting order submission:** requires a sufficiently fresh validated snapshot or must fail safely.
- An extra stale-grace window **after** TTL is **not** frozen in the OC4 source. Recommendation (D11): no extra grace. Do not silently widen.

---

## E. CP order contract

Fixture: `tests/fixtures/cp_order_payload.json`.

### CP-ORDER-001 — Endpoint

`POST /api/v1/orders`

Headers: `Authorization: Bearer <access_token>`, JSON content type.

Throttle (CP-side): 60 / shop / minute.

### CP-ORDER-002 — Field names, meaning, limits

| Field           | Rule                                                                                                                    |
| --------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `order_id`      | required string, **max 13**; local OC order id as string                                                                |
| `name`          | required, max **65**                                                                                                    |
| `phone`         | required, max **45**, `^[\d\s\-\+\(\)]+$`; Checkout may send `''` when OC has no telephone — do not invent placeholders |
| `email`         | required email, max **128**                                                                                             |
| `address`       | required, max **256**                                                                                                   |
| `address2`      | optional, max **256**; empty fallback `'-'` in completed builder                                                        |
| `price`         | required numeric ≥ 0, `round(..., 2)`                                                                                   |
| `vnoska`        | monthly installment, required numeric ≥ 0, 2 dp                                                                         |
| `gpr`           | required numeric ≥ 0, 2 dp                                                                                              |
| `vnoski`        | months, optional int 1–255, default 12                                                                                  |
| `parva`         | first installment, optional numeric ≥ 0, default 0, 2 dp                                                                |
| `products_id`   | optional; implode ids with `_`                                                                                          |
| `products_name` | optional; underscore in names → hyphen, implode `_`; builder max 255                                                    |
| `products_q`    | optional; implode qty `_`, qty ≥ 1                                                                                      |
| `type_client`   | 0–255, default 0; completed modules: `0` if mobile else `1`                                                             |
| `currency`      | max 3, **`in:BGN,EUR`**, API default BGN                                                                                |
| `version`       | max 11, `x.x.x`; frozen **`2.0.2`** (D2)                                                                                |

**Create-time:** omit `status` / `status_id`. CP defaults to `Създаден в КП Банка` / `cp_sent`.

Do not send EGN or phone2 on this payload.

Known CP message discrepancy: `currency.in` error text may mention USD; the rule accepts only BGN and EUR.

### CP-ORDER-003 — Success, idempotency, conflict, timeout

Idempotency key: CP `(shop_id, order_id)`.

| Outcome                        | HTTP    | Action                                                                                      |
| ------------------------------ | ------- | ------------------------------------------------------------------------------------------- |
| Created                        | 201     | persist CP id, state `cp_created`                                                           |
| Same semantic hash             | 200     | treat as success (already created)                                                          |
| Different semantic hash        | **409** | conflict; not success; do not overwrite frozen payload                                      |
| Timeout / transport after send | —       | `cp_outcome_unknown`; replay **exact frozen payload**; never a second local order reference |

Semantic fields (frozen): `name`, `phone`, `email`, `address`, `address2`, `price`, `vnoska`, `gpr`, `vnoski`, `parva`, `status`, `status_id`, `products_id`, `products_name`, `products_q`, `type_client`, `currency`, `version`.

Floats: `price`, `vnoska`, `gpr`, `parva`. Integers: `vnoski`, `type_client`.

`cp_payload` is immutable after first freeze. A mismatch on retry is a conflict, not an update.

PATCH `/api/v1/orders/status` uses the **shop** `order_id` from create (local OC id), not the CP internal PK.

---

## F. Bank status vocabulary

Fixture: `tests/fixtures/status_vocabulary.json`.

**Do not rename status IDs for OC3 convenience.**

### STATUS-001 — Process flag

Shop field `uni_proces`:

- `(int) uni_proces === 1` → **Process 2** (secondary / inverted numeric vs name)
- otherwise → **Process 1**

### STATUS-002 — Outbound module → CP

| `status_id`                 | `status_label`                      | When it MAY be recorded                              | Must NOT                                                     |
| --------------------------- | ----------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------ |
| `bank_sent_process1`        | Изпратен Банка - Процес 1           | CP order exists **and** SmartUCF Process 1 succeeded | On CP create; on Process 2; on pre-send/ambiguous SmartUCF   |
| `bank_sent_process2`        | Изпратен Банка - Процес 2           | Process 2 bank handoff after validation/encrypt      | On CP create; on Process 1; before EGN/phone2 valid          |
| `bank_send_failed`          | Неуспешно изпратен Банка            | Legacy / Process 2 bank-send failure path            | As a substitute for SmartUCF unknown outcome                 |
| `bank_send_failed_cp`       | Неуспешно изпратен Банка - КП       | CP create failed (attempt taxonomy)                  | As a bank-sent label on the create payload                   |
| `bank_send_failed_smartucf` | Неуспешно изпратен Банка - SmartUCF | CP created **and** definitive SmartUCF rejection     | Pre-send cert failure; timeout; `outcome_unknown`; Process 2 |

Local bank status is stored separately from native OpenCart `order_status_id`. Inbound callbacks **must not** change native OC order status.

### STATUS-003 — CP enum (inbound / display)

API does **not** enum-validate create/patch status strings. Default on create: `cp_sent` / `Създаден в КП Банка`.

Inbound accepted `status_id` vocabulary includes at least:

- `cp_sent`, `smartucf_sent`
- `bank_sent_process1`, `bank_sent_process2`
- `bank_send_failed`, `bank_send_failed_cp`, `bank_send_failed_smartucf`
- SmartUCF numeric codes `^\d{1,3}$`

Unsupported → HTTP 400. Same status twice → idempotent upsert.

CP display enum (labels may have null `status_id`): Създаден в КП Банка, Създаден в SmartUCF, Онлайн подписване на договор, В процес на онлайн идентификация, Отказана, Сключен договор, Въвежда се - фаза 1, Регистрирана, Отказана от клиент при контакт, Активиран договор, Отказана от клиент.

---

## G. Process 1 / SmartUCF contract

Fixture: `tests/fixtures/process1_contract.json`.

No live SmartUCF request in Phase 0.

### P1-001 — Prerequisites and certificate mode

Prerequisites: local OC order, `cp_created`, validated snapshot, `uni_proces !== 1`.

When `uni_sertificat` is enabled: sync/validate `GET /ssl/certificate` metadata and download bundle only for missing/mismatched material. SHA-256 over **exact PEM bytes**. Passphrase stays in `secrets/smartucf-key.php`. Modes: certificate `0640`, key `0600`; temporary consumer copies `0600` removed in `finally`. Transient CP metadata failure may fail open **only** with a complete valid local pair. Explicit unavailability fails closed.

Pre-send certificate/sync failure is retryable and does **not** write `bank_send_failed_smartucf`.

### P1-002 — Trusted endpoints

Only:

- Hosts: `online.ucfin.bg`, `onlinetest.ucfin.bg`
- Service: `/suos/api/otp/` + `sucfOnlineSessionStart`
- Application: `/sucf-online/Request/Start[/{sessionId}]`
- HTTPS, port 443, no userinfo/query/fragment
- TLS peer + hostname verification always on
- Timeout **10** seconds
- Redirects disabled or revalidated
- `uni_env === 0` → test; otherwise production. Admin must not type arbitrary URLs.

### P1-003 — Request lifecycle and outcomes

```text
not_started → submitting → created | failed | outcome_unknown
```

- **Success:** persist trusted redirect; PATCH/local `bank_sent_process1`; return `redirect_url` with `bank_submitted=true`.
- **Definitive remote rejection:** `bank_send_failed_smartucf` locally and CP.
- **Ambiguous post-send** (timeout, stale `submitting`, duplicate-order evidence, invalid response): `outcome_unknown`; **no** terminal bank failure; **no** automatic second SmartUCF call; tell the customer not to resubmit.
- Created attempt **replays** the stored trusted redirect.

Failed CP status PATCH after a valid SmartUCF session does not rewrite bank failure; later retry reconciles status without a second SmartUCF call.

### P1-004 — Process 2 exclusion

`uni_proces === 1` skips SmartUCF entirely. Zero `sucfOnlineSessionStart` calls.

---

## H. Process 2 contract

Fixture: `tests/fixtures/process2_contract.json`.

### P2-001 — Inputs and validation

Required after CP create when `uni_proces === 1`:

- **EGN:** 10 digits; first 8 a valid calendar `YYYYMMDD`; **no** Bulgarian checksum.
- **phone2:** charset `[-0-9+() ]` and at least one digit; no min/max length.

Validate **before** claiming a validating state so field errors leave the attempt `issued` and the token retryable.

Product/Cart primary phone remains required. Checkout primary phone remains optional; phone2 is separate.

### P2-002 — Storage, CP exclusion, encryption, status timing

- Store EGN/phone2 only as encrypted `process2_sensitive_enc`.
- **Never** send EGN/phone2 to CP.
- Authenticated encryption (`enc:v1:` AES-256-GCM preferred). Fail closed if the key cannot be resolved. No plaintext fallback.
- After encrypt: PATCH/local `bank_sent_process2` using shop order id = local OC order id.
- Never write `bank_sent_process1` or `bank_send_failed_smartucf` for Process 2.
- Once `bank_sent_process2` is recorded, retry must not repeat the bank handoff.

### P2-003 — Mail audiences and degraded mail

- Customer Process 2 mail: confirmation / leasing snapshot, **no EGN**.
- Admin / `uni_email` mail: EGN + phone2 allowed.
- Independent idempotent mail marker (`process2_mail_sent`). Mail failure is recoverable and must not undo bank state.
- Continue to native Thank You (no SmartUCF).

---

## I. Inbound API security contract

Fixtures: `tests/fixtures/hmac_callback_vector.json`, `tests/fixtures/inbound_api_contract.json`.

Verified from the completed UniCredit HMAC implementation/tests, not from plan prose alone.

### SEC-HMAC-001 — Protocol

Headers:

- `X-UniPayment-Timestamp`
- `X-UniPayment-Nonce`
- `X-UniPayment-Signature`

Canonical signed bytes:

```text
timestamp + "\n" + nonce + "\n" + exact_raw_body
```

- HMAC-SHA256 → **lowercase hex**
- Compare with `hash_equals`
- Timestamp: decimal digits, window **±300** seconds
- Nonce: **exactly 64** hex (`^[0-9a-fA-F]{64}$`); persist only `sha256(nonce)`; unique `(store_id, unicid, nonce_hash)`; retain **900** seconds
- Verify **raw body before JSON decode**. Re-encoding JSON must not match.
- Invalid signature **must not** consume the nonce
- Claim nonce atomically **after** signature validation
- Bind body `unicid` to the exact store-scoped credential
- Valid replay of a consumed nonce → 401

### SEC-HMAC-002 — Known vector (test secret only)

From `hmac_callback_vector.json` (not a production secret):

- secret: `test_shared_secret_123`
- timestamp: `1787380000`
- nonce: `0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`
- raw body: `{"unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"10"}`
- expected HMAC: `2f4a55c19a2dd0f2f7f2390a6d720e95dbdff577c096d7ff291ef8f84a53e94f`

### API-001 — Conceptual OC3 inbound routes (D4 deferred to Phase 6)

POST-only JSON, no SEO aliases. Conceptual only; **not** registered with CP until Phase 6:

```text
index.php?route=extension/mt_uni_credit/api/shopCache
index.php?route=extension/mt_uni_credit/api/orderBankStatus
index.php?route=extension/mt_uni_credit/api/smartUcfDebugLog
```

Errors: 400 / 401 / 403 / 404 / 405 / 422 / 500 (redacted). Never theme HTML on these endpoints.

---

## J. Store / multistore ownership

### STORE-001 — Exact `store_id` scope

Every attempt, correlation, bank status, cache, nonce, diagnostic, credential and token lookup includes exact `store_id`.

- `store_id = 0` is the default store and a **real** scope.
- Negative ids are invalid.
- Missing scope is **not** equivalent to 0.
- **Prohibition:** never fall back from a nonzero store to store 0 (including colliding numeric `order_id` values).

### STORE-002 — Ownership surfaces

| Surface              | Rule                                              |
| -------------------- | ------------------------------------------------- |
| Order correlation    | unique `(store_id, order_id)` and attempt         |
| Shop cache           | `(store_id, unicid)`                              |
| Callbacks            | `config_store_id` + owned UniCredit order/attempt |
| Diagnostics          | store + order; redacted                           |
| Credentials / tokens | store-scoped encrypted settings                   |

Logged-customer Thank You additionally matches `customer_id`. Guests use unpredictable session/attempt binding.

---

## K. Privacy and retention

Fixture: `tests/fixtures/privacy_retention.json`.

Do not weaken privacy because OC3 is older.

### PII-001 — Audiences and exclusions

- CP frozen payload is the only durable customer snapshot needed for CP retry.
- EGN/phone2 exist only as Process 2 ciphertext.
- Customer email, customer Thank You: never EGN/phone2.
- Thank You identity is session-only; GET `order_id` is never trusted.
- Admin email and admin order detail may show EGN/phone2 for Process 2.
- Logs: identifiers, state, error class, HTTP status only — never secrets, keys, EGN, email, phone, address, raw payloads.

### RETENTION-001 — Windows

| Data                                    | Retention                                  |
| --------------------------------------- | ------------------------------------------ |
| Process 2 ciphertext                    | **180** days then redact                   |
| Leasing presentation JSON               | **183** days                               |
| Diagnostic journal                      | **3 months**                               |
| Inbound nonces                          | **900** seconds                            |
| Operational attempt / order identifiers | keep unless later policy requires deletion |

Cleanup in bounded batches. Opportunistic plus documented cron/admin trigger.

### RETENTION-002 — Uninstall

Preserve financing/audit tables by default. Remove module settings and OCMOD registration. Separate explicit purge only if later required (D9).

---

## L. Duplicate / idempotency

### IDEM-001 — Submission token and operation identity

- Random submission token, expiry, one durable attempt.
- Bound to actor + authoritative selection hashes (product/cart/checkout as applicable).
- Operation key is deterministic per store / entry point / selection.
- Atomic unique lock serializes the operation (TTL **45** s). Lock expiry recovers abandoned **pre-side-effect** work only; it never authorizes repeating an ambiguous external send.
- Correlation is written immediately after `addOrder()` (Product/Cart). Checkout correlates the native `session.order_id`.
- Same-token retry returns the stored result/redirect without a second remote call.

### IDEM-002 — Failure classes (unsafe resend prohibition)

| Class                     | Meaning                                     | Fresh resend?                                                                                                 |
| ------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Definite pre-send failure | never reached remote / local validation     | retryable with same or new attempt per rules                                                                  |
| Definite remote rejection | CP 4xx validation, SmartUCF business reject | terminal for that frozen attempt; not a new semantic CP order with a different payload on the same `order_id` |
| Ambiguous post-send       | timeout after send, unknown outcome         | **never** an unsafe fresh resend; replay exact frozen payload / stored redirect only                          |

Ambiguous outcomes must tell the customer not to resubmit.

---

## M. OC3 native checkout contract

Characterized from `reference-oc3-core` **3.0.3.9** only. 3.0.3.6 / 3.0.3.8 are verification requirements.

Fixture: `tests/fixtures/oc3_lifecycle.json`.

### OC3-CHECKOUT-001 — Native order creation (project rule)

**Checkout reuses the native OpenCart order and must never create a second order from the UniCredit payment controller.**

On 3.0.3.9:

1. `catalog/controller/checkout/confirm.php::index()` builds `$order_data` when shipping/payment/cart validation passes.
2. It **always** calls `model_checkout_order->addOrder($order_data)` — there is **no** reuse of an existing `session.order_id` and **no** `editOrder()` on this path.
3. `$this->session->data['order_id']` is assigned **immediately** from `addOrder()`.
4. Payment body is then `$this->load->controller('extension/payment/' . $session['payment_method']['code'])`.
5. `addOrder()` INSERT omits `order_status_id`; schema default is **0** (`install/opencart.sql`).
6. Native COD `confirm()` calls `addOrderHistory($this->session->data['order_id'], payment_cod_order_status_id)` and does **not** call `addOrder()`.
7. `checkout/success` clears cart + session (`order_id`, payment/shipping, guest, totals, …) and does **not** mutate the DB order.

Payment identifier on OC3 is `{code}` (varchar `payment_code`), **not** OC4 `{code}.{option}` JSON.

**Authoritative at confirm time:** persisted order row for `session.order_id` (totals, currency, store, customer, `payment_code`), plus live cart/scheme revalidation. Browser fields carry only token, scheme identity, consent, and Process 2 inputs.

Product and Cart financing flows are **not** native checkout; they may materialize **one** local OC order in later phases.

### OC3-CHECKOUT-002 — What the payment controller must not do

- Must not call `addOrder()`.
- Must not invent a second local order reference after confirm has created one.
- Must not trust posted identity fields except explicit consent / Process 2 inputs.
- Must not treat OC4 `editOrder()` void-status hazard as native OC3 behaviour (`not applicable`). Stale `session.order_id` after cart mutation still needs an OC3-specific guard in later phases (Master Plan D8 / recovery), characterized from this core, not copied from OC4 events.

Going back and forth on native OC3 confirm can create additional status-0 drafts; that is core behaviour. UniCredit still must not add another order on `confirm`.

### OC3-CHECKOUT-003 — Payment discovery

`checkout/payment_method` loads enabled `payment_{code}_status` extensions and calls `ModelExtensionPayment{Code}::getMethod($address, $total)` where `$total` is the native totals pipeline (not `cart->getTotal()` alone).

`getMethod` returns `code`, `title`, `terms`, `sort_order` or empty.

---

## N. OCMOD integration policy

### OCMOD-001 — Approved mechanism

- OCMOD is the preferred injection into existing OC3 flows/templates (`install.xml`).
- Do **not** introduce parallel event-based injection merely to avoid OCMOD.
- Native payment / module / API routes remain native where no modification is needed.
- Injected code must stay minimal and **delegate** to module-owned logic.
- Each operation needs a narrow search anchor and a unique module marker.
- Optional theme placement must degrade safely (`error="skip"` where an optional theme anchor may be absent). Avoid `error="abort"` when one optional mismatch would break the entire refresh.
- Compatibility must eventually be checked against 3.0.3.6, 3.0.3.8 and 3.0.3.9.

Likely later injection points (not implemented in Phase 0): product controller/template, cart controller/template, success, admin order list/info, mail templates. See Master Plan §7.2.

### JOURNAL-001 — Journal-compatible assets (from JET)

Proven `reference-jet-oc3` pattern (product widget):

1. Physical assets under `catalog/view/theme/default/template/extension/<module>/`.
2. Public URL from that default-theme path + guarded `filemtime`.
3. Fragment-local `<link>` / `<script>` beside the product widget (Journal may cache the header before OCMOD-injected product PHP runs).

**Do not copy:** unguarded `filemtime` (JET can warn on missing files), business logic inside XML, bundled PHPMailer, `error="abort"` on optional theme files.

Cart in JET uses `Document::addStyle/addScript`; product uses inline tags. UniCredit should keep inline fallback harmless on all themes and must not detect Journal by brittle class/file probes.

**Phase 2 frozen rules (storefront phases only; not implemented in Phase 2):**

4. Storefront assets for injected Product/Cart fragments may be referenced from the fragment Twig itself where controller-level `addStyle()` / `addScript()` is unreliable under Journal.
5. Distributable assets remain separate CSS/JS files under the default-theme fallback path (`catalog/view/theme/default/template/extension/<module>/…`).
6. `filemtime()` cache busting must be guarded (skip or omit when the file is absent; never emit PHP warnings).
7. Storefront JS must **not** assume `window.jQuery` exists when the script file is evaluated.
8. jQuery-dependent initialization must wait safely for `window.jQuery`.
9. Waiting must be bounded, not infinite (explicit timeout/retry cap).
10. Initialization and event binding must be idempotent because Journal may rebuild/reinject fragments.
11. No Journal-specific business logic may leak into domain/business services.
12. Standard OC3 themes must continue using the same implementation (no Journal-only fork).
13. Delayed-jQuery handling applies only to storefront assets that need it, not globally to every JS file.

### OC3-ROUTE-001 — Nested catalog API routes

On 3.0.3.9, `Action` resolves `extension/mt_uni_credit/api/shopCache` to file `catalog/controller/extension/mt_uni_credit/api.php`, class `ControllerExtensionMtUniCreditApi`, method `shopCache`. Confirm the same on 3.0.3.6 and 3.0.3.8 before freezing controller split vs shared API controller.

---

## PHP compatibility characterization

Fixture: `tests/fixtures/php_floor.json`.

### PHP-001 — Floor (D1 closed)

**Minimum module PHP version:** **7.3.0**

The UniCredit OpenCart 3 module deliberately supports **PHP 7.3.0+** across its practical OC3 compatibility matrix (3.0.3.6, 3.0.3.8, 3.0.3.9). This is the **module** floor — not a statement that OpenCart 3.0.3.6 itself universally requires PHP 7.3.

Do not freeze PHP 7.1 merely because crypto is theoretically available there. Newer PHP may run where OC3 and installed modifications permit; that is not claimed as release-supported here.

Deferred runtime checks (do not block Phase 1): remote `php -v` / modules / OpenSSL ciphers on the test host.

### PHP-002 — Forbidden syntax above PHP 7.3 floor

Implementation must avoid until a higher floor is explicitly approved:

typed properties; union/intersection types; `mixed`; constructor promotion; attributes; enums; `match`; arrow functions; nullsafe `?->`; `str_contains` / `str_starts_with` / `str_ends_with`; `Throwable`-only assumptions.

Prefer OC3-style arrays and untyped signatures where portability improves.

Required extensions: cURL+TLS, OpenSSL (AES-256-GCM), JSON, hash/HMAC, `random_bytes`.

Database: MySQL/MariaDB via `$this->db`, `DB_PREFIX`, escaped values; portable SQL; InnoDB recommended. Charset/collation is a runtime check (do not assume `utf8mb4_unicode_ci` without fallback).

---

## Secret / certificate deployment

Fixture: `tests/fixtures/secret_deployment.json`.

### DEPLOY-001 — Established UniCredit filenames

Follow completed modules wherever technically possible:

| Material            | Relative path                                  | Notes                                        |
| ------------------- | ---------------------------------------------- | -------------------------------------------- |
| SmartUCF passphrase | `secrets/smartucf-key.php`                     | returns `['passphrase' => …]`; never from CP |
| Certificate PEM     | `keys/avalon_cert.pem`                         | CP-synchronizable                            |
| Private key PEM     | `keys/avalon_private_key.pem`                  | CP-synchronizable                            |
| CP host             | `config/environment.php` → `control_panel_url` | packaging-time switch                        |

Authoritative modes: cert `0640`, key `0600`, passphrase file `0600`. Health checks: presence, path, owner/group, permissions, PEM SHA-256 — **never** secret contents.

### DEPLOY-002 — Key sources (D3 closed for Phase 0)

- CP login secret: admin setting, AES-256-GCM `enc:v1:`, never re-displayed.
- Settings encryption key (contract from OC4): HKDF-SHA256(`DB_PASSWORD`, 32 bytes, info `mt_uni_credit/settings-encryption/v1`) → AES-256-GCM `enc:v1:`. Fail closed if key material cannot be resolved. **No plaintext fallback. No predictable metadata fallback.**
- SmartUCF passphrase: `secrets/smartucf-key.php` only.

**Phase 2 semantic verification (verified against `reference-uni-oc4`):**

| Parameter                 | Verified value                                                                                                |
| ------------------------- | ------------------------------------------------------------------------------------------------------------- |
| HKDF hash                 | `sha256` via `hash_hkdf()`                                                                                    |
| Input key material        | `DB_PASSWORD` constant (non-empty)                                                                            |
| Salt                      | none (PHP default empty salt)                                                                                 |
| Info/context              | `mt_uni_credit/settings-encryption/v1`                                                                        |
| Derived key length        | 32 bytes                                                                                                      |
| Cipher                    | AES-256-GCM (`openssl_encrypt` / `openssl_decrypt`, `OPENSSL_RAW_DATA`)                                       |
| IV/nonce length           | 12 bytes (`random_bytes`)                                                                                     |
| Authentication tag length | 16 bytes                                                                                                      |
| Ciphertext encoding       | raw bytes concatenated with IV+tag, then base64                                                               |
| Storage envelope          | `enc:v1:` + base64(`iv[12]` + `tag[16]` + `ciphertext`)                                                       |
| Decryption failure        | fail closed (`RuntimeException`); no plaintext fallback; corrupt envelope returns null at repository boundary |

Fixture: `tests/fixtures/crypto_hkdf_vector.json`. Phase 0 inferred the mechanism from OC4 reference sources; Phase 2 implementation matches OC4 semantics.

### DEPLOY-003 — OC3 path translation (D3 closed for Phase 0)

Keep the **same relative filenames** as OC4:

```text
secrets/smartucf-key.php
keys/avalon_cert.pem
keys/avalon_private_key.pem
```

Resolve the directory from a module root helper. If the shop document root would expose PEM/passphrase, use a protected location (`DIR_STORAGE/mt_uni_credit/…` or equivalent) — an OC3 filesystem constraint, **not** a new secret format.

Final physical protected root, ownership and permissions are **runtime/deployment verification** items. They do **not** block Phase 1.

---

## Phase 0 exclusions

Phase 0 does **not** create: admin/catalog payment implementation, product/cart calculators, CP/SmartUCF clients, DB schema installer, OCMOD injections, storefront JS/CSS, or live payment lifecycle code.

---

## Decision log (Phase 0 STOP GATE)

| ID                                 | Status                        | Outcome                                                                                                                                          |
| ---------------------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| D1 PHP floor                       | **CLOSED**                    | Module minimum **PHP 7.3.0+** across practical OC3 matrix; not a universal OC 3.0.3.6 core requirement                                           |
| D2 Module version                  | **CLOSED**                    | Code `mt_uni_credit`, type `payment`, version **`2.0.2`** for module and CP payload identity                                                     |
| D3 Secrets/certs                   | **CLOSED (Phase 2 verified)** | OC4 filenames frozen; HKDF+AES-256-GCM semantic parity verified locally; physical root/permissions deferred to deployment; no plaintext fallback |
| D4 CP/SmartUCF env + OC3 callbacks | **CLOSED FOR PHASE 0**        | `/api/v1` and SmartUCF allowlist frozen; deployment CP hostname → Phase 4; final inbound callback URLs → Phase 6                                 |

D5–D12 remain as in the Master Plan (several already approved there: D5 targets, D6 OCMOD, D9 preserve tables). D8/D11 stay open for later phases.

**Phase 0 STOP GATE:** **PASS** (developer approved D1–D4 on commit `9a63d70cb1f1cb9183925880c070950cf8fa5e3a`).
