# Master Implementation Plan — UniCredit financing for OpenCart 3.x

## Document status and boundaries

This is an analysis and implementation plan, not implementation. It is based on baseline commit `e7787b091e5258ac0f9d105b5131bf4e58dd9c11` and the local references available on 2026-09-01. No runtime facts are inferred from the test shop.

The only future write target is `uni-oc3`. All `reference-*` projects remain read-only. The target is the OpenCart 3.x family, not a clone of one store or of OpenCart 3.0.3.9.

### Planning vocabulary

- **MUST**: frozen behaviour or safety property inherited from the completed UniCredit module or required by OpenCart.
- **SHOULD**: recommended design supported by the references, subject to a phase stop gate.
- **RUNTIME CHECK**: fact that cannot be established from the workspace and must be supplied from the test server.
- **Decision**: developer review is required before the dependent phase starts.

## 1. Executive summary

The deliverable will be a distributable OpenCart 3.x payment/financing extension named `mt_uni_credit`, with product, cart and native-checkout entry points; Control Panel (CP) synchronization and order submission; Process 1/SmartUCF and Process 2 handoffs; authenticated inbound callbacks; durable, store-scoped lifecycle state; customer/admin presentation; recovery and privacy controls.

The porting strategy has three layers:

1. Preserve the proven UniCredit business contracts from `reference-uni-oc4`: calculations, scheme eligibility, CP payloads, status vocabulary, idempotency, security, Process 1/2 semantics, and degraded outcomes.
2. Reimplement OpenCart integration in native OC3 terms: non-namespaced controllers/models, `extension/payment/...` routes, Registry/Loader, OC3 settings, `addOrder()`/`addOrderHistory()`, and small deterministic `install.xml` operations for injections into existing flows/templates.
3. Reuse only proven OC3 compatibility techniques from `reference-jet-oc3`, especially packaging, route/file conventions and the default-theme asset URL plus inline product asset-loading pattern that survives Journal. Do not inherit JET's duplicated business logic, client-authoritative financial fields, bundled mailer, fragile monolithic OCMOD, direct setting writes, or destructive cart behaviour.

Internally, the module should remain small enough for OC3: thin controllers/models around a compact `system/library/mt_uni_credit/` domain/integration layer. Durable repositories and explicit state transitions are justified by retry/security requirements; a framework, container, ORM, or mechanical copy of the OC4 class graph is not.

## 2. Reference hierarchy

| Priority | Reference             | Authority                                                                                                                                                          | Must not be used as                                   |
| -------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------- |
| 1        | `reference-uni-oc4`   | UniCredit behaviour, calculator and scheme contracts, CP/SmartUCF contracts, lifecycle, statuses, security, recovery, privacy, notifications and functional parity | OC3 file layout/API template                          |
| 2        | `reference-oc3-core`  | Native OC3 lifecycle, controllers/models, payment discovery, checkout order construction, settings, events, DB abstraction, Twig and extension conventions         | Exact-patch-only specification                        |
| 3        | `reference-jet-oc3`   | Proven OC3 packaging, payment/admin structure, OCMOD and frontend/Journal asset-loading compatibility patterns                                                     | UniCredit business or security specification          |
| 4        | `reference-oc3-store` | Optional later runtime comparison                                                                                                                                  | Specification or source of store-specific assumptions |

Conflict rule: UniCredit behaviour comes from OC4; platform mechanics come from OC3 core. JET may resolve an OC3 integration question but cannot override UniCredit contracts or security. The sanitized store never overrides any of these.

## 3. Compatibility targets

### 3.1 Platform and PHP

- Describe the product generally as targeting the practical OpenCart 3.x family. The formal primary release targets and required test matrix are OpenCart **3.0.3.6, 3.0.3.8 and 3.0.3.9**; 3.0.3.9 remains the primary local core reference.
- Phase 0 must derive and test an explicit PHP floor from the practical compatibility needs of those three OC3 releases and the required security/crypto functions. Do not freeze PHP 7.1 merely because it is theoretically possible. PHP 7.3.33 is one remote runtime reference, not the compatibility definition; preserve the widest safe PHP range practical for the approved OC3 matrix, including newer PHP only where OC3 itself and installed modifications permit it.
- Until the floor is approved, implementation must avoid typed properties, union/intersection types, `mixed`, constructor promotion, attributes, enums, `match`, arrow functions, nullsafe access, `str_contains`, `str_starts_with`, `Throwable`-only assumptions and other PHP 7.4/8-only syntax or functions. Prefer OC3-style arrays and untyped signatures where portability is improved.
- Required extensions/features: cURL with TLS, OpenSSL, JSON, hash/HMAC, random bytes, and a writable protected location for certificate material if Process 1 certificate mode is enabled. Every item is a RUNTIME CHECK.

### 3.2 Database

- MySQL and MariaDB via `$this->db`, `DB_PREFIX`, escaped values and integer casts.
- Portable schema and queries; no current-store SQL modes, vendor-only JSON operators, stored procedures or engine-specific locking assumptions without an explicit compatibility test.
- InnoDB is recommended for unique-key concurrency semantics. Charset/collation must be selected after checking the oldest target DB; do not assume `utf8mb4_unicode_ci` is universally available without installation fallback/diagnostic.

### 3.3 Themes and checkout

- Default OC3 theme is the baseline.
- Generic themes: semantic module-owned containers/classes, minimal selectors, no dependency on Bootstrap modal internals, defensive re-initialization after AJAX renders.
- Journal/Journal 3: explicitly support the proven JET asset-loading mechanism without requiring Journal.
- Standard OC3 checkout is the behavioural baseline. Third-party one-page checkouts are compatibility targets only when they preserve payment model/controller contracts and `session.order_id`; their DOM and sequencing must be runtime-tested, not assumed.

### 3.4 Distribution

Expected package root:

```text
module.ocmod.zip
├── install.xml
└── upload/
    ├── admin/
    ├── catalog/
    └── system/
```

No Composer runtime install should be required on the shop. If dependencies are unavoidable, package audited PHP-compatible source; do not bundle a second mail stack when OC3 Mail can satisfy the contract.

## 4. Functional inventory and parity matrix

Status meanings: **direct port concept** preserves a pure contract; **adapt** preserves behaviour through OC3 APIs; **reimplement** needs an OC3-native implementation; **OC3-specific** is compatibility work; **not applicable** is a platform-specific OC4 issue; **requires decision** blocks a later phase.

| Functional area                              | Frozen OC4 behaviour                                                                                   | Planned OC3 equivalent                                                                              | Status              |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------- | ------------------- |
| Module/admin setup                           | Module/payment settings, health, store scope                                                           | OC3 payment admin controller/model/template; install/uninstall hooks; settings per store            | adapt               |
| Shop/CP configuration                        | Auth, refresh, validated cached shop snapshot and coefficients                                         | Compact CP client + encrypted settings + custom cache repository                                    | direct port concept |
| Calculator domain                            | Scheme filtering, coefficient resolution, first installment, monthly/total/GPR values, stable ordering | Pure PHP 7-compatible calculator objects/functions with golden fixtures                             | direct port concept |
| Currency gate/display                        | Only CP-supported BGN/EUR contract; dual-display rules                                                 | Server-authoritative OC3 currency/context adapter                                                   | adapt               |
| Product calculator                           | Eligibility, options/quantity-aware amount, modal, customer prefill, calculate/submit                  | OC3 product controller/model endpoints; module-owned view; minimal OCMOD placement                  | reimplement         |
| Product Buy                                  | Financing selection handed to checkout and UniCredit payment preselected                               | Session-first signed preference fallback; OC3 checkout/save hooks; no fabricated order              | adapt               |
| Cart calculator                              | Live cart totals, intersection of line schemes, modal and submit, cart unchanged                       | OC3 total pipeline adapter and cart endpoints; AJAX-safe widget                                     | reimplement         |
| Homepage advertising                         | CP-controlled eligibility/presentation                                                                 | Optional module/layout output or small OCMOD placement where required                               | adapt               |
| Payment eligibility                          | module/payment enabled, currency, amount and shared scheme intersection                                | `ModelExtensionPaymentMtUniCredit::getMethod($address, $total)`                                     | adapt               |
| Checkout payment UI                          | Scheme selection within native payment confirmation                                                    | `ControllerExtensionPaymentMtUniCredit::index/issueSubmission/confirm` and Twig                     | reimplement         |
| Authoritative checkout amount                | Confirm against persisted order total, not browser values                                              | Load `session.order_id` through OC3 checkout/order model; parity check                              | adapt               |
| Customer/address sourcing                    | Native order/session authoritative; checkout phone may be empty                                        | OC3 order/session adapter; ignore posted identity fields except explicit consent/Process 2 inputs   | adapt               |
| Local order creation                         | Product/Cart create visible OC order once; Checkout reuses native order                                | OC3 order draft builder and `addOrder`; checkout never creates a second order                       | adapt               |
| Attempt/correlation                          | Durable entry-point attempt, submission token, operation identity, order correlation                   | DB-prefix repositories and explicit state transition service                                        | direct port concept |
| Duplicate/crash recovery                     | Operation lock, replay stable result, recover order/CP creation                                        | Unique-key claim and frozen snapshots; idempotent controller responses                              | direct port concept |
| CP order lifecycle                           | Frozen payload, authenticated POST `/api/v1/orders`, idempotent retry, 401 refresh once                | OC3-compatible cURL transport/token repository                                                      | direct port concept |
| CP auth                                      | login/refresh/logout and bearer cache lifecycle                                                        | Store-scoped credential/token service; encrypted secret at rest                                     | direct port concept |
| Process 1                                    | CP created, optional certificate sync, SmartUCF direct mTLS session, trusted redirect                  | PHP/cURL/OpenSSL adapter with destination allowlist, atomic state claim and consumer temp files     | adapt               |
| Process 2                                    | Validate EGN/phone2, encrypted local storage, status update, leasing mail, no SmartUCF                 | OC3 Mail adapter; same audience separation and idempotent mail marker                               | adapt               |
| Outbound statuses                            | Exact IDs/labels and correct timing                                                                    | Constants plus CP PATCH/status repository; never claim sent before actual handoff                   | direct port concept |
| Inbound shop cache                           | Signed POST replaces validated store/unicid snapshot                                                   | Dedicated catalog API controller route returning JSON only                                          | adapt               |
| Inbound bank status                          | Signed, store/order-owned, vocabulary-validated idempotent upsert; no OC status change                 | Dedicated OC3 catalog API endpoint/repository                                                       | direct port concept |
| Diagnostic retrieval                         | Signed, ownership-bound, redacted, retained for 3 months                                               | Dedicated endpoint and bounded cleanup                                                              | direct port concept |
| Customer result                              | Session-bound Thank You leasing/status; no GET order trust                                             | OCMOD delegates session capture/rendering to a module-owned presenter                               | adapt               |
| Emails                                       | Customer/admin leasing presentation with audience filtering; Process 2 customer copy excludes EGN      | OC3 Mail plus small OCMOD presentation injection and snapshot                                       | adapt               |
| Admin orders                                 | List bank status and detail leasing data, exact store/order scope                                      | Deterministic OCMOD columns/sections delegated to an admin presenter                                | adapt               |
| Data retention                               | Process 2 ciphertext 180d, presentation 183d, diagnostics 3 months; bounded cleanup                    | Repository cleanup opportunistically plus documented cron/manual option                             | direct port concept |
| Multistore                                   | Exact `(store_id, order_id)` and store-scoped credentials/cache                                        | Read `config_store_id`; no fallback from nonzero store to store 0                                   | direct port concept |
| OC4.1 void-order remediation                 | OC4.1-specific `editOrder()` void hazard                                                               | Not copied; characterize OC3 stale `session.order_id` behaviour and implement only proven OC3 guard | not applicable      |
| OC4 extension namespaces/event action syntax | OC4 extension loader conventions                                                                       | Classic OC3 class names/routes plus OCMOD where existing flow/template injection is required        | reimplement         |
| Journal asset transport                      | Not authoritative in OC4                                                                               | Proven JET inline local asset tags for product widget                                               | OC3-specific        |
| Third-party checkout adapters                | Not generically solvable                                                                               | Document compatibility contract; optional adapters only after evidence                              | requires decision   |

### Functional parity rule

Nothing in the matrix may be silently dropped. If an OC4 feature proves impossible or inappropriate on OC3, its phase must record evidence, user impact and explicit approval in the decision log.

## 5. Proposed OC3 source/package structure

This is an expected structure, not files to create in the planning phase:

```text
uni-oc3/
├── install.xml
├── upload/
│   ├── admin/
│   │   ├── controller/extension/payment/mt_uni_credit.php
│   │   ├── language/bg-bg/extension/payment/mt_uni_credit.php
│   │   ├── language/en-gb/extension/payment/mt_uni_credit.php
│   │   ├── model/extension/payment/mt_uni_credit.php
│   │   └── view/template/extension/payment/mt_uni_credit.twig
│   ├── catalog/
│   │   ├── controller/extension/payment/mt_uni_credit.php
│   │   ├── controller/extension/module/mt_uni_credit.php
│   │   ├── controller/extension/mt_uni_credit/api.php
│   │   ├── language/bg-bg/extension/payment/mt_uni_credit.php
│   │   ├── language/en-gb/extension/payment/mt_uni_credit.php
│   │   ├── model/extension/payment/mt_uni_credit.php
│   │   ├── model/extension/mt_uni_credit/mt_uni_credit.php
│   │   └── view/theme/default/
│   │       ├── image/mt_uni_credit/*
│   │       └── template/extension/mt_uni_credit/
│   │           ├── payment.twig
│   │           ├── product_calculator.twig
│   │           ├── cart_calculator.twig
│   │           ├── financing_modal.twig
│   │           ├── homepage_advertising.twig
│   │           ├── result.twig
│   │           ├── mt_uni_credit.css
│   │           └── mt_uni_credit.js
│   └── system/library/mt_uni_credit/
│       ├── bootstrap.php
│       ├── constants.php
│       ├── calculator.php
│       ├── scheme_resolver.php
│       ├── submission_service.php
│       ├── order_service.php
│       ├── lifecycle_service.php
│       ├── control_panel_client.php
│       ├── smart_ucf_client.php
│       ├── inbound_authenticator.php
│       ├── cipher.php
│       ├── repositories.php
│       ├── presenter.php
│       └── compatibility.php
├── tests/
│   ├── fixtures/
│   ├── unit/
│   ├── contract/
│   ├── support/
│   └── bootstrap.php
├── scripts/
│   ├── lint.ps1
│   └── package.ps1
└── docs/
    ├── MASTER_IMPLEMENTATION_PLAN.md
    ├── RUNTIME_VERIFICATION.md
    ├── DEPLOYMENT.md
    ├── RECOVERY.md
    └── SECURITY_OPERATIONS.md
```

The exact number of library files should follow cohesion, not mirror OC4's many classes. API methods may initially share one OC3 controller with separate public methods (`shopCache`, `orderBankStatus`, `smartUcfDebugLog`) if route compatibility and access controls remain explicit. If the tested OC3 minor versions resolve nested routes consistently, separate controllers may be preferable.

## 6. Architecture

### 6.1 Layers and responsibilities

| Layer         | Responsibilities                                                                           | Prohibited responsibility                         |
| ------------- | ------------------------------------------------------------------------------------------ | ------------------------------------------------- |
| OC3 adapters  | Routes, Registry/Loader, session/cart/order/settings, response headers, language, Twig     | Financial calculations or trusting browser totals |
| Domain        | Scheme identity/intersection, calculator, status vocabulary, validation, state transitions | Direct DB/HTTP/session access                     |
| Persistence   | Settings, cache, attempts, correlation, locks, nonces, bank status, diagnostics            | Presentation or remote calls                      |
| Integrations  | CP bearer lifecycle, payload/response contract, certificate sync, SmartUCF, mail           | Creating OC orders directly                       |
| Presentation  | Product/cart/payment/admin/Thank You view models and audience filtering                    | Lifecycle mutation                                |
| Compatibility | OC3 version differences, theme asset transport and OCMOD placement                         | Business-rule forks by theme/store                |

Dependencies point inward: controllers call services; services use small injected/callable adapters or repositories; pure domain code has no OpenCart globals. A lightweight factory/bootstrap may obtain Registry services. Do not build a general-purpose dependency injection container.

### 6.2 Server authority

At every submission, reconstruct price, products/options/quantity, totals, customer ownership, currency, store and available schemes from live OC3/order/CP-cache data. Browser fields carry only an issued submission token, selected scheme identity, consent, and Process 2 inputs. Recalculate and compare the selection hash before order/remote side effects.

### 6.3 State machine

Recommended attempt progression:

```text
issued
  -> validated
  -> order_creating -> order_created/local_order_prepared
  -> cp_submitting
     -> cp_created
     -> cp_failed_retryable | cp_outcome_unknown
  -> Process 1: smartucf_submitting
     -> smartucf_created/bank_sent_process1
     -> smartucf_outcome_unknown | smartucf_terminal_failed
  -> Process 2: process2_prepared
     -> bank_sent_process2 -> mail_pending/mail_sent
```

Transitions must be conditional and idempotent. Ambiguous post-send outcomes must not be downgraded to definite failures and must tell the customer not to resubmit. Stable replay returns the stored result/redirect without a second remote call.

### 6.4 Entry-point differences

- **Product**: builds an order draft from the selected product, owned options and quantity; creates one OC order; does not pretend to be native checkout.
- **Cart**: builds from the live cart and native totals pipeline; creates one OC order; preserves the cart after financing submit, matching OC4.
- **Checkout**: uses the native order already stored in `session.order_id`; it must never call `addOrder()` from the payment controller. Confirm validates order/cart/amount/ownership parity before CP submission.

All three converge at `local_order_prepared`, use the same frozen CP payload builder, and then branch only on Process 1/2.

### 6.5 Settings and health

Admin configuration should separate:

- payment visibility: enabled, sort order, geo-zone if retained, minimum/maximum policy, initial OC order status;
- CP identity: UNICID/shop name/secret and endpoint environment;
- feature presentation: product/cart/home/checkout toggles and scheme defaults;
- Process mode and certificate option;
- operational toggles: debug (redacted), cache refresh/health, retention action;
- local-only secrets/passphrase/certificate paths following the established cross-platform UniCredit deployment convention where technically possible, never echoed back in full.

Save must require `modify` permission, validate values, invalidate auth/cache safely after credential changes, and show actionable deployment/OCMOD health without leaking secrets.

## 7. OCMOD strategy

### 7.1 Governing rule

OCMOD is the established and preferred OC3 modification mechanism for injections into existing OpenCart controllers, flows and templates. Use native OC3 extension mechanisms normally where no modification is needed: payment/module/API controllers and models, payment templates, DB/settings and internal libraries. Do not introduce the OC3 event system merely to avoid OCMOD, and do not maintain parallel event and OCMOD implementations unless a concrete characterized platform need justifies both.

Each OCMOD operation must have minimal scope, a stable search anchor, a unique module marker and an installation diagnostic when it does not apply. Use graceful handling such as `error="skip"` where an optional theme anchor may be absent; avoid `error="abort"` when one optional theme mismatch would otherwise break the entire modification refresh. Injected code must contain no business logic: it delegates to module-owned controllers/services/presenters.

### 7.2 Likely integration points

| Target original file/method                                                                               | Intended point                                                                                                | Why OCMOD may be required                                                                                      | Risk and mitigation                                                                                                               |
| --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `catalog/controller/product/product.php::index()`                                                         | After product data and price/options are available, delegate to module presenter and expose minimal view data | Product-specific integration is an existing core flow injection; JET proves this OCMOD location                | Core patch/minor differences and other OCMODs. Search stable anchors, keep injected block tiny, test compiled modification        |
| `catalog/view/theme/*/template/product/product.twig`                                                      | Near quantity/minimum/add-to-cart region, add module-owned widget output and inline asset tags                | Product widget needs deterministic visible placement; generic theme templates lack a universal injection point | Theme markup differs; wildcard misses Journal-generated views. Feature detection, alternate documented placement, no hard failure |
| `catalog/controller/checkout/cart.php::index()`                                                           | Register presenter/assets and module view output after totals/context exists                                  | Cart route needs live total/scheme data and AJAX re-render support                                             | Total extensions and controller modifications; delegate, do not duplicate totals logic                                            |
| `catalog/view/theme/*/template/checkout/cart.twig`                                                        | After cart table/form or totals block, render module-owned container                                          | Stable visible cart placement requires a deterministic template injection                                      | Highly customized carts; use narrow optional operation and JS idempotent mount                                                    |
| `catalog/controller/checkout/success.php::index()` or `catalog/view/theme/*/template/common/success.twig` | Capture order identity before session cleanup and add financing result block                                  | Existing success flow/template must be extended while preserving session-only identity                         | Security-sensitive; never read order id from GET; delegate capture/presentation and characterize all three target versions        |
| `admin/controller/sale/order.php` / `admin/view/template/sale/order_list.twig` and `order_info.twig`      | Add bank status list column/detail presentation                                                               | Existing admin list/detail output requires deterministic enrichment                                            | Admin theme/mod conflicts; optional enrichment, exact store scope, no order workflow break                                        |
| `catalog/view/theme/*/template/mail/order_add.twig` / alert equivalent                                    | Append audience-filtered financing snapshot                                                                   | Existing mail presentation requires a small deterministic injection                                            | Mail template diversity; plain/HTML testing, never include EGN in customer mail                                                   |

### 7.3 Areas that should not require OCMOD

- Payment discovery and checkout payment body: standard `extension/payment/mt_uni_credit` model/controller/template.
- CP/inbound API routes: normal catalog controllers.
- DB schema and settings: install/admin model.
- Cart mutation/session hygiene only when it can be owned entirely by the module's normal routes; any required injection into core cart/checkout mutation paths belongs in small OCMOD operations.
- Calculation, repositories, HTTP clients and security: module libraries.

### 7.4 OCMOD phase gate

Before writing `install.xml`, build an integration map against OpenCart 3.0.3.6, 3.0.3.8 and 3.0.3.9. For every operation record: target, anchor, expected match count, behaviour when zero/multiple, overlap with JET/other common modifications, generated file to inspect, and uninstall/refresh procedure.

## 8. Frontend integration strategy

### 8.1 Shared frontend contract

- One namespace such as `.mtuc-*`, one root with `data-mtuc-context`, no global IDs reused across product/cart/checkout.
- JS initialization is idempotent and may be called after AJAX fragment replacement.
- Use delegated events scoped to the root, abort/stale-response protection, disabled submit state, accessible focus/close/error behaviour and plain DOM APIs compatible with the supported browsers; use the shop's bundled jQuery only where OC3 checkout requires it.
- Server-rendered URLs and localized messages; no hard-coded domain, language or SEO route.
- Cache-bust with guarded `filemtime`; missing files must not emit warnings.
- CSS must not reset generic elements and should not assume the default Bootstrap grid.

### 8.2 Product

Render only when module/shop cache/currency/product/stock/amount/scheme gates pass. Product option and quantity changes trigger a debounced server calculation using current authoritative product option IDs. The modal has calculation and, where enabled, customer/consent or Product Buy actions. Submission uses a freshly issued one-time token tied to actor, product selection and scheme.

### 8.3 Cart

Compute the native grand total through enabled OC3 total extensions, not merely `cart->getTotal()`. Available schemes are the deterministic intersection across eligible cart lines and CP coefficients. The widget survives OC3 cart AJAX updates, invalidates stale tokens after mutations and never clears the cart on financing submission.

### 8.4 Checkout

`getMethod()` is the single eligibility gate exposed to checkout. The payment view fetches/embeds schemes for the current native order, then `confirm` revalidates `session.order_id`, payment code, store/customer ownership, order total, cart fingerprint and chosen scheme. Product Buy preference is convenience only and must be cleared when payment changes or checkout succeeds.

Third-party checkout contract: it must invoke standard payment model discovery, save the payment code, create/set `session.order_id`, render the payment controller, and honor the confirm JSON redirect/error. Deviations are documented per checkout; they are not patched speculatively.

### 8.5 Journal compatibility pattern

The proven JET mechanism is:

1. Keep distributable assets in `catalog/view/theme/default/template/extension/<module>/` so OpenCart theme fallback makes one physical copy usable by all themes.
2. Build public URLs from that fixed default-theme path and a guarded physical path under `DIR_APPLICATION`; append `filemtime` for cache busting.
3. On the product injection, render `<link>` and `<script>` immediately beside the widget rather than relying solely on `$this->document->addStyle/addScript()`. Journal can rebuild/cache the header before an OCMOD-injected product widget is evaluated, so local tags keep the asset coupled to the fragment.

Reuse this as an `AssetUrlProvider`/view partial with once-per-document guards. Standard pages may still use `Document` registration, but the inline fallback must be harmless and must not detect Journal by brittle class/file probes. Verify Journal 3 product, cart AJAX and checkout separately. This is compatibility support, not a Journal dependency.

## 9. Control Panel integration

### 9.1 Outbound authentication and configuration

- Base API prefix and allowed environments are constants/configuration, not arbitrary admin URLs. Production/test endpoints require an explicit admin choice and HTTPS.
- Login body: `unicid`, `name`, `secret`; store bearer token with expiry metadata; refresh/logout per frozen CP contract.
- On 401, perform one synchronized refresh/login and one replay only. Never loop.
- Encrypt the CP secret at rest with an installation/deployment secret. If no secure key exists, fail closed and display a health error; do not use a predictable fallback.

### 9.2 Shop snapshot

`GET /api/v1/shop` returns shop configuration plus coefficients. Validate schema and business bounds before replacing the exact `(store_id, unicid)` cache. A signed inbound `shop_cache` push validates and replaces the same record without calling CP. Stale cache policy must be explicit: calculations may display last-known data only within the approved grace policy; order submission must use a sufficiently fresh validated snapshot or fail safely.

### 9.3 CP order

After a local order exists, build and freeze one CP payload. Preserve known limits: local `order_id` string max 13, name 65, phone 45 with accepted characters, email 128, addresses 256, BGN/EUR currency, semantic product fields, version format, amounts rounded as frozen. Omit create-time `status/status_id`; CP defaults to `cp_sent`.

POST `/api/v1/orders` is idempotent by CP `(shop_id, order_id)` and semantic hash: equivalent retry returns 200, conflicting payload returns 409. Store the CP id and state only after a validated response. Timeout/transport ambiguity becomes `cp_outcome_unknown` and replays the exact frozen payload; never fabricate a second local order reference.

### 9.4 Status patch

PATCH `/api/v1/orders/status` only after the corresponding bank-side action. Preserve exact IDs such as `bank_sent_process1`, `bank_sent_process2`, `bank_send_failed`, `bank_send_failed_cp`, `bank_send_failed_smartucf`. Local bank status is separate from native OC order status.

### 9.5 Inbound endpoints

Use explicit non-SEO routes, final form determined in Phase 6, conceptually:

```text
index.php?route=extension/mt_uni_credit/api/shopCache
index.php?route=extension/mt_uni_credit/api/orderBankStatus
index.php?route=extension/mt_uni_credit/api/smartUcfDebugLog
```

All are POST-only JSON. Expected errors: 400 bad JSON/validation/status, 401 auth/replay/time, 403 module disabled, 404 owned order/log missing, 405 method, 422 invalid shop snapshot, 500 redacted unexpected failure. Phase 6 must coordinate the final OC3 paths with CP configuration; do not assume OC4 routes are accepted unchanged.

## 10. End-to-end order lifecycle

### 10.1 Happy path

```text
Customer opens Product/Cart/Checkout
  -> module resolves validated store-scoped CP snapshot and eligible schemes
  -> server calculates offer and issues actor/selection-bound submission token
  -> customer selects financing and consents
  -> server revalidates live product/cart/order and scheme
  -> Product/Cart: atomically materialize one OC3 order
     Checkout: reuse native session.order_id only
  -> persist correlation and visible awaiting-financing OC order history
  -> freeze CP payload and POST /orders idempotently
  -> persist CP id and cp_created
  -> Process 1:
       sync/validate certificate if enabled
       -> atomic SmartUCF claim -> session start -> persist trusted redirect
       -> PATCH/local bank_sent_process1 -> redirect customer to bank
     Process 2:
       validate EGN/phone2 -> encrypt locally
       -> PATCH/local bank_sent_process2 -> send audience-safe leasing mail
       -> native Thank You page
  -> signed CP callbacks update exact store/order bank status or cache
  -> Thank You, mail and admin show audience-filtered snapshot/status
```

### 10.2 Local creation and duplicate protection

The operation key is deterministic per store/entry point and selection; an atomic unique lock serializes it. Correlation is written immediately after `addOrder()`. A retry with the same attempt recovers the bound order and returns stable output. Product/Cart apply the configured UniCredit awaiting status through `addOrderHistory`; if missing/invalid, do not invent an order status and surface health remediation. Checkout uses its configured confirm/history policy without creating a second order.

### 10.3 CP degraded paths

- Auth/config/snapshot validation failure before send: retryable, no CP/bank success claim.
- Network timeout after send: `cp_outcome_unknown`; make the same idempotent POST on operator/customer retry.
- CP validation rejection: terminal for that frozen attempt; show a safe message and record error class, not payload/PII.
- Checkout CP failure while the native order is status 0: apply a neutral configured visible status only if approved and tested for OC3, so the order is recoverable in admin.

### 10.4 Process 1 degraded paths

- Certificate metadata/bundle/local validation failure before SmartUCF send is retryable and does not publish `bank_send_failed_smartucf`.
- A valid local certificate pair may be used during transient CP metadata failure only if the frozen policy explicitly allows it; explicit certificate unavailability fails closed.
- Timeout, stale `submitting`, duplicate evidence or invalid post-send response is `outcome_unknown`; do not call again automatically and tell the customer not to resubmit.
- Only a definitive SmartUCF rejection records `bank_send_failed_smartucf` locally and in CP.

### 10.5 Process 2 degraded paths

Invalid EGN/phone2 or unavailable encryption key fails before bank-sent status. Once `bank_sent_process2` is recorded, retry must not repeat the bank handoff. Mail has its own idempotent marker; mail failure remains recoverable and must not undo bank state. CP never receives EGN/phone2.

## 11. Security model

### 11.1 Inbound authenticity and replay

- Headers: `X-UniPayment-Timestamp`, `X-UniPayment-Nonce`, `X-UniPayment-Signature`.
- Canonical bytes: `timestamp + "\n" + nonce + "\n" + exact_raw_body`.
- HMAC-SHA256 lowercase hex, compared with `hash_equals`.
- Timestamp is decimal and within ±300 seconds.
- Nonce is exactly 64 lowercase hex; persist only `sha256(nonce)`, unique by `(store_id, unicid, nonce_hash)`, retain 900 seconds.
- Verify raw body before JSON decode. An invalid signature must not consume the nonce. Bind body UNICID to the exact store-scoped credential.
- Claim nonce atomically only after signature validation; prune expired rows in bounded batches.

### 11.2 Store/order/actor ownership

Every attempt, order correlation, bank status, cache and diagnostic query includes exact `store_id`. Store 0 is valid but never a fallback for another store. Inbound bank/log actions require a correlated attempt or verified UniCredit payment order. Logged-customer result/replay additionally matches `customer_id`; guests use an unpredictable session/attempt binding.

### 11.3 Storefront request integrity

Issue random submission tokens with expiry, one durable attempt and hashes of actor + authoritative selection. For product/cart AJAX mutation, use a module CSRF token stored in session and same-origin POST. Revalidate everything on confirm. Use atomic DB uniqueness, not JavaScript disabling, as duplicate protection.

### 11.4 Admin and secrets

- OC3 `user_token` plus `hasPermission('modify', 'extension/payment/mt_uni_credit')` on every mutation/action.
- Never expose full secrets, bearer tokens, private keys or passphrases in templates, logs or health JSON.
- Where technically possible, use the same established UniCredit secret, certificate, private-key and passphrase deployment convention used across the completed Woo / PS8 / PS9 / OC4 modules. Phase 0 must inventory that convention and translate paths/bootstrap mechanics into OC3 without inventing an OC3-only layout. Any difference must be justified by an OC3 or filesystem/runtime constraint and documented operationally.
- CP secret and Process 2 PII encrypted at rest using authenticated encryption available on the approved PHP floor. Prefer OpenSSL AES-256-GCM when runtime support is verified; otherwise decide on an audited encrypt-then-MAC construction. No silent plaintext fallback.
- SmartUCF certificate/private key staged atomically with strict permissions; validate dates, key match and exact PEM SHA-256; temporary consumer copies removed in `finally`-equivalent cleanup.
- SmartUCF destination is allowlisted (`online.ucfin.bg` / `onlinetest.ucfin.bg` and frozen paths), TLS peer/hostname verification always on, bounded timeout, redirects disabled or revalidated.

### 11.5 PII, logging and retention

- CP frozen payload is the one necessary customer snapshot for idempotent recovery; do not add another permanent customer table.
- EGN/phone2 exist only as encrypted Process 2 data; never in CP payload, customer email, customer Thank You or logs. EGN may appear only in the authorized admin leasing message if the frozen Process 2 contract requires it.
- Debug logs allow identifiers, state/error class and HTTP status, not raw payloads, email, phone, address, EGN, secrets/tokens/keys.
- Redact Process 2 ciphertext after 180 days, leasing presentation after 183 days, diagnostic journal after 3 months, in bounded batches. Keep operational attempt/order identifiers unless policy later requires deletion.

### 11.6 Failure handling

Public messages are stable, localized and non-sensitive. Server logs use correlation IDs and classified errors. JSON endpoints always set correct status/content type and never render theme error HTML. Error handling must not convert unknown remote outcomes into retryable fresh submissions.

## 12. Data and storage model

### 12.1 OpenCart settings

Use standard `setting` rows under `payment_mt_uni_credit` (and only a separate module code if OC3 discovery requires it), scoped by `store_id`. Candidate values: status, sort order, geo zone, awaiting order status, entry-point toggles, default presentation, CP UNICID/name/encrypted secret, environment, process and certificate flags, debug flag. Keep a single canonical setting key per concept and translate legacy aliases only through an explicit compatibility adapter.

### 12.2 Custom tables

Recommended minimum logical tables, all prefixed with `DB_PREFIX`:

| Table                                | Purpose and key constraints                                                                                                                   |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `mt_uni_credit_shop_cache`           | Validated snapshot, `(store_id, unicid)` unique, fetched/expiry timestamps                                                                    |
| `mt_uni_credit_api_nonce`            | Hashed inbound nonces, `(store_id, unicid, nonce_hash)` unique, expiry index                                                                  |
| `mt_uni_credit_operation_lock`       | Short-lived atomic operation claims, `(store_id, entry_point, operation_key_hash)` unique                                                     |
| `mt_uni_credit_financing_attempt`    | Token, actor/selection/cart hashes, lifecycle, order/CP ids, frozen CP payload, Process 1/2 fields, presentation, classified error/timestamps |
| `mt_uni_credit_order_correlation`    | Crash-safe attempt↔OC order mapping; attempt and `(store_id, order_id)` unique                                                                |
| `mt_uni_credit_order_bank_status`    | Latest external status, `(store_id, order_id)` unique; no mutation of native OC order state                                                   |
| `mt_uni_credit_diagnostic_debug_log` | Redacted event summaries indexed by store/order and creation time                                                                             |

The OC4 schema is the behavioural starting point, not SQL to copy. Phase 2 must verify column lengths against frozen contracts, MySQL/MariaDB versions, index byte limits and charset. Foreign keys are optional and probably should be avoided for OC3 installer portability; repository ownership checks remain mandatory.

### 12.3 Tokens, caches, locks and snapshots

- Bearer tokens may use a dedicated encrypted setting/cache record with explicit expiry; they must not be included in shop snapshot JSON.
- Submission tokens are random and unique; actor/selection hashes prevent transfer/replay.
- Lock expiry only recovers abandoned pre-side-effect work; it never authorizes repeating ambiguous external sends.
- `cp_payload` is immutable after first freeze. A mismatch on retry is a conflict, not an update.
- Presentation snapshot is audience-neutral source data; presenters filter fields per customer/admin channel.

### 12.4 Install/uninstall and upgrades

Install creates/ensures schema, permissions, OCMOD metadata and defaults idempotently. Uninstall removes module-managed settings/OCMOD registration only according to approved data policy and should preserve financing/audit tables by default to avoid destroying order records. A separate explicit purge tool may be designed later. Schema upgrades must be versioned/idempotent before production release; never depend on uninstall/reinstall after customer data exists.

## 13. Compatibility risks and mitigations

| Risk                                      | Impact                                              | Mitigation/verification                                                                            |
| ----------------------------------------- | --------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| OC3 minor route/core-template differences | OCMOD anchor or native route differs                | Characterize 3.0.3.6/3.0.3.8/3.0.3.9; stable anchors and a narrow compatibility adapter            |
| Old PHP syntax/runtime                    | Parse-time fatal or missing crypto functions        | Explicit floor; matrix lint/runtime; polyfill simple string helpers; no modern syntax              |
| PHP 8 warnings against old OC3            | Runtime noise/failure outside module                | Avoid amplifying core issues; test supported patched OC3 runtime separately                        |
| MySQL/MariaDB charset/index variance      | Install failure                                     | Portable DDL, preflight versions/collations, install diagnostics and rollback-safe ensure          |
| DB concurrency/SQL modes                  | Duplicate attempts or invalid dates                 | Unique keys, affected-row checks, real DATETIME values, strict-mode tests                          |
| Default-theme markup drift                | Widget not placed                                   | Narrow anchors, match-count tests, graceful missing-widget diagnostic                              |
| Journal header/cache behaviour            | CSS/JS absent                                       | Inline fragment-coupled default-theme asset URL pattern, once guard, Journal runtime matrix        |
| Journal/cart AJAX                         | Duplicate handlers or stale UI                      | Idempotent initializer, delegated events, mutation/fingerprint invalidation                        |
| Custom themes                             | Layout break or missing anchor                      | Module-owned markup/CSS, optional layout/manual insertion contract                                 |
| Third-party checkout sequence             | Missing order id, confirm not called, duplicate     | Publish compatibility contract; test named checkout; isolated adapter only with evidence           |
| Total extensions/shipping                 | Amount differs from order/CP                        | Use native total pipeline and persisted checkout order total; parity tests                         |
| Product options/custom types              | Wrong price/order line                              | Server-side ownership and price reconstruction; fixtures for each OC option type                   |
| OCMOD collisions/cache                    | Search miss or stale compiled PHP/Twig              | Small operations, unique markers, modification refresh/inspection checklist, recovery instructions |
| Existing JET module                       | CSS/JS/route collisions or competing credit widgets | Unique `mtuc` namespace; coexistence test; never reuse JET settings/table names                    |
| Multistore domains/config                 | Cross-store data or wrong callback URL              | Exact store scope, canonical store URL validation, callback per store                              |
| CP contract/environment drift             | Rejected orders/security issue                      | Golden request/response fixtures and explicit endpoint/environment constants; coordinate paths     |
| Remote timeout/duplicate                  | Double CP/bank send                                 | Frozen payload, unique attempt, atomic states, unknown-outcome state, stable replay                |
| Clock skew                                | Valid callbacks rejected                            | RUNTIME CHECK NTP/timezone; UTC timestamps; health warning, never widen silently                   |
| Certificate filesystem permissions        | Process 1 failure/key exposure                      | Preflight, protected directory, atomic staging, temp lease cleanup, operator diagnostics           |
| Mail engine variations                    | Process 2 notification loss                         | OC3 Mail adapter contract tests and manual SMTP/mail tests; separate idempotent mail state         |
| Encryption key loss/rotation              | PII unreadable or insecure fallback                 | Key-source/rotation runbook before Process 2; fail closed; versioned ciphertext envelope           |
| Retention without traffic                 | Rows not pruned promptly                            | Opportunistic bounded cleanup plus documented cron/admin maintenance trigger                       |

## 14. Testing strategy

### 14.1 Automated local tests

- **Pure domain**: calculator golden fixtures copied as data/contracts (not modern code), coefficient/filter edge cases, scheme ordering/intersection, rounding, first installment, BGN/EUR, invalid inputs.
- **Lifecycle**: allowed/forbidden transitions, same-token replay, lock conflict, crash after order/correlation/CP response, ambiguous remote result, Process 2 mail retry.
- **Security contracts**: OC4 HMAC vector, raw-body preservation, timestamp edges, invalid signature non-consumption, nonce concurrency, actor/store/order binding, redaction and retention.
- **CP/SmartUCF**: fake transport verifies methods, exact endpoints/headers/payload bounds, 401 once, 409 conflict, timeout classification, certificate destination/validation and trusted redirect.
- **Persistence integration**: run against representative MySQL and MariaDB containers when available; install twice, unique constraints, store 0/nonzero isolation, cleanup batches and strict SQL mode.
- **OC3 stubs/characterization**: minimal Registry/Loader/Config/Session/Cart/DB/Order stubs; verify controller return/JSON shapes, payment `getMethod` and classic class discovery.
- **OCMOD contracts**: XML parse, each search matches exactly once in OpenCart 3.0.3.6, 3.0.3.8 and 3.0.3.9 where required, injected code syntax, no reference-specific absolute path, generated modification smoke inspection.
- **Frontend**: lint JS/CSS; DOM tests for calculate, modal focus/close, double click, stale AJAX response, cart rerender and once-only assets.
- **Static gates**: PHP syntax at the approved minimum and representative maximum; forbidden modern syntax scan; no secrets/PII fixtures; distribution file allowlist.

Tests must never contact live CP/SmartUCF. Fixed fake responses and known contract fixtures are required.

### 14.2 Manual runtime matrix

At minimum:

- default theme: guest/logged-in, product with/without options, cart mutation, shipping/no-shipping, native checkout, success;
- Journal 3: same product/cart/checkout asset and AJAX paths;
- BGN and EUR; below/at/above scheme bounds; no scheme intersection; stale cache;
- Process 1 without/with certificate, success, definitive reject, pre-send certificate failure, timeout/unknown;
- Process 2 validation, encrypted persistence, customer/admin email contents, mail retry;
- CP callbacks: valid, altered body, old/future timestamp, replay, wrong UNICID/store/order/status;
- duplicate clicks and browser refresh/back; forced interruption at each durable boundary;
- multistore store 0 and nonzero with colliding numeric order IDs;
- install twice, upgrade, disable, uninstall module/OCMOD cleanup, modification refresh and rollback;
- one representative third-party checkout only after it is named/available.

### 14.3 Regression evidence per phase

Every phase produces: changed-file list, automated output, remote checklist/results, unresolved deviations, and a commit only after STOP GATE approval. Failures are added as regression tests before fixes where feasible.

## 15. Deployment and remote testing workflow

1. Work only in `uni-oc3`; verify `git diff --name-only` against the phase allowlist.
2. Run local lint/contracts/unit/packaging tests.
3. Build a deterministic `module.ocmod.zip` from `install.xml` + `upload/`; record checksum and module version.
4. Back up remote DB/files and record installed module/OCMOD state manually.
5. Upload through Extension Installer or documented manual staging; refresh Modifications and theme cache.
6. Inspect installer errors, `oc_modification`, generated modification files and module health.
7. Run the phase manual matrix on the test store; collect sanitized logs/queries only.
8. On failure, disable the extension/OCMOD and restore the prior package/config; do not delete order/lifecycle data.
9. Review evidence, commit the phase, then begin the next phase.

### Runtime information to request before relevant gates

Provide commands appropriate to the server shell/control panel; exact paths must be supplied by the developer:

```text
php -v
php -m
php -i | grep -E 'OpenSSL|cURL|disable_functions|upload_max_filesize|post_max_size'
mysql --version
SELECT VERSION(), @@sql_mode, @@character_set_server, @@collation_server;
SELECT * FROM <DB_PREFIX>extension WHERE type IN ('payment','module');
SELECT modification_id, name, code, status FROM <DB_PREFIX>modification;
SELECT store_id, name, url, ssl FROM <DB_PREFIX>store;
```

Also request: exact OC version constant, active theme and Journal version, checkout extension/version, PHP handler user/group, writable storage paths, cron availability, server timezone/NTP, outbound DNS/TLS access to CP/SmartUCF, CA bundle, mail engine, and sanitized OpenCart/PHP/web-server logs. Confirm the established UniCredit cross-platform secret/certificate directory convention and whether the OC3 filesystem can use it unchanged. Never request secret values in chat; request presence/permissions/fingerprints.

## 16. Phased implementation plan

Phases are intentionally small and sequential. Cursor should receive only one approved phase at a time.

### Phase 0 — Freeze contracts and compatibility floor

**Objective:** turn OC4 behaviour and OC3 platform facts into executable contracts and close only decisions that block scaffolding.

**Expected files/components:** `docs/CONTRACTS.md`, `docs/RUNTIME_VERIFICATION.md`, test fixtures/support; no storefront implementation.

**Tasks:**

- Extract calculator golden data, CP endpoints/payload limits/auth fixtures, HMAC vector, status vocabulary and Process 1/2 outcome rules from OC4.
- Characterize OC3 payment route/class/install/OCMOD conventions and checkout order creation on 3.0.3.6, 3.0.3.8 and 3.0.3.9.
- Derive and approve the PHP floor from that three-version matrix plus required crypto/security functions; do not preselect PHP 7.1. Confirm encryption/key-source approach, module version/identity and the established cross-platform UniCredit secret/certificate deployment convention.
- Define source/package allowlists and no-live-network test rule.

**Dependencies:** this plan and developer decisions D1–D4.

**Tests:** fixture integrity, references/checksums, compatibility syntax probe, scope guard.

**Runtime/manual verification:** obtain environment inventory listed in section 15; no deployment required.

**Acceptance criteria:** frozen contract document contains no invented runtime fact; all later phases can cite a contract ID.

**STOP GATE:** developer approves compatibility floor, identifiers, endpoints/environments and secret storage direction.

### Phase 1 — OC3 skeleton, installer and admin health

**Objective:** create a discoverable, installable but functionally disabled OC3 extension with **dual admin surfaces** (Module + Payment).

**Expected files/components:** package skeleton; **module** and **payment** admin controller/model/languages/templates; bootstrap/constants; shared install helper; install/uninstall/OCMOD shell; packaging script.

**Tasks:** implement classic OC3 names/routes for both surfaces; separate `module_mt_uni_credit_*` and `payment_mt_uni_credit_*` settings; independent permissions; idempotent install per surface; deterministic package; align Phase 1 Module admin with established UniCredit family field order/terminology (no Environment UI, no Health panel); module boolean fields as OC3 checkboxes; operational buttons as safe Phase 1 placeholders; payment listing logo max-width ~200px; fresh payment install defaults order status to **Processing** via install-time name resolution.

**Dependencies:** Phase 0.

**Tests:** OC3 discovery for Module and Payment; PHP-floor lint; install twice per surface; permission denial per route; package layout; uninstall preserves other surface settings and data tables; Module field parity (advertising, product button, spacing, operational buttons); no visible Environment/Health UI; payment logo sizing; Processing default not first arbitrary status.

**Runtime/manual verification:** install package; verify Module admin matches established UniCredit settings/order; operational buttons safe; Payment admin payment-only; Processing default on clean payment install; settings per store; modification records/generated files and logs.

**Acceptance criteria:** both admin surfaces install/configure without storefront output or warnings; Module admin follows UniCredit family parity without premature Phase 4/9 behaviour; Payment shows payment-only settings and reasonably sized listing logo; existing explicit payment order status preserved on reopen.

**STOP GATE:** approve skeleton and remote install evidence before adding schema/business code.

### Phase 2 — Portable persistence, secrets and security primitives

**Objective:** establish durable store-scoped state and fail-closed secret handling.

**Expected files/components:** schema installer; repositories for cache/nonces/locks/attempts/correlation/status/diagnostics; cipher/key provider; HMAC authenticator; tests.

**Tasks:** implement portable DDL via DB abstraction; atomic unique claims; allowed transitions; bounded pruning; encrypted settings/PII envelope; raw-body HMAC/replay protocol; exact store scoping.

**Dependencies:** Phase 1 and approved key source.

**Tests:** MySQL/MariaDB integration, HMAC vector, concurrent duplicate claims, store collision, retention, missing-key fail closed.

**Runtime/manual verification:** run install DDL twice; inspect engines/charset/indexes/permissions; verify no plaintext secrets.

**Acceptance criteria:** repositories meet concurrency/security contracts and no data lookup crosses stores.

**STOP GATE:** schema and crypto review; production data preservation/upgrade policy approved.

### Phase 3 — Shop configuration and calculator domain

**Objective:** deliver pure, parity-tested financing calculations backed by validated CP snapshots.

**Expected files/components:** scheme/coefficient/filter resolver; calculator; currency/amount presenters; shop cache validator/repository facade; golden fixtures.

**Tasks:** port concepts into PHP-floor-compatible code; deterministic intersection/order; validate cache snapshots; define fresh/stale/unavailable outcomes; ensure all displayed values derive from server inputs.

**Dependencies:** Phase 2.

**Tests:** OC4 golden parity, boundary amounts/months, malformed snapshots, BGN/EUR, no intersection and ordering.

**Runtime/manual verification:** use sanitized CP snapshot from test environment; compare visible calculations with approved OC4 cases.

**Acceptance criteria:** all golden cases match or an explicitly approved OC3/platform deviation is logged.

**STOP GATE:** business owner approves calculation/scheme parity.

### Phase 4 — Outbound Control Panel client and admin configuration

**Objective:** authenticate, refresh shop data and provide operational health without order submission.

**Expected files/components:** HTTP transport, bearer token/auth lifecycle, CP client, admin refresh/test actions, redacted diagnostics.

**Tasks:** HTTPS allowlist, timeouts, JSON/error classification, one 401 retry, token expiry, credential-change invalidation, certificate metadata health (not sync yet).

**Dependencies:** Phases 2–3.

**Tests:** fake transport contract, login/refresh/logout, retry limits, invalid JSON/status, redaction, endpoint policy.

**Runtime/manual verification:** admin-authenticated CP login/shop refresh against test environment; inspect sanitized logs and cache timestamps.

**Acceptance criteria:** shop snapshot refresh is reliable and no credential/token leaks.

**STOP GATE:** CP owner confirms contract and endpoint/environment configuration.

### Phase 5 — Payment method and standard checkout preparation

**Objective:** expose UniCredit correctly in native OC3 checkout and prepare confirm handoff, stopping before CP creation or scheme-selection UX.

**Expected files/components:** catalog payment model/controller/template/languages; checkout eligibility/cart-context adapters; confirm preparation service; minimal checkout template (no financing form).

**Tasks:** implement `getMethod`; derive totals from native OC3 total extensions; reuse native `session.order_id`; validate cart/order parity; operation-lock idempotent confirm preparation; redirect to prepared continuation route (not `checkout/success`); leave native order status at 0; no remote side effect.

**Dependencies:** Phases 3–4.

**Tests:** `tests/phase5_check.php` — eligibility combinations, store scope, order-total authority, no second `addOrder()`, lock/idempotency, OC3 lifecycle characterization.

**Runtime/manual verification:** native checkout on default theme through confirm preparation; inspect order/session/status; confirm no CP traffic.

**Acceptance criteria:** payment appears only when eligible and preparation binds exactly one native order with no CP/bank call.

**STOP GATE:** approve checkout sequencing and OC3 stale-order characterization.

### Phase 6 — Inbound authenticated API bridge

**Objective:** accept CP cache/status/debug callbacks securely on stable OC3 routes.

**Expected files/components:** API controller/actions, JSON responder, ownership/vocabulary validators, CP route documentation.

**Tasks:** POST-only dispatch; raw-body HMAC; nonce claim; shop cache replace; bank status upsert without OC status mutation; redacted debug retrieval; precise HTTP errors.

**Dependencies:** Phases 2–4 and final CP route coordination.

**Tests:** known HMAC vector, method/content errors, replay, timestamp edges, wrong store/UNICID/order/status, idempotent repeat and redaction.

**Runtime/manual verification:** signed requests generated by an offline helper and then CP test push; clock skew check; no SEO dependency.

**Acceptance criteria:** CP accepts final paths and all negative tests fail with the correct non-leaking response.

**STOP GATE:** security review and CP callback-path approval. **Local implementation: PASS** (`tests/phase6_check.php`); remote CP registration and signed callback evidence still required.

### Phase 7 — Local order materialization and CP order lifecycle

**Objective:** unify product/cart-created and checkout-reused orders through idempotent CP creation.

**Expected files/components:** order draft/adapters, materialization service, CP payload builder/lifecycle, recovery docs, diagnostic classes.

**Tasks:** Product/Cart `addOrder()` builders; immediate correlation; configured history; Checkout reuse; freeze CP payload; idempotent POST; 200/409/timeout handling; visible degraded checkout order.

**Dependencies:** Phases 2, 4–5.

**Tests:** golden payload, field limits, duplicate click, crash windows, checkout never adds, CP same-hash replay/conflict, unknown outcome.

**Runtime/manual verification:** create test orders from controlled harness/native checkout; interrupt between boundaries; compare OC/CP records and retry.

**Acceptance criteria:** one customer intent creates at most one local order and one semantic CP order; every interruption has a documented recovery outcome.

**STOP GATE:** inspect real sanitized local/CP order pairs before Process 1/2. **Local implementation: PASS** (`tests/phase7_check.php`); remote happy-path / duplicate-click evidence still required.

### Phase 8 — Product and cart storefront flows plus OCMOD/Journal

**Status:** local implementation done (`php tests/phase8_check.php` PASS). STOP GATE (screenshots / compiled OCMOD diff) pending developer approval.

**Objective:** deliver product/cart calculators and submission using shared lifecycle with broad theme compatibility.

**Expected files/components:** module controller/model/views/assets; product/cart services; Product Buy preference; small deterministic `install.xml` operations for required controller/template injections.

**Tasks:** authoritative product options/quantity; live cart totals/intersection; accessible modal; CSRF/token; AJAX reinit; cart unchanged; Product Buy handoff; exact JET-style default-path inline asset fallback; OCMOD-only injection strategy with delegated business logic and match diagnostics; no parallel event implementation without a concrete characterized need.

**Dependencies:** Phases 3 and 7; OCMOD characterization.

**Tests:** product/cart domain and DOM contracts, OCMOD match counts, option ownership, cart mutation, double click, signed preference, missing asset guard.

**Runtime/manual verification:** default theme and Journal 3 product/cart/checkout handoff, with caches on/off; coexistence with JET if installed.

**Acceptance criteria:** all eligible entry points reach the same local/CP lifecycle; no duplicate assets/handlers/orders; Journal works without a Journal code dependency.

**STOP GATE:** developer approves screenshots/behaviour and compiled OCMOD diff.

### Phase 9 — Process 1 and certificate/SmartUCF lifecycle

**Objective:** perform secure, recoverable direct SmartUCF session creation and bank redirect.

**Expected files/components:** certificate metadata/bundle client, local validator/store/lease, SmartUCF client/payload, lifecycle state coordinator, operator runbook.

**Tasks:** destination allowlist; TLS/mTLS; CP certificate revision/hash sync; reuse the established cross-platform UniCredit secret/certificate/passphrase deployment convention where OC3 permits; atomic staging/permissions; pre-send vs ambiguous vs terminal classification; trusted redirect storage; correct status patch timing.

**Dependencies:** Phases 4 and 7; server crypto/filesystem checks.

**Tests:** certificate fixtures/mismatch/expiry/encrypted key, endpoint attacks, timeout/invalid response/definitive reject, concurrent replay, temp cleanup.

**Runtime/manual verification:** test SmartUCF success and controlled failures; file ownership/modes; CP/local status; repeat does not resend.

**Acceptance criteria:** success redirects only to trusted stored URL; ambiguous outcome never auto-resends or records terminal failure.

**STOP GATE:** security/operations approval with sanitized bank test evidence.

### Phase 10 — Process 2, mail and privacy retention

**Objective:** implement encrypted Process 2 handoff and audience-safe notification lifecycle.

**Expected files/components:** field validator, sensitive cipher envelope, Process 2 coordinator, OC3 Mail adapter/templates, retention service.

**Tasks:** validate EGN/phone2; fail closed without key; never send PII to CP; status idempotency; mail marker/retry; customer/admin field separation; 180/183-day cleanup.

**Dependencies:** Phases 2 and 7; encryption/mail decisions.

**Tests:** validation, ciphertext only, key failure/rotation version, duplicate retry, mail audience snapshots, retention boundaries.

**Runtime/manual verification:** configured OC3 mail engines, inspect customer/admin messages and DB ciphertext/cleanup using synthetic PII.

**Acceptance criteria:** no plaintext EGN/phone2 outside authorized transient memory/admin message and no duplicate handoff/mail.

**STOP GATE:** privacy/security and business-message approval.

### Phase 11 — Result, email/admin presentation and homepage advertising

**Objective:** complete customer/admin visibility without changing native bank/order semantics.

**Expected files/components:** presentation repository/presenter, Thank You hook/view, order mail enrichment, admin order list/detail enrichment, homepage advertising.

**Tasks:** session-only success identity; exact store/customer checks; snapshot retention; audience filtering; bank label display; small deterministic OCMOD injections for Thank You, admin and mail presentation where required; CP-controlled homepage gate; all injected blocks delegate to module-owned presenters.

**Dependencies:** Phases 7, 9–10.

**Tests:** cross-store/cross-customer denial, GET order ignored, customer/admin field sets, missing status/snapshot, Twig escaping, OCMOD optional failure.

**Runtime/manual verification:** guest/logged success, direct/replayed URL, order emails, admin list/detail, homepage in default/Journal.

**Acceptance criteria:** correct financing information is visible to each audience with no PII/store leakage and native OC order status remains independent.

**STOP GATE:** UX/privacy approval across all presentation channels.

### Phase 12 — Compatibility, release hardening and independent audit readiness

**Objective:** validate the complete distributable extension and produce evidence for final Codex audit.

**Expected files/components:** compatibility report, deployment/recovery/security docs, final package/checksum, full regression suite; fixes only through separately reviewed tasks.

**Tasks:** PHP matrix derived from OpenCart 3.0.3.6/3.0.3.8/3.0.3.9; DB/theme matrix; Journal and named checkout; OCMOD collision/refresh/rollback across all three primary targets; security abuse/failure testing; retention and multistore; package allowlist/licenses; operator recovery drills.

**Dependencies:** all phases.

**Tests:** full automated suite plus manual matrix and audit checklist below.

**Runtime/manual verification:** clean install, upgrade simulation, rollback, end-to-end Process 1/2, callbacks, failure injection, logs and data audit.

**Acceptance criteria:** no unresolved critical/high issue, all deviations documented/approved, deterministic package reproducible from clean baseline.

**STOP GATE:** freeze implementation and hand to independent Codex full audit; do not release until audit remediation is separately approved and verified.

## 17. Decision log

| ID  | Question                                        | Why it matters                                               | Options                                                            | Recommendation / gate                                                                                                                                                      |
| --- | ----------------------------------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Exact PHP floor                                 | Controls syntax, crypto and test matrix                      | derive from OC3 3.0.3.6/3.0.3.8/3.0.3.9; then validate crypto      | Do not preselect PHP 7.1. Phase 0 records the widest safe floor practical for all three primary OC3 targets and required security functions                                |
| D2  | Module identity/version                         | CP payload and marketplace upgrades depend on stable values  | Keep `mt_uni_credit`/2.0.2 parity; assign OC3-specific version     | Keep code `mt_uni_credit`; confirm release version with product owner before Phase 1                                                                                       |
| D3  | Secret/certificate deployment convention        | Operational consistency and secure key handling              | established UniCredit layout; OC3-specific adaptation if required  | Follow the completed Woo/PS8/PS9/OC4 UniCredit convention wherever technically possible; document only evidence-based OC3/filesystem differences, never plaintext fallback |
| D4  | CP/SmartUCF environments and OC3 callback paths | Wrong endpoint risks production actions and broken callbacks | frozen production/test allowlist and coordinated OC3 routes        | Freeze allowlists and have CP owner register exact OC3 paths before Phase 4/6                                                                                              |
| D5  | Formal OC3 release targets                      | Core/template anchors and runtime compatibility differ       | broad untested 3.x; explicit primary matrix                        | Approved primary matrix: OpenCart 3.0.3.6, 3.0.3.8 and 3.0.3.9; retain general “OpenCart 3.x” product description only where appropriate                                   |
| D6  | Modification mechanism for existing OC3 flows   | Determines consistency and maintainability                   | OCMOD; events; parallel implementations                            | Approved: prefer small deterministic OCMOD injections; use native routes where no modification is needed; no parallel event path absent a concrete platform need           |
| D7  | Third-party checkout target                     | Cannot safely support unnamed DOM/sequencing                 | native only; name one checkout; adapter framework                  | Guarantee native contract; select a concrete checkout only from runtime inventory, then build a small evidence-based adapter if needed                                     |
| D8  | Checkout CP failure order status                | Draft order may be invisible or misleading                   | leave status 0; neutral Pending; UniCredit awaiting                | Recommend configured neutral/awaiting visible status after CP failure, but verify OC3 admin/retry semantics in Phase 5                                                     |
| D9  | Data on uninstall                               | Financial/audit data must not be lost casually               | drop all; preserve tables; explicit purge                          | Preserve lifecycle tables by default; remove module settings/OCMOD registration; separate explicit destructive purge only if later required                                |
| D10 | Authenticated encryption on minimum PHP         | Availability varies with OpenSSL/PHP                         | AES-GCM; AES-CBC+HMAC; raise floor                                 | Prefer AES-256-GCM after runtime matrix; otherwise audited encrypt-then-MAC and versioned envelope, never plaintext                                                        |
| D11 | Stale shop-cache policy                         | Availability vs incorrect financing offers                   | fail closed immediately; short grace for display; grace for submit | Allow approved short grace for read-only display only; require fresh snapshot for side-effecting submission unless business owner documents otherwise                      |
| D12 | Homepage advertising placement/config           | OC4 has the feature but OC3 theme layout varies              | layout module; small OCMOD placement                               | Prefer optional module/layout placement; use a small OCMOD injection only where required and validate UX in Phase 11                                                       |

Straightforward implementation details are intentionally absent from this log. A decision is closed only when its outcome, owner, date and affected contract/phases are recorded.

## 18. Final audit checklist

### Scope and packaging

- [ ] Only `uni-oc3` changed; reference repositories untouched.
- [ ] Package contains only `install.xml`, intended `upload/` files and licensed assets.
- [ ] Classic OC3 class/route/file names work on OpenCart 3.0.3.6, 3.0.3.8 and 3.0.3.9.
- [ ] Install/upgrade/disable/uninstall/rollback are documented and tested.
- [ ] Uninstall cannot silently destroy financing/order evidence.

### Functional parity

- [ ] Product, Product Buy, Cart, Checkout and homepage gates match approved OC4 contracts.
- [ ] Calculator/scheme golden fixtures pass for BGN/EUR and all boundaries.
- [ ] Product options, cart totals, shipping, tax, discounts and quantities are server-authoritative.
- [ ] Product/Cart create one order; Checkout reuses exactly one native order.
- [ ] CP payload fields, bounds, semantic hash and status timing match frozen contracts.
- [ ] Process 1 and Process 2 happy/degraded paths match approved lifecycle.
- [ ] Cart remains unchanged after financing submit.

### Security and privacy

- [ ] Admin mutations require user token and modify permission.
- [ ] Storefront submissions use CSRF/token, actor binding and current selection validation.
- [ ] Duplicate clicks/concurrency/crash recovery cannot repeat side effects.
- [ ] Inbound HMAC uses exact raw body, ±300s, 64-hex nonce, 900s replay store and `hash_equals`.
- [ ] Invalid HMAC does not consume nonce; valid replay is rejected.
- [ ] All order/cache/status/diagnostic access is exact-store scoped, including store 0.
- [ ] No GET `order_id` controls Thank You data; logged customer ownership is checked.
- [ ] CP secrets/tokens, keys/passphrases and EGN/phone2 are never logged or rendered to wrong audiences.
- [ ] Encryption fails closed; key permissions/rotation/recovery are documented.
- [ ] SmartUCF TLS verification, destination allowlist, certificate validation and temp cleanup pass.
- [ ] Retention windows and bounded pruning pass at boundaries.

### Robustness and lifecycle

- [ ] Every remote failure is classified as pre-send, definitive or ambiguous.
- [ ] Ambiguous CP/SmartUCF outcomes do not trigger unsafe automatic fresh submissions.
- [ ] Frozen CP payload and stored trusted redirect are replayed stably.
- [ ] CP 401 retries exactly once; 409 mismatch is not treated as success.
- [ ] Process 2 handoff and mail each have independent idempotency.
- [ ] Native OC order status and external bank status remain distinct.
- [ ] Public errors are localized/non-sensitive; diagnostic logs are redacted/correlated.

### Compatibility

- [ ] Approved minimum/maximum PHP syntax/runtime gates pass.
- [ ] MySQL and MariaDB install/query/concurrency tests pass with `DB_PREFIX` and strict mode.
- [ ] Default theme product/cart/checkout/success passes desktop/mobile/AJAX.
- [ ] Journal 3 loads each asset once using the proven fragment-safe fallback and has no hard dependency.
- [ ] Named third-party checkout results/limitations are documented.
- [ ] OCMOD XML parses; anchors/match counts/compiled files are checked on OpenCart 3.0.3.6, 3.0.3.8 and 3.0.3.9.
- [ ] Missing optional OCMOD placement degrades safely and is visible in health diagnostics.
- [ ] Coexistence with other payment/total modules and JET uses no shared identifiers/settings/assets.

### Operations and evidence

- [ ] Runtime inventory is current and contains no disclosed secret values.
- [ ] CP/SmartUCF/callback URLs and environment are independently confirmed.
- [ ] Clock, CA bundle, cURL/OpenSSL, filesystem permissions and mail engine checks pass.
- [ ] Clean install and deterministic package checksum are recorded.
- [ ] End-to-end Process 1/2 and inbound callback evidence is sanitized and attached to the phase review.
- [ ] Recovery drills cover order creation, CP create, SmartUCF unknown outcome and Process 2 mail failure.
- [ ] All decision-log items are closed or explicitly accepted as release limitations.
- [ ] Independent Codex audit findings are fixed, regression-tested and re-audited before release.
