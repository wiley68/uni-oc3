# UNI-CONSENT-001 — OpenCart 3 consent authority

Production remains PHP 7.3+ / OpenCart 3.x. Module version is unchanged. No DB schema or external payload changes are required.

## Canonical policy

`StorefrontConsentResolver` accepts a list or JSON list of consent descriptors. IDs must be canonical positive integers; duplicates and malformed containers/rows fail closed. Missing/invalid/non-string `interest_scope` becomes `all`. Supported scopes are `all`, `zero_interest`, `nonzero_interest`; mandatory is independent of scope.

Classification uses the selected server-resolved scheme's raw coefficient: finite numeric `coeff > 0` and finite numeric `interestPercent`. `abs(interestPercent) <= 0.00001` is zero; other finite interest is nonzero; missing/invalid coefficient or interest is unknown. Any scoped descriptor, including informational ones, blocks fresh acceptance on unknown. Empty/all-only lists add no unknown-interest consent gate. Financial formulas and scheme priority are unchanged.

Missing optional consents and actual empty lists require no synthetic checkbox. Legacy `consent=1` is never inferred from missing fields, HTML or POST. The compatibility utility has no canonical call site; enabling a legacy configuration requires an explicitly trusted discriminator, not currently present in the configuration contract.

## New attempts and evidence

`ApplicationSnapshot::fromLive`, v1 encoding and v1 hashing remain unchanged. `withConsent` builds a new v2 snapshot only for a new attempt. `consent_evidence` contains:

- evidence/policy versions;
- canonical descriptors (ID/name/URL/mandatory/scope), configuration SHA-256;
- effective KOP/months, raw finite coefficient/interest or null, classification;
- sorted unique applicable/mandatory/accepted IDs;
- store/order/entry-point/operation/selection binding;
- UTC server acceptance timestamp.

Accepted IDs are restricted to applicable checkbox consents; informational descriptors are never recorded as explicitly accepted. The full application snapshot SHA-256 covers evidence. It is an integrity checksum, not a customer signature.

`FinancingAttemptRepository` INSERTs a new attempt with complete v2 JSON/hash in one statement, under the existing identity/unique/lock rules. Evidence-free new attempts are refused. A concurrent winner is read back, integrity-checked and retained. `bindToAttempt` separately compares financial intent, so fresh timestamps do not create drift or overwrite evidence. Historical v1 attempts are never backfilled by submission/recovery.

## Continuation and failure behavior

Persisted selection A overrides posted B. Present but invalid persisted selection fails without fallback. Valid frozen v2 evidence governs the same attempt even after CP consent configuration changes; re-consent is out of scope.

Legacy v1 continuation uses persisted KOP/months and matching strict current coefficient. If acceptance is required, validate the current request and retain only a private request-local authorization bound to the attempt hash/configuration. This is not historical evidence and is never written back. Scoped unknown fails safely. Direct legacy recovery without suitable current-request authority fails closed.

`ConsentGate` guards popup/checkout services, pre-prepare checkout, CP lifecycle including CP_CREATED continuation, P1/P2 coordinators and direct missing-target recovery. Fresh popup rejection occurs before the durable pre-order claim/addOrder. Post-order rejection retains order/attempt/session identity and never calls banking `persistFailure` merely for consent refusal. Existing CP ambiguity, P1/P2 CAS, order claims and external idempotency remain unchanged.

Prepared checkout uses its bound selection. Legacy forms collect their own current acceptance; valid frozen v2 continuation does not demand re-consent. Popup acceptance is never copied into checkout. Missing mandatory IDs/changed rendered configuration require refresh; unknown legacy applicability may require support for the existing order. No automatic resubmit, replacement order or token reset is performed.

## Frontend

Both existing JS flows consume server `consent_context`; it is presentation transport only. Every real descriptor remains in markup. Hidden scoped checkboxes are cleared/disabled. Scope acceptance resets on actual scheme/KOP/month changes; all-scope acceptance may remain within the form. First-installment-only changes preserve acceptance after successful current recalculation.

Pending/error invalidates immediately, including debounce time. Popup uses real jqXHR abort plus latest-generation/mounted-root checks. Immediate recalculation/Apply cancel obsolete timers; Apply cannot use pending context. Checkout cancels first-installment timers on scheme change, discards prior successful calculation on errors, guards direct confirmation and invalidates on relevant checkout updates. Removed roots invalidate callbacks and detach module-owned listeners. Default-theme inline/versioned asset paths and Journal integration patterns remain intact.

## Offline verification

Use reviewed isolated tests with temporary storage inside this repository, for example `TMPDIR="$PWD/.tmp-authority" php tests/phase_uni_consent_check.php`. Ensure `.tmp-authority` already exists as a trusted directory.

- `phase_uni_consent_check.php`: policy, all three service boundaries, v1 oracle, v2 tampering, CP_CREATED/P1/P2/recovery guards, atomic INSERT/race, crash/order reuse and external payload equality.
- `support/consent_frontend_check.js`: dependency-free execution of actual production functions with isolated DOM/timer/request ports; H1/stale/remount/readiness/duplicate-submit behavior.
- `phase_aud021_f01_prepared_selection_check.php`: production prepared controller with isolated registry/model ports. The test registry bridge is explicitly a fake, not live core verification.
- Existing calculator, canonical lifecycle, popup, v1 hash, immutable snapshot and missing-order regressions remain applicable. Legacy fixture creation uses an explicitly memory-only fixture helper, never the new production INSERT API.
- `phase_aud031_packaging_check.php` builds disposable ZIPs only and checks complete manifest/bytes. `consent_gate.php` is a required release entry.

Actual default/Journal browser rendering, third-party checkout sequencing, PHP 7.3 runtime and real DB race semantics still require a separately authorized runtime matrix. No automatic npm install, live store/DB/order/mail/bank operation is part of this verification.

## Local verification results

- Consent/backend: 147 PASS.
- Dependency-free production JS function harness: 42 PASS.
- Canonical offline runner: 14/14 suites PASS.
- Calculator golden/domain checks: 35 PASS.
- Product/Cart: 368 PASS. Checkout presentation: 57 PASS.
- Prepared controller/selection: 82 PASS.
- Legacy hash: 20 PASS; immutable application: 58 PASS; missing-order recovery: 35 PASS.
- Documentation: 47 PASS. Packaging/security: 74 PASS, exact 260-file payload with per-file SHA-256.
- Changed/new PHP syntax and JS syntax: PASS; Git whitespace check: PASS.

No live tests, dependency installation, DB connections, real orders, mail, banking requests, commits or pushes were performed. Actual PHP 7.3 execution, full browser DOM/Journal behavior and real database concurrency remain runtime acceptance work.

## Change inventory

Created:

- `docs/UNI_CONSENT_001.md`
- `tests/fixtures/consent_v1_snapshot.json`
- `tests/phase_uni_consent_check.php`
- `tests/support/consent_frontend_check.js`
- `tests/support/legacy_attempt_fixture.php`
- `tests/support/oc3_registry_controller.php`
- `upload/system/library/mt_uni_credit/consent_gate.php`

Modified:

- `docs/CONTRACTS.md`
- `docs/RUNTIME_VERIFICATION.md`
- `scripts/package_policy.php`
- `tests/phase_aud007_f02_immutable_application_snapshot_check.php`
- `tests/phase_aud007_f03_legacy_missing_order_recovery_check.php`
- `tests/phase_aud010_f03_r1_legacy_snapshot_hash_check.php`
- `tests/phase_aud021_f01_prepared_selection_check.php`
- `tests/phase_canonical_order_id_string_check.php`
- `tests/support/phase2_memory_db.php`
- `tests/support/phase7_harness.php`
- `tests/support/phase8_body_4.php`
- `tests/support/phase8_body_5.php`
- `tests/support/phase8_body_6.php`
- `tests/support/phase9_harness.php`
- `upload/catalog/controller/extension/mt_uni_credit/cart.php`
- `upload/catalog/controller/extension/mt_uni_credit/product.php`
- `upload/catalog/controller/extension/payment/mt_uni_credit.php`
- `upload/catalog/language/bg-bg/extension/mt_uni_credit/cart.php`
- `upload/catalog/language/bg-bg/extension/mt_uni_credit/product.php`
- `upload/catalog/language/bg-bg/extension/payment/mt_uni_credit.php`
- `upload/catalog/language/en-gb/extension/mt_uni_credit/cart.php`
- `upload/catalog/language/en-gb/extension/mt_uni_credit/product.php`
- `upload/catalog/language/en-gb/extension/payment/mt_uni_credit.php`
- `upload/catalog/model/extension/payment/mt_uni_credit.php`
- `upload/catalog/view/theme/default/template/extension/mt_uni_credit/modal.twig`
- `upload/catalog/view/theme/default/template/extension/mt_uni_credit/storefront.js`
- `upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit.twig`
- `upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_checkout.js`
- `upload/catalog/view/theme/default/template/extension/payment/mt_uni_credit_prepared.twig`
- `upload/system/library/mt_uni_credit/application_snapshot.php`
- `upload/system/library/mt_uni_credit/bootstrap.php`
- `upload/system/library/mt_uni_credit/checkout_financing_submission_service.php`
- `upload/system/library/mt_uni_credit/control_panel_order_lifecycle_service.php`
- `upload/system/library/mt_uni_credit/financing_attempt_repository.php`
- `upload/system/library/mt_uni_credit/process_two_lifecycle_coordinator.php`
- `upload/system/library/mt_uni_credit/process_two_lifecycle_repository.php`
- `upload/system/library/mt_uni_credit/shop_configuration_snapshot_validator.php`
- `upload/system/library/mt_uni_credit/smart_ucf_session_coordinator.php`
- `upload/system/library/mt_uni_credit/storefront_calculator_presenter.php`
- `upload/system/library/mt_uni_credit/storefront_consent_resolver.php`
- `upload/system/library/mt_uni_credit/storefront_financing_submission_service.php`
- `upload/system/library/mt_uni_credit/storefront_modal_presenter.php`
- `upload/system/library/mt_uni_credit/storefront_validation_copy.php`

Deleted: none.
